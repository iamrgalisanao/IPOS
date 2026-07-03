<?php

namespace App\Services\POS\OfflineSync;

use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\OfflineTerminalJournal;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalesMachineProfile;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Services\Accounting\AccountingOutboxService;
use App\Services\AuditLogger;
use App\Services\Inventory\FefoAllocationService;
use App\Services\InventoryService;
use App\Services\POS\InvoiceSequenceService;
use App\Services\POS\OfflineReadiness\OfflineSettingsValidator;
use App\Services\POS\OfflineSync\OfflineImportRecalculationService;
use App\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OfflineReconciliationService (Story 28.7 — Validation Layer)
 *
 * Implements the validation-only intake pipeline for offline sync batches.
 *
 * ## Boundary guarantees (enforced by this story)
 *  - No Sale record is created.
 *  - reconciled_sale_id and reconciled_at remain null on all imports.
 *  - Grand cumulative total (GCT) is never touched.
 *  - OfflineTerminalJournal aggregation is not performed here.
 *
 * ## Methods still stubbed (Story 28.8+)
 *  - reconcileImport()
 *  - finalizeJournal()
 *
 * @see Story 28.7 Scope Lock
 */
class OfflineReconciliationService
{
    public function __construct(
        protected OfflineSettingsValidator $settingsValidator,
        protected TenantContext $tenantContext,
        protected OfflineImportRecalculationService $recalculationService,
        protected FefoAllocationService $fefoAllocationService,
        protected InventoryService $inventoryService,
        protected AccountingOutboxService $outboxService,
        protected AuditLogger $auditLogger,
        protected InvoiceSequenceService $invoiceSequenceService
    ) {}

    // =========================================================================
    // PUBLIC CONTRACT
    // =========================================================================

    /**
     * Receive a raw sync batch payload from a terminal.
     *
     * Enforces:
     *  - terminal offline enablement (Tenant → Branch → Terminal → Prefix → Status)
     *  - batch reference idempotency (returns existing batch on replay)
     *  - per-import validation and deduplication
     *
     * Returns the created (or existing, on replay) OfflineSyncBatch.
     *
     * @param SalesMachineProfile $profile
     * @param array               $batchPayload  Validated payload from SyncBatchRequest
     * @return OfflineSyncBatch
     *
     * @throws \RuntimeException  If offline sales are not allowed for this terminal.
     */
    public function receiveImportBatch(SalesMachineProfile $profile, array $batchPayload): OfflineSyncBatch
    {
        // 1. Terminal enablement check (cascading Tenant → Branch → Terminal → Prefix → Status)
        $tenant = $profile->tenant()->withoutGlobalScopes()->first();
        $branch = $profile->branch()->withoutGlobalScopes()->first();

        $validation = $this->settingsValidator->validate($tenant, $branch, $profile);

        if (!$validation['allowed']) {
            throw new \RuntimeException('OFFLINE_NOT_ENABLED: ' . $validation['reason']);
        }

        $batchReference = $batchPayload['batch_reference'];
        $rawImports     = $batchPayload['imports'] ?? [];

        // 2. Idempotency — return existing batch if already processed
        $existing = OfflineSyncBatch::withoutGlobalScopes()
            ->where('tenant_id', $profile->tenant_id)
            ->where('sales_machine_profile_id', $profile->id)
            ->where('batch_reference', $batchReference)
            ->first();

        if ($existing !== null) {
            $existing->setAttribute('_replayed', true);
            return $existing;
        }

        // 3. Create batch record
        $batch = OfflineSyncBatch::create([
            'tenant_id'               => $profile->tenant_id,
            'branch_id'               => $profile->branch_id,
            'sales_machine_profile_id' => $profile->id,
            'batch_reference'         => $batchReference,
            'status'                  => OfflineSyncBatch::STATUS_PROCESSING,
            'submitted_import_count'  => count($rawImports),
            'sync_started_at'         => now(),
        ]);

        $processedCount = 0;
        $failedCount    = 0;

        // 4. Process each import
        foreach ($rawImports as $rawImport) {
            $import = OfflineSalesImport::create([
                'tenant_id'                => $profile->tenant_id,
                'branch_id'                => $profile->branch_id,
                'sales_machine_profile_id' => $profile->id,
                'batch_id'                 => $batch->id,
                'offline_sequence_number'  => $rawImport['offline_sequence_number'] ?? '',
                'payload_hash'             => '',   // set by deduplicateImport
                'raw_payload'              => $rawImport,
                'status'                   => OfflineSalesImport::STATUS_PENDING,
                'submitted_at'             => $rawImport['submitted_at'] ?? now(),
            ]);

            // Validate envelope structure and prefix ownership
            $isValid = $this->validateImport($import, $profile);

            if (!$isValid) {
                $failedCount++;
                continue;
            }

            // Deduplicate against previous submissions
            $isDuplicate = $this->deduplicateImport($import);

            if ($isDuplicate) {
                $processedCount++;
                continue;
            }

            // Perform server-side recalculation and total validation
            $recalcResult = $this->recalculationService->recalculate($import);

            if ($recalcResult['status'] === OfflineSalesImport::STATUS_REJECTED) {
                $failedCount++;
                continue;
            }

            // Classify late sync (informational only, does not reject)
            $this->classifyLateSyncIfNeeded($import);

            $processedCount++;
        }

        // Check for GCT discrepancy if reported by the terminal
        if (isset($batchPayload['reported_gct'])) {
            app(\App\Services\POS\Reconciliation\LateSyncReconciliationService::class)
                ->checkAndLogGctDiscrepancy($profile, (float) $batchPayload['reported_gct'], 'sync');
        }

        // 5. Finalise batch
        $batch->update([
            'status'            => OfflineSyncBatch::STATUS_COMPLETED,
            'processed_count'   => $processedCount,
            'failed_count'      => $failedCount,
            'sync_completed_at' => now(),
        ]);

        return $batch->fresh();
    }

