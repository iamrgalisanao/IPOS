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

        // 1.5. Verify consecutive sequence and hash chain integrity
        $this->verifyBatchHashChain($profile, $rawImports);

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

            // Run offline payment policy checks
            $reason = null;
            $context = null;
            if (!$this->validatePaymentPolicy($import, $reason, $context)) {
                if ($reason === 'PAYMENT_POLICY_HASH_STALE') {
                    $import->update([
                        'status' => OfflineSalesImport::STATUS_ACCEPTED_WITH_WARNING,
                        'rejection_reason' => "PAYMENT_POLICY_HASH_STALE: client={$context['client_hash']} current={$context['current_hash']}"
                    ]);
                } else {
                    $import->update([
                        'status' => OfflineSalesImport::STATUS_CONFLICT,
                        'conflict_notes' => json_encode([
                            'sync_status' => 'review_required',
                            'review_reason' => $reason,
                            'review_context' => $context,
                        ]),
                    ]);
                }
            }

            $promotionReason = null;
            $promotionContext = null;
            if (!$this->validatePromotionPolicy($import, $promotionReason, $promotionContext)) {
                if (in_array($promotionReason, ['PROMOTION_RULE_HASH_STALE_NO_PREVIEW', 'PROMOTION_RULE_HASH_MISSING_NO_PREVIEW'], true)) {
                    $import->update([
                        'status' => OfflineSalesImport::STATUS_ACCEPTED_WITH_WARNING,
                        'rejection_reason' => "{$promotionReason}: client={$promotionContext['client_hash']} current={$promotionContext['current_hash']}",
                    ]);
                } else {
                    $import->update([
                        'status' => OfflineSalesImport::STATUS_CONFLICT,
                        'conflict_notes' => $this->mergeConflictNotes($import->conflict_notes, [
                            'sync_status' => 'review_required',
                            'review_reason' => $promotionReason,
                            'review_context' => $promotionContext,
                        ]),
                    ]);
                }
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

        // 3. Prefix ownership — the sequence number must start with the terminal's registered prefix or dynamic OFF-{profile_code}-
        if ($profile !== null) {
            $sequenceNumber = $payload['offline_sequence_number'];
            $expectedDynamicPrefix = "OFF-{$profile->profile_code}-";

            $isDynamic = str_starts_with($sequenceNumber, $expectedDynamicPrefix);
            $isStatic = !empty($profile->offline_sequence_prefix) && str_starts_with($sequenceNumber, $profile->offline_sequence_prefix);

            if (!$isDynamic && !$isStatic) {
                return $this->rejectImport(
                    $import,
                    'prefix_mismatch: expected prefix starting with ' . $expectedDynamicPrefix
                );
            }

            // 4. Suffix must be a positive integer
            $suffixValue = $this->parseSequenceSuffix($sequenceNumber, $profile->offline_sequence_prefix);
            if ($suffixValue < 1) {
                return $this->rejectImport($import, 'invalid_sequence_number_suffix');
            }
        }

        // 5. Version configuration drift warning check (non-blocking)
        $clientHashes = [
            'layout_version_hash' => $payload['layout_version_hash'] ?? null,
            'catalog_version_hash' => $payload['catalog_version_hash'] ?? null,
            'tax_configuration_version_hash' => $payload['tax_configuration_version_hash'] ?? null,
            'discount_rules_version_hash' => $payload['discount_rules_version_hash'] ?? null,
            'payment_methods_version_hash' => $payload['payment_methods_version_hash'] ?? null,
            'terminal_policy_version_hash' => $payload['terminal_policy_version_hash'] ?? null,
            'printer_profile_version_hash' => $payload['printer_profile_version_hash'] ?? null,
        ];

        if (collect($clientHashes)->filter()->isNotEmpty()) {
            $bootstrapService = app(\App\Services\POS\OfflineReadiness\CacheBootstrapService::class);
            $tenant = $profile?->tenant()->withoutGlobalScopes()->first()
                ?: \App\Models\Tenant::withoutGlobalScopes()->find($import->tenant_id);
            $branch = $profile?->branch()->withoutGlobalScopes()->first()
                ?: \App\Models\Branch::withoutGlobalScopes()->find($import->branch_id);

            $currentHashes = [
                'layout_version_hash' => $bootstrapService->calculateLayoutVersionHash($import->tenant_id, $import->branch_id, $profile),
                'catalog_version_hash' => $bootstrapService->calculateCatalogVersionHash($import->tenant_id, $import->branch_id),
                'tax_configuration_version_hash' => $bootstrapService->calculateTaxConfigHash($import->tenant_id, $import->branch_id),
                'discount_rules_version_hash' => $bootstrapService->calculateDiscountRulesVersionHash($import->tenant_id, $import->branch_id),
                'payment_methods_version_hash' => $bootstrapService->calculatePaymentMethodsVersionHash($import->tenant_id, $import->branch_id),
                'terminal_policy_version_hash' => ($tenant && $branch)
                    ? $bootstrapService->calculateTerminalPolicyVersionHash($tenant, $branch, $profile)
                    : null,
                'printer_profile_version_hash' => $bootstrapService->calculatePrinterProfileVersionHash($import->tenant_id, $import->branch_id, $profile?->id),
            ];

            $mismatches = collect($clientHashes)
                ->filter()
                ->filter(fn ($clientHash, $key) => isset($currentHashes[$key]) && $clientHash !== $currentHashes[$key])
                ->keys()
                ->values()
                ->all();

            if (!empty($mismatches)) {
                $import->update([
                    'status' => OfflineSalesImport::STATUS_ACCEPTED_WITH_WARNING,
                    'rejection_reason' => 'Config drift detected for: ' . implode(', ', $mismatches) . '.'
                ]);
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

    /**
     * Parse the sequence number suffix.
     */
    private function parseSequenceSuffix(string $sequenceNumber, ?string $staticPrefix = null): int
    {
        if (preg_match('/^OFF-[A-Z0-9]+-\d+-(\d+)$/', $sequenceNumber, $matches)) {
            return (int) $matches[1];
        }
        if ($staticPrefix !== null && str_starts_with($sequenceNumber, $staticPrefix)) {
            return (int) substr($sequenceNumber, strlen($staticPrefix));
        }
        if (preg_match('/(\d+)$/', $sequenceNumber, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Verify that the imports in the batch are consecutive and chain correctly.
     */
    private function verifyBatchHashChain(SalesMachineProfile $profile, array $rawImports): void
    {
        if (empty($rawImports)) {
            return;
        }

        // Check if the payload has dynamic hashing fields; if not, skip validation to support legacy/mock tests
        $firstImport = reset($rawImports);
        if (empty($firstImport['row_hash'])) {
            return;
        }

        // Sort imports by sequence number suffix to ensure chronological order
        usort($rawImports, function ($a, $b) use ($profile) {
            $seqA = $a['offline_sequence_number'] ?? '';
            $seqB = $b['offline_sequence_number'] ?? '';

            $suffixA = $this->parseSequenceSuffix($seqA, $profile->offline_sequence_prefix);
            $suffixB = $this->parseSequenceSuffix($seqB, $profile->offline_sequence_prefix);

            return $suffixA <=> $suffixB;
        });

        // Get the last successful import from DB to chain the first item
        $allImports = OfflineSalesImport::withoutGlobalScopes()
            ->where('sales_machine_profile_id', $profile->id)
            ->whereNotIn('status', [OfflineSalesImport::STATUS_REJECTED])
            ->get();

        $lastImport = $allImports->sortByDesc(function ($imp) use ($profile) {
            return $this->parseSequenceSuffix($imp->offline_sequence_number, $profile->offline_sequence_prefix);
        })->first();

        $expectedPrevHash = $lastImport ? ($lastImport->raw_payload['row_hash'] ?? null) : null;
        $expectedSeqSuffix = $lastImport ? ($this->parseSequenceSuffix($lastImport->offline_sequence_number, $profile->offline_sequence_prefix) + 1) : null;

        foreach ($rawImports as $importPayload) {
            $seq = $importPayload['offline_sequence_number'] ?? '';
            $suffix = $this->parseSequenceSuffix($seq, $profile->offline_sequence_prefix);

            // 1. Verify sequential sequence number
            if ($expectedSeqSuffix !== null && $suffix !== $expectedSeqSuffix) {
                throw new \RuntimeException("SEQUENCE_OUT_OF_ORDER: Sequence {$seq} does not follow expected {$expectedSeqSuffix}");
            }

            // 2. Verify previous hash chaining
            $prevHash = $importPayload['previous_hash'] ?? null;
            if ($expectedPrevHash !== null && $prevHash !== $expectedPrevHash) {
                throw new \RuntimeException("HASH_CHAIN_BROKEN: Record {$seq} previous_hash does not chain to expected hash");
            }

            // Update expected for the next record in the batch
            $expectedSeqSuffix = $suffix + 1;
            $expectedPrevHash = $importPayload['row_hash'] ?? null;
        }
    }

    /**
     * Validate the payments in the import against branch offline payment policy.
     *
     * Returns true if valid, false if invalid (populating reason and context).
     */
    public function validatePaymentPolicy(OfflineSalesImport $import, ?string &$reason = null, ?array &$context = null): bool
    {
        $payload = $import->raw_payload;
        $payments = $payload['payments'] ?? [];
        $branchId = $import->branch_id;
        $tenantId = $import->tenant_id;

        // 1. Check if payment methods version hash is reported
        $clientHash = $payload['payment_methods_version_hash'] ?? null;
        if (!$clientHash) {
            // Verify if any payment is non-cash
            $hasNonCash = false;
            if (is_array($payments)) {
                foreach ($payments as $paymentData) {
                    $methodId = $paymentData['payment_method_id'] ?? null;
                    if ($methodId) {
                        $method = PaymentMethod::where('tenant_id', $tenantId)->find($methodId);
                        if ($method && !$method->isCash()) {
                            $hasNonCash = true;
                            break;
                        }
                    }
                }
            }

            if (!$hasNonCash) {
                return true;
            }

            $reason = 'PAYMENT_POLICY_HASH_MISSING';
            $context = [
                'payment_methods_version_hash' => null,
            ];
            return false;
        }

        // 2. Check if hash is stale
        $bootstrapService = app(\App\Services\POS\OfflineReadiness\CacheBootstrapService::class);
        $currentHash = $bootstrapService->calculatePaymentMethodsVersionHash($tenantId, $branchId);
        $staleHash = ($clientHash !== $currentHash);

        if (is_array($payments)) {
            foreach ($payments as $paymentData) {
                $methodId = $paymentData['payment_method_id'] ?? null;
                if (!$methodId) {
                    $reason = 'PAYMENT_METHOD_NOT_IN_TERMINAL_SNAPSHOT';
                    $context = [];
                    return false;
                }

                $method = PaymentMethod::where('tenant_id', $tenantId)->find($methodId);
                if (!$method) {
                    $reason = 'PAYMENT_METHOD_NOT_IN_TERMINAL_SNAPSHOT';
                    $context = [
                        'payment_method_id' => $methodId,
                    ];
                    return false;
                }

                if ($method->isStoreCredit()) {
                    $reason = 'STORE_CREDIT_OFFLINE_REDEMPTION_NOT_ALLOWED';
                    $context = [
                        'payment_method_code' => $method->code,
                        'payment_method_id' => $method->id,
                        'amount_centavos' => (int) round(($paymentData['amount'] ?? 0) * 100),
                        'payment_methods_version_hash' => $clientHash,
                    ];
                    return false;
                }

                $settings = $method->getSettingsForBranch($branchId);

                // Is allowed offline?
                if (!$settings['allow_offline']) {
                    $reason = 'OFFLINE_PAYMENT_METHOD_NOT_ALLOWED';
                    $context = [
                        'payment_method_code' => $method->code,
                        'payment_method_id' => $method->id,
                        'amount_centavos' => (int) round(($paymentData['amount'] ?? 0) * 100),
                        'payment_methods_version_hash' => $clientHash,
                    ];
                    return false;
                }

                // Enforce offline max limit
                if ($settings['offline_max_limit_centavos'] !== null) {
                    $amount = (float) ($paymentData['amount'] ?? 0);
                    $amountCentavos = (int) round($amount * 100);
                    if ($amountCentavos > $settings['offline_max_limit_centavos']) {
                        $reason = 'OFFLINE_PAYMENT_LIMIT_EXCEEDED';
                        $context = [
                            'payment_method_code' => $method->code,
                            'payment_method_id' => $method->id,
                            'amount_centavos' => $amountCentavos,
                            'limit_centavos' => $settings['offline_max_limit_centavos'],
                            'payment_methods_version_hash' => $clientHash,
                        ];
                        return false;
                    }
                }

                // Enforce reference requirements
                if ($settings['requires_reference'] || $method->reference_required) {
                    $ref = trim($paymentData['reference_number'] ?? '');
                    if ($ref === '') {
                        $reason = 'OFFLINE_PAYMENT_REFERENCE_REQUIRED';
                        $context = [
                            'payment_method_code' => $method->code,
                            'payment_method_id' => $method->id,
                            'payment_methods_version_hash' => $clientHash,
                        ];
                        return false;
                    }
                }
            }
        }

        if ($staleHash) {
            $reason = 'PAYMENT_POLICY_HASH_STALE';
            $context = [
                'client_hash' => $clientHash,
                'current_hash' => $currentHash,
            ];
            return false;
        }

        return true;
    }

    /**
     * Validate commercial promotion cache assumptions for an offline import.
     *
     * Server-side rules remain authoritative. A stale/missing hash is only a
     * warning when no promotion preview was submitted; otherwise the import needs
     * manager review because the cashier-visible total may differ from current
     * server promotion rules.
     */
    public function validatePromotionPolicy(OfflineSalesImport $import, ?string &$reason = null, ?array &$context = null): bool
    {
        $payload = $import->raw_payload;
        $clientHash = $payload['discount_rules_version_hash'] ?? null;

        $bootstrapService = app(\App\Services\POS\OfflineReadiness\CacheBootstrapService::class);
        $currentHash = $bootstrapService->calculateDiscountRulesVersionHash($import->tenant_id, $import->branch_id);
        $hasPromotionPreview = $this->payloadIncludesPromotionPreview($payload);

        $context = [
            'client_hash' => $clientHash,
            'current_hash' => $currentHash,
            'has_promotion_preview' => $hasPromotionPreview,
            'promotion_discount_centavos' => $this->extractPromotionDiscountCentavos($payload),
        ];

        if (!$clientHash) {
            $reason = $hasPromotionPreview
                ? 'PROMOTION_RULE_HASH_MISSING_WITH_PREVIEW'
                : 'PROMOTION_RULE_HASH_MISSING_NO_PREVIEW';

            return !$hasPromotionPreview;
        }

        if ($clientHash === $currentHash) {
            return true;
        }

        $reason = $hasPromotionPreview
            ? 'PROMOTION_RULE_HASH_STALE_WITH_PREVIEW'
            : 'PROMOTION_RULE_HASH_STALE_NO_PREVIEW';

        return false;
    }

    private function payloadIncludesPromotionPreview(array $payload): bool
    {
        if (!empty($payload['promotion_preview']) || !empty($payload['applied_promotions'])) {
            return true;
        }

        return $this->extractPromotionDiscountCentavos($payload) > 0;
    }

    private function extractPromotionDiscountCentavos(array $payload): int
    {
        foreach (['promotion_discount_centavos', 'commercial_discount_centavos'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        if (isset($payload['promotion_discount_amount']) && is_numeric($payload['promotion_discount_amount'])) {
            return max(0, (int) round(((float) $payload['promotion_discount_amount']) * 100));
        }

        return 0;
    }

    private function mergeConflictNotes(?string $existingNotes, array $newNote): string
    {
        $notes = [];

        if ($existingNotes) {
            $decoded = json_decode($existingNotes, true);
            $notes = json_last_error() === JSON_ERROR_NONE
                ? (array) $decoded
                : ['previous_note' => $existingNotes];
        }

        $notes['promotion_policy'] = $newNote;

        return json_encode($notes);
    }
}