    /**
     * Validate an offline import's envelope structure and prefix ownership.
     *
     * On failure: marks import status=rejected, sets rejection_reason, returns false.
     * On success: leaves status=pending, returns true.
     *
     * Does NOT perform tax calculation, total computation, or sale creation.
     *
     * @param OfflineSalesImport  $import
     * @param SalesMachineProfile $profile  Needed for prefix ownership check.
     * @return bool
     */
    public function validateImport(OfflineSalesImport $import, ?SalesMachineProfile $profile = null): bool
    {
        $payload = $import->raw_payload;

        // 1. Required envelope fields
        if (empty($payload['offline_sequence_number'])) {
            return $this->rejectImport($import, 'missing_offline_sequence_number');
        }
        if (empty($payload['submitted_at'])) {
            return $this->rejectImport($import, 'missing_submitted_at');
        }
        if (empty($payload['items']) || !is_array($payload['items'])) {
            return $this->rejectImport($import, 'missing_or_empty_items');
        }

        // 2. Per-item structure
        foreach ($payload['items'] as $index => $item) {
            if (empty($item['product_id'])) {
                return $this->rejectImport($import, "item_{$index}_missing_product_id");
            }
            if (!isset($item['quantity']) || (int) $item['quantity'] < 1) {
                return $this->rejectImport($import, "item_{$index}_invalid_quantity");
            }
            if (!isset($item['unit_price']) || (float) $item['unit_price'] <= 0) {
                return $this->rejectImport($import, "item_{$index}_invalid_unit_price");
            }
        }

        // 3. Prefix ownership — the sequence number must start with the terminal's registered prefix
        if ($profile !== null && !empty($profile->offline_sequence_prefix)) {
            $sequenceNumber = $payload['offline_sequence_number'];
            if (!str_starts_with($sequenceNumber, $profile->offline_sequence_prefix)) {
                return $this->rejectImport(
                    $import,
                    'prefix_mismatch: expected prefix ' . $profile->offline_sequence_prefix
                );
            }

            // 4. Suffix must be a positive integer (e.g., INV-T01-0042 → "0042")
            $suffix = substr($sequenceNumber, strlen($profile->offline_sequence_prefix));
            if (!ctype_digit($suffix) || (int) $suffix < 1) {
                return $this->rejectImport($import, 'invalid_sequence_number_suffix');
            }
        }

        return true;
    }

    /**
     * Check if an import is a duplicate of an existing submitted payload.
     *
     * Hash is computed as SHA-256 of canonical JSON (sorted keys, no whitespace).
     * Duplicates are saved with status=duplicate — the original row is NOT modified.
     *
     * Returns true if duplicate, false if unique.
     *
     * @param OfflineSalesImport $import
     * @return bool
     */
    public function deduplicateImport(OfflineSalesImport $import): bool
    {
        $canonical = $this->canonicalJson($import->raw_payload);
        $hash      = hash('sha256', $canonical);

        // Persist hash on this import first
        $import->update(['payload_hash' => $hash]);

        // Look for an earlier import with the same hash that is not itself and not already a duplicate/rejected
        $existing = OfflineSalesImport::withoutGlobalScopes()
            ->where('tenant_id', $import->tenant_id)
            ->where('sales_machine_profile_id', $import->sales_machine_profile_id)
            ->where('payload_hash', $hash)
            ->where('id', '!=', $import->id)
            ->whereNotIn('status', [OfflineSalesImport::STATUS_REJECTED])
            ->first();

        if ($existing !== null) {
            $import->update(['status' => OfflineSalesImport::STATUS_DUPLICATE]);
            return true;
        }

        return false;
    }

    /**
     * Reconcile a validated import into an official Sale record.
     *
     * @throws \RuntimeException If not eligible for posting.
     */
    public function reconcileImport(OfflineSalesImport $import): ?Sale
    {
        if (!$import->exists || !$import->id) {
            throw new \RuntimeException('Cannot reconcile an unsaved import.');
        }

        return DB::transaction(function () use ($import) {
            // Lock the import row
            $import = OfflineSalesImport::where('id', $import->id)->lockForUpdate()->first();

            if (!$import) {
                throw new \RuntimeException('Import record not found in database.');
            }

            // Idempotency check
            if ($import->status === OfflineSalesImport::STATUS_POSTED) {
                return $import->reconciledSale;
            }

            // Eligibility check
            if (!in_array($import->status, [
                OfflineSalesImport::STATUS_SERVER_VERIFIED,
                OfflineSalesImport::STATUS_OVERRIDE_APPROVED,
            ], true)) {
                $this->auditLogger->log('offline_import_posting_rejected', $import, null, null, 'Not eligible for posting');
                throw new \RuntimeException('Import is not eligible for posting. Status: ' . $import->status);
            }

            $machineProfile = $import->salesMachineProfile;
            $recalc = $import->server_recalculation;
            $rawPayload = $import->raw_payload;

            if (!is_array($recalc) || !isset($recalc['items'], $recalc['server_total'], $recalc['server_subtotal'], $recalc['server_tax_total'])) {
                throw new \RuntimeException('Missing or invalid server recalculation payload for posting.');
            }

            $saleTotal = $this->formatAmount($recalc['server_total']);
            $normalizedPayments = $this->validateAndNormalizePayments($rawPayload, $saleTotal);

            // Generate invoice number using the server's sequence generator
            $principalInvoiceNumber = $this->invoiceSequenceService->generateNextInvoiceNumber($machineProfile);

            $profileSnapshot = app(\App\Services\Tax\TaxSourceSnapshotService::class)->prepareSaleTaxProfileSnapshot($machineProfile, [
                'tax_source'             => Sale::TAX_SOURCE_SYSTEM,
                'tax_computation_source' => Sale::TAX_SOURCE_SYSTEM,
                'tax_source_version'     => 'BIR_VAT_2026_BASELINE',
            ]);
            $profileSnapshot['offline_reconciliation'] = [
                'source' => 'offline_reconciliation',
                'offline_sales_import_id' => $import->id,
                'offline_sequence_number' => $import->offline_sequence_number,
                'offline_submitted_at' => optional($import->submitted_at)->toIso8601String(),
                'offline_local_created_at' => $rawPayload['local_created_at'] ?? null,
                'offline_posted_at' => now()->toIso8601String(),
            ];

            // Recreate aggregate fields that SaleCreationService does
            // To simplify, we sum the tax buckets from items
            $grossSalesAmount = 0.0;
            $vatableSalesAmount = 0.0;
            $vatExemptSalesAmount = 0.0;
            $zeroRatedSalesAmount = 0.0;
            $nonVatSalesAmount = 0.0;
            $vatAmountTotal = 0.0;

            foreach ($recalc['items'] as $item) {
                $taxBucket = $item['tax_bucket'];
                $subtotal = (float) $item['subtotal'];
                $taxAmount = (float) $item['tax_amount'];
                
                $grossSalesAmount += $subtotal;
                $vatAmountTotal += $taxAmount;

                if ($taxBucket === SaleItem::TAX_BUCKET_VATABLE) {
                    $vatableSalesAmount += ($subtotal - $taxAmount);
                } elseif ($taxBucket === SaleItem::TAX_BUCKET_VAT_EXEMPT) {
                    $vatExemptSalesAmount += $subtotal;
                } elseif ($taxBucket === SaleItem::TAX_BUCKET_ZERO_RATED) {
                    $zeroRatedSalesAmount += $subtotal;
                } else {
                    $nonVatSalesAmount += $subtotal;
                }
            }

            // Check for late sync and shift reporting basis (Slice 33B)
            $lateSyncService = app(\App\Services\POS\Reconciliation\LateSyncReconciliationService::class);
            $isLateSync = $lateSyncService->isLateSync($import);
            $originalZReadId = null;
            $adjustedSettlementPeriodId = null;
            $reportingBasisAt = $rawPayload['submitted_at'] ?? now();

            if ($isLateSync) {
                $originalZRead = $lateSyncService->getOriginalZRead($import);
                if ($originalZRead) {
                    $originalZReadId = $originalZRead->id;
                }

                $openPeriod = $lateSyncService->getBranchActiveOpenSettlementPeriod($import->branch_id);
                if ($openPeriod) {
                    $reportingBasisAt = $openPeriod->period_start_at;
                    $adjustedSettlementPeriodId = $openPeriod->id;
                } else {
                    $reportingBasisAt = now();
                }
            }

            $sale = Sale::create([
                'id'                           => Str::uuid()->toString(),
                'tenant_id'                    => $import->tenant_id,
                'branch_id'                    => $import->branch_id,
                'user_id'                      => $rawPayload['user_id'] ?? null, // From original payload if available
                'client_request_uuid'          => $rawPayload['client_request_uuid'] ?? Str::uuid()->toString(),
                'checkout_request_id'          => null, // No online checkout request
                'sales_machine_profile_id'     => $machineProfile->id,
                'principal_invoice_number'     => $principalInvoiceNumber,
                'sale_number'                  => $principalInvoiceNumber,
                'principal_invoice_type'       => 'vat',
                'principal_invoice_label'      => 'Invoice',
                'status'                       => 'created', // Will be set to 'paid' if payments exist
                'subtotal'                     => $recalc['server_subtotal'],
                'tax_total'                    => $recalc['server_tax_total'],
                'discount_total'               => '0.0000',
                'total'                        => $recalc['server_total'],
                'gross_sales_amount'           => number_format($grossSalesAmount, 4, '.', ''),
                'vatable_sales_amount'         => number_format($vatableSalesAmount, 4, '.', ''),
                'vat_exempt_sales_amount'      => number_format($vatExemptSalesAmount, 4, '.', ''),
                'zero_rated_sales_amount'      => number_format($zeroRatedSalesAmount, 4, '.', ''),
                'non_vat_sales_amount'         => number_format($nonVatSalesAmount, 4, '.', ''),
                'vat_amount'                   => number_format($vatAmountTotal, 4, '.', ''),
                'statutory_discount_total'     => '0.0000',
                'commercial_discount_total'    => '0.0000',
                'other_adjustment_total'       => '0.0000',
                'contains_statutory_discount'  => false,
                'compliance_version'           => 'EPIC14_V1',
                'tax_source_version'           => 'BIR_VAT_2026_BASELINE',
                'tax_computation_source'       => 'system',
                'tax_profile_snapshot'         => $profileSnapshot,
                'invoice_issued_at'            => $rawPayload['submitted_at'] ?? now(),
                'reporting_basis_at'           => $reportingBasisAt,
                'confirmed_at'                 => now(),
                'is_training_mode'             => false,
                'source'                       => 'offline_reconciliation',
                'offline_sales_import_id'      => $import->id,
                'offline_sequence_number'      => $import->offline_sequence_number,
                'offline_submitted_at'         => $import->submitted_at,
                'offline_local_created_at'     => $rawPayload['local_created_at'] ?? null,
                'offline_posted_at'            => now(),
            ]);

            // Create PriorPeriodAdjustment if it is a late sync
            if ($isLateSync) {
                $lateSyncService->createPriorPeriodAdjustment(
                    $import,
                    $sale,
                    $originalZReadId,
                    $adjustedSettlementPeriodId,
                    $reportingBasisAt
                );
            }

            // Create items
            $saleItemsData = [];
            foreach ($recalc['items'] as $item) {
                $saleItemsData[] = [
                    'id'                   => Str::uuid()->toString(),
                    'tenant_id'            => $import->tenant_id,
                    'branch_id'            => $import->branch_id,
                    'sale_id'              => $sale->id,
                    'product_id'           => $item['product_id'],
                    'product_name'         => $item['product_name'],
                    'sku'                  => $item['sku'],
                    'barcode'              => $item['barcode'],
                    'unit_of_measure'      => $item['unit_of_measure'],
                    'quantity'             => $item['quantity'],
                    'unit_price'           => number_format($item['selling_price'], 4, '.', ''),
                    'subtotal'             => $item['subtotal'],
                    'discount_amount'      => '0.0000',
                    'tax_category_id'      => $item['tax_category_id'],
                    'tax_type'             => $item['tax_type'],
                    'tax_bucket'           => $item['tax_bucket'],
                    'tax_rate'             => number_format($item['tax_rate'], 4, '.', ''),
                    'tax_amount'           => $item['tax_amount'],
                    'net_amount'           => number_format($item['subtotal'] - $item['tax_amount'], 4, '.', ''),
                    'vatable_amount'       => $item['tax_bucket'] === SaleItem::TAX_BUCKET_VATABLE ? number_format($item['subtotal'] - $item['tax_amount'], 4, '.', '') : '0.0000',
                    'vat_exempt_amount'    => $item['tax_bucket'] === SaleItem::TAX_BUCKET_VAT_EXEMPT ? $item['subtotal'] : '0.0000',
                    'zero_rated_amount'    => $item['tax_bucket'] === SaleItem::TAX_BUCKET_ZERO_RATED ? $item['subtotal'] : '0.0000',
                    'non_vat_amount'       => $item['tax_bucket'] === SaleItem::TAX_BUCKET_NON_VAT ? $item['subtotal'] : '0.0000',
                    'tax_source'           => SaleItem::TAX_SOURCE_SYSTEM,
                    'tax_snapshot'         => is_array($item['tax_snapshot']) ? json_encode($item['tax_snapshot']) : $item['tax_snapshot'],
                    'line_total'           => $item['subtotal'],
                    'is_inventory_tracked' => $item['is_inventory_tracked'] ?? false,
                    'created_at'           => now(),
                ];
            }

            SaleItem::insert($saleItemsData);

            // Allocate inventory FEFO
            foreach ($recalc['items'] as $item) {
                if ($item['expiry_tracking_enabled'] ?? false) {
                    $this->fefoAllocationService->allocate(
                        $import->tenant_id,
                        $import->branch_id,
                        $item['product_id'],
                        $item['quantity']
                    );
                }
            }

            // Ensure sale items are loaded
            $sale->refresh();

            // Deduct inventory
            \App\Jobs\Inventory\ProcessSaleInventoryDeductionJob::dispatch($sale->id)->afterCommit();

            // Process fully-paid payloads only.
            $salePayments = collect();
            foreach ($normalizedPayments as $paymentData) {
                $paymentMethod = PaymentMethod::where('tenant_id', $sale->tenant_id)
                    ->where('id', $paymentData['payment_method_id'])
                    ->first();

                if (!$paymentMethod) {
                    throw new \RuntimeException('Invalid payment method in offline payload.');
                }

                $salePayments->push(SalePayment::create([
                    'tenant_id' => $sale->tenant_id,
                    'branch_id' => $sale->branch_id,
                    'sale_id' => $sale->id,
                    'shift_id' => null,
                    'payment_method_id' => $paymentMethod->id,
                    'payment_type' => $paymentMethod->type,
                    'amount' => $paymentData['amount'],
                    'reference_number' => $paymentData['reference_number'] ?? null,
                    'status' => 'recorded',
                    'paid_at' => $rawPayload['submitted_at'] ?? now(),
                    'created_by' => $rawPayload['user_id'] ?? null,
                ]));
            }

            $sale->update(['status' => 'paid']);

            // Accounting Outbox
            $this->outboxService->recordEvent('sale_paid', $sale, [
                'sale_id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'subtotal' => (string) $sale->subtotal,
                'tax_total' => (string) $sale->tax_total,
                'discount_total' => (string) $sale->discount_total,
                'total' => (string) $sale->total,
                'paid_at' => (string) now(),
                'items' => $sale->items->map(fn($item) => [
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'line_total' => (string) $item->line_total,
                ])->toArray(),
                'taxes' => $sale->items
                    ->filter(fn($item) => $item->tax_category_id && bccomp((string) $item->tax_amount, '0', 4) !== 0)
                    ->groupBy('tax_category_id')
                    ->map(fn($items, $taxCategoryId) => [
                        'tax_category_id' => $taxCategoryId,
                        'tax_rate' => (string) $items->first()->tax_rate,
                        'tax_amount' => (string) $items->reduce(
                            fn($carry, $item) => bcadd($carry, (string) $item->tax_amount, 4),
                            '0.0000'
                        ),
                    ])
                    ->values()
                    ->toArray(),
                'payments' => $salePayments->map(fn($p) => [
                    'id' => $p->id,
                    'method' => $p->payment_method_id,
                    'amount' => (string) $p->amount,
                    'reference' => $p->reference_number
                ])->toArray()
            ]);

            // Update Import Record
            $import->update([
                'status' => OfflineSalesImport::STATUS_POSTED,
                'reconciled_sale_id' => $sale->id,
                'reconciled_at' => now(),
            ]);

            $this->auditLogger->log('offline_import_posted', $import, null, ['status' => OfflineSalesImport::STATUS_POSTED], null, 'Successfully posted offline import', [
                'sale_id' => $sale->id,
                'server_total' => $sale->total
            ]);

            return $sale;
        });
    }

    /**
     * Finalize a provisional terminal journal after all imports are reconciled.
     *
     * NOT YET IMPLEMENTED — Story 28.8+.
     * Guard is intentional to prevent accidental partial execution.
     *
     * @throws \BadMethodCallException Always.
     */
    public function finalizeJournal(OfflineTerminalJournal $journal): void
    {
        throw new \BadMethodCallException('Not yet implemented.');
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Mark an import as rejected with a reason and persist immediately.
     * Returns false so callers can return the result inline.
     */
    private function rejectImport(OfflineSalesImport $import, string $reason): bool
    {
        $import->update([
            'status'           => OfflineSalesImport::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);
        return false;
    }

    /**
     * Flag an import as late sync if submitted_at exceeds the configured threshold.
     * This is informational only — the import status remains pending.
     */
    private function classifyLateSyncIfNeeded(OfflineSalesImport $import): void
    {
        $thresholdHours = (int) config('offline.late_sync_threshold_hours', 72);
        $submittedAt    = Carbon::parse($import->submitted_at);

        if ($submittedAt->diffInHours(now()) > $thresholdHours) {
            $import->update([
                'rejection_reason' => 'late_sync: submitted_at older than ' . $thresholdHours . ' hours',
            ]);
        }
    }

    /**
     * Produce a canonical JSON string for hashing (sorted keys, no whitespace).
     */
    private function canonicalJson(array $data): string
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = json_decode($this->canonicalJson($value), true);
            }
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Story 28.10: only fully paid payloads are accepted for posting.
     *
     * @return array<int,array{payment_method_id:string,amount:string,reference_number?:string|null}>
     */
    private function validateAndNormalizePayments(array $rawPayload, string $saleTotal): array
    {
        $payments = $rawPayload['payments'] ?? null;
        if (!is_array($payments) || $payments === []) {
            throw new \RuntimeException('Offline posting requires at least one payment entry.');
        }

        $normalizedPayments = [];
        $totalPaymentAmount = '0.0000';

        foreach ($payments as $index => $paymentData) {
            if (!is_array($paymentData)) {
                throw new \RuntimeException("Malformed payment entry at index {$index}.");
            }

            $paymentMethodId = $paymentData['payment_method_id'] ?? null;
            $amount = $paymentData['amount'] ?? null;

            if (!is_string($paymentMethodId) || trim($paymentMethodId) === '') {
                throw new \RuntimeException("Missing payment_method_id at index {$index}.");
            }

            if (!is_numeric($amount)) {
                throw new \RuntimeException("Invalid payment amount at index {$index}.");
            }

            $normalizedAmount = $this->formatAmount($amount);
            if (bccomp($normalizedAmount, '0.0000', 4) <= 0) {
                throw new \RuntimeException("Payment amount must be greater than zero at index {$index}.");
            }

            $totalPaymentAmount = bcadd($totalPaymentAmount, $normalizedAmount, 4);
            $normalizedPayments[] = [
                'payment_method_id' => $paymentMethodId,
                'amount' => $normalizedAmount,
                'reference_number' => $paymentData['reference_number'] ?? null,
            ];
        }

        if (bccomp($totalPaymentAmount, $saleTotal, 4) !== 0) {
            throw new \RuntimeException('Offline posting requires fully paid imports only.');
        }

        return $normalizedPayments;
    }

    private function formatAmount(mixed $amount): string
    {
        return number_format((float) $amount, 4, '.', '');
    }
}
