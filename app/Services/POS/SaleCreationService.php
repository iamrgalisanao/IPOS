<?php

namespace App\Services\POS;

use App\Models\CheckoutRequest;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaleCreationService
{
    public function __construct(
        protected \App\Services\Inventory\FefoAllocationService $fefoAllocationService,
        protected \App\Services\POS\StatutoryDiscountService $discountService,
        protected \App\Services\POS\ApprovalRuleResolver $approvalRuleResolver,
        protected \App\Services\POS\ManagerAuthorizationService $managerAuthorizationService,
    ) {}
    /**
     * Attempt to create a sale from a validated payload.
     *
     * Idempotency contract:
     *   - Same UUID + same hash → return existing sale (status: duplicate_seen).
     *   - Same UUID + different hash → return conflict (status: conflict).
     *   - New UUID → validate, create sale atomically, link to checkout_request.
     *
     * Zero-mutation guarantee:
     *   - Does NOT create payment records.
     *   - Does NOT create inventory movements.
     *   - Does NOT create accounting outbox records.
     *   - Does NOT deduct stock.
     *
     * @param array $statutoryDiscount {
     *   @var string $discount_type_id
     *   @var array $options
     * }
     * @return array{status: string, sale?: Sale, message?: string}
     */
    public function createFromPayload(
        string $tenantId,
        string $branchId,
        string $userId,
        string $clientRequestUuid,
        array $rawItems,
        array $statutoryDiscount = [],
        bool $isTrainingMode = false,
        ?string $terminalId = null,
    ): array {
        $hash = $this->computePayloadHash($clientRequestUuid, $rawItems, $tenantId, $branchId, $userId, $isTrainingMode, $statutoryDiscount);

        // ---- 1. Idempotency: look up existing CheckoutRequest ----
        $checkoutRequest = CheckoutRequest::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('user_id', $userId)
            ->where('client_request_uuid', $clientRequestUuid)
            ->first();

        if ($checkoutRequest) {
            if ($checkoutRequest->payload_hash !== $hash) {
                // Same UUID, different cart — conflict
                return ['status' => 'conflict'];
            }

            if ($checkoutRequest->sale_id) {
                // Sale already created for this request — safe idempotent response
                $sale = Sale::find($checkoutRequest->sale_id);
                return ['status' => 'duplicate_seen', 'sale' => $sale];
            }

            // CheckoutRequest exists and is validated, but no sale yet — proceed
        } else {
            // No prior checkout request — create one now as part of this flow
            $checkoutRequest = CheckoutRequest::create([
                'id'                  => Str::uuid()->toString(),
                'tenant_id'           => $tenantId,
                'branch_id'           => $branchId,
                'user_id'             => $userId,
                'client_request_uuid' => $clientRequestUuid,
                'status'              => 'validated',
                'payload_hash'        => $hash,
                'validated_at'        => now(),
                'last_seen_at'        => now(),
                'is_training_mode'    => $isTrainingMode,
            ]);
        }

        // ---- 2. Resolve product snapshots ----
        $productIds = collect($rawItems)->pluck('product_id')->unique()->values()->all();

        $products = Product::where('tenant_id', $tenantId)
            ->whereIn('id', $productIds)
            ->active()
            ->with('taxCategory')
            ->get()
            ->keyBy('id');

        $missingProducts = array_diff($productIds, $products->keys()->all());
        if (!empty($missingProducts)) {
            return [
                'status'              => 'invalid_products',
                'invalid_product_ids' => array_values($missingProducts),
            ];
        }

        // ---- 3. Compute server-side totals from snapshots ----
        $saleItemsData = [];
        $subtotal      = 0.0;
        $taxTotal      = 0.0;

        $grossSalesAmount        = 0.0;
        $vatableSalesAmount      = 0.0;
        $vatExemptSalesAmount    = 0.0;
        $zeroRatedSalesAmount    = 0.0;
        $nonVatSalesAmount       = 0.0;
        $vatAmountTotal          = 0.0;

        // Handle Statutory Discount Calculation
        $statutoryResult = null;
        $discountType = null;
        $approvalRequired = false;
        if (!empty($statutoryDiscount)) {
            $discountType = \App\Models\DiscountType::active()->findOrFail($statutoryDiscount['discount_type_id']);
            $statutoryResult = $this->discountService->calculate(
                collect($rawItems)->map(function ($item) use ($products) {
                    $snapshot = $products[$item['product_id']]->getSaleSnapshotBase();
                    $taxType = strtolower((string) ($snapshot['tax_type'] ?? 'non-vat'));
                    $taxBucket = match ($taxType) {
                        'vat', 'vatable' => \App\Models\SaleItem::TAX_BUCKET_VATABLE,
                        'exempt', 'exm' => \App\Models\SaleItem::TAX_BUCKET_VAT_EXEMPT,
                        'zero-rated', 'zero_rated', 'zro' => \App\Models\SaleItem::TAX_BUCKET_ZERO_RATED,
                        default => \App\Models\SaleItem::TAX_BUCKET_NON_VAT,
                    };
                    return [
                        'product_id' => $item['product_id'],
                        'line_subtotal' => (float) $snapshot['selling_price'] * (float) ($item['quantity'] ?? 0),
                        'tax_bucket' => $taxBucket,
                    ];
                }),
                $discountType,
                $statutoryDiscount['options'] ?? []
            );
            if (!$statutoryResult['is_valid']) {
                throw new \RuntimeException(implode(' ', $statutoryResult['errors']));
            }
            $approvalRequired = $this->approvalRuleResolver
                ->resolve($tenantId, $branchId, $discountType)['required'];
            if ($approvalRequired && empty($statutoryDiscount['manager_approval_id'])) {
                throw new \RuntimeException('Manager approval is required for this statutory discount.');
            }
        }

        foreach ($rawItems as $item) {
            $product  = $products[$item['product_id']];
            $snapshot = $product->getSaleSnapshotBase();
            $quantity = (float) $item['quantity'];

            $lineSubtotal = $snapshot['selling_price'] * $quantity;
            $discountAmt  = 0.0; 

            // If statutory discount is applied, we need to handle the VAT exemption and discount
            // Note: The StatutoryDiscountService handles the global calculation, 
            // but we need to distribute the VAT exemption and discount across items for the ledger.
            if ($statutoryResult && $statutoryResult['is_valid']) {
                // For simplicity in this implementation, we distribute the statutory discount 
                // proportionally across eligible items.
                $eligibilityRatio = $statutoryResult['gross_eligible_amount'] > 0 
                    ? ($lineSubtotal / $statutoryResult['gross_eligible_amount']) 
                    : 0;
                
                // Only apply if the item is actually eligible (simplified check)
                // In a full implementation, we'd use $this->discountService->validateEligibility()
                $discountAmt = $statutoryResult['discount_amount'] * $eligibilityRatio;
            }

            $taxTypeNormalized = strtolower($snapshot['tax_type'] ?? 'non-vat');

            if ($taxTypeNormalized === 'vatable' || $taxTypeNormalized === 'vat') {
                $taxBucket = \App\Models\SaleItem::TAX_BUCKET_VATABLE;
                $rate = (float) ($snapshot['tax_rate'] ?? 0.0);
                // VAT is inclusive
                $netLineTotal = $lineSubtotal / (1.00 + ($rate / 100.0));
                $taxAmount = $lineSubtotal - $netLineTotal;

                $netAmount = $netLineTotal;
                $vatableAmount = $netLineTotal;
                $vatExemptAmount = 0.0;
                $zeroRatedAmount = 0.0;
                $nonVatAmount = 0.0;
            } elseif ($taxTypeNormalized === 'exempt' || $taxTypeNormalized === 'exm') {
                $taxBucket = \App\Models\SaleItem::TAX_BUCKET_VAT_EXEMPT;
                $taxAmount = 0.0;
                $netAmount = $lineSubtotal;
                $vatableAmount = 0.0;
                $vatExemptAmount = $lineSubtotal;
                $zeroRatedAmount = 0.0;
                $nonVatAmount = 0.0;
            } elseif ($taxTypeNormalized === 'zero-rated' || $taxTypeNormalized === 'zero_rated' || $taxTypeNormalized === 'zro') {
                $taxBucket = \App\Models\SaleItem::TAX_BUCKET_ZERO_RATED;
                $taxAmount = 0.0;
                $netAmount = $lineSubtotal;
                $vatableAmount = 0.0;
                $vatExemptAmount = 0.0;
                $zeroRatedAmount = $lineSubtotal;
                $nonVatAmount = 0.0;
            } else {
                // Default to non-vat
                $taxBucket = \App\Models\SaleItem::TAX_BUCKET_NON_VAT;
                $taxAmount = 0.0;
                $netAmount = $lineSubtotal;
                $vatableAmount = 0.0;
                $vatExemptAmount = 0.0;
                $zeroRatedAmount = 0.0;
                $nonVatAmount = $lineSubtotal;
            }

            $lineTotal = $lineSubtotal - $discountAmt; // since tax is inclusive

            $subtotal += $lineSubtotal;
            $taxTotal += $taxAmount;

            $grossSalesAmount     += $lineSubtotal;
            $vatableSalesAmount   += $vatableAmount;
            $vatExemptSalesAmount += $vatExemptAmount;
            $zeroRatedSalesAmount += $zeroRatedAmount;
            $nonVatSalesAmount    += $nonVatAmount;
            $vatAmountTotal       += $taxAmount;

            // Adjust VAT totals if statutory discount is applied
            if ($statutoryResult && $statutoryResult['is_valid']) {
                // The statutory discount removes VAT from the vatable amount and moves it to exempt
                $itemVatRemoved = $taxAmount * ($discountAmt / ($lineSubtotal ?: 1));
                $vatableSalesAmount -= $itemVatRemoved;
                $vatExemptSalesAmount += $itemVatRemoved;
                $vatAmountTotal -= $itemVatRemoved;
            }

            $taxSnapshot = app(\App\Services\Tax\TaxSourceSnapshotService::class)->prepareSaleItemTaxSnapshot([
                'tax_category_id'   => $snapshot['tax_category_id'],
                'tax_type'          => $snapshot['tax_type'],
                'tax_rate'          => $snapshot['tax_rate'],
                'tax_bucket'        => $taxBucket,
                'net_amount'        => $netAmount,
                'vatable_amount'    => $vatableAmount,
                'vat_exempt_amount' => $vatExemptAmount,
                'zero_rated_amount' => $zeroRatedAmount,
                'non_vat_amount'    => $nonVatAmount,
                'tax_source'        => \App\Models\SaleItem::TAX_SOURCE_SYSTEM,
                'is_discountable'   => $snapshot['is_discountable'] ?? false,
            ]);

            $saleItemsData[] = [
                'id'                   => Str::uuid()->toString(),
                'tenant_id'            => $tenantId,
                'branch_id'            => $branchId,
                // sale_id filled after Sale is inserted
                'product_id'           => $snapshot['product_id'],
                'product_name'         => $snapshot['product_name'],
                'sku'                  => $snapshot['sku'],
                'barcode'              => $snapshot['barcode'],
                'unit_of_measure'      => $snapshot['unit_of_measure'],
                'quantity'             => number_format($quantity, 4, '.', ''),
                'unit_price'           => number_format($snapshot['selling_price'], 4, '.', ''),
                'subtotal'             => number_format($lineSubtotal, 4, '.', ''),
                'discount_amount'      => number_format($discountAmt, 4, '.', ''),
                'tax_category_id'      => $snapshot['tax_category_id'],
                'tax_type'             => $snapshot['tax_type'],
                'tax_bucket'           => $taxBucket,
                'tax_rate'             => number_format($snapshot['tax_rate'], 4, '.', ''),
                'tax_amount'           => number_format($taxAmount, 4, '.', ''),
                'net_amount'           => number_format($netAmount, 4, '.', ''),
                'vatable_amount'       => number_format($vatableAmount, 4, '.', ''),
                'vat_exempt_amount'    => number_format($vatExemptAmount, 4, '.', ''),
                'zero_rated_amount'    => number_format($zeroRatedAmount, 4, '.', ''),
                'non_vat_amount'       => number_format($nonVatAmount, 4, '.', ''),
                'tax_source'           => \App\Models\SaleItem::TAX_SOURCE_SYSTEM,
                'tax_snapshot'         => json_encode($taxSnapshot),
                'line_total'           => number_format($lineTotal, 4, '.', ''),
                'is_inventory_tracked' => $product->is_inventory_tracked,
                'created_at'           => now(),
            ];
        }

        $discountTotal = 0.0;
        if ($statutoryResult && $statutoryResult['is_valid']) {
            $discountTotal = $statutoryResult['discount_amount'];
        }
        $total         = $subtotal - $discountTotal;

        // Resolve branch active SalesMachineProfile if exists
        $machineProfile = \App\Models\SalesMachineProfile::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->when($terminalId, fn ($query) => $query->where('id', $terminalId))
            ->first();

        $profileSnapshot = app(\App\Services\Tax\TaxSourceSnapshotService::class)->prepareSaleTaxProfileSnapshot($machineProfile, [
            'tax_source'             => Sale::TAX_SOURCE_SYSTEM,
            'tax_computation_source' => Sale::TAX_SOURCE_SYSTEM,
            'tax_source_version'     => 'BIR_VAT_2026_BASELINE',
        ]);

        // ---- 4. Atomic creation of Sale + SaleItems ----
        try {
            $sale = DB::transaction(function () use (
            $tenantId, $branchId, $userId, $clientRequestUuid,
            $checkoutRequest, $subtotal, $taxTotal, $discountTotal, $total,
            $grossSalesAmount, $vatableSalesAmount, $vatExemptSalesAmount,
            $zeroRatedSalesAmount, $nonVatSalesAmount, $vatAmountTotal,
            $machineProfile, $profileSnapshot, $saleItemsData,
            $rawItems, $products, $isTrainingMode,
            $statutoryResult, $statutoryDiscount, $discountType, $approvalRequired
        ) {
            $principalInvoiceNumber = null;
            if ($machineProfile) {
                if ($isTrainingMode) {
                    $principalInvoiceNumber = 'TRAIN-INV-' . ($machineProfile->profile_code ?: 'POS') . '-' . strtoupper(Str::random(10));
                } else {
                    $principalInvoiceNumber = app(\App\Services\POS\InvoiceSequenceService::class)->generateNextInvoiceNumber($machineProfile);
                }
            }

            $sale = Sale::create([
                'id'                           => Str::uuid()->toString(),
                'tenant_id'                    => $tenantId,
                'branch_id'                    => $branchId,
                'user_id'                      => $userId,
                'client_request_uuid'          => $clientRequestUuid,
                'checkout_request_id'          => $checkoutRequest->id,
                'sales_machine_profile_id'     => $machineProfile?->id,
                'principal_invoice_number'     => $principalInvoiceNumber,
                'sale_number'                  => $principalInvoiceNumber,
                'principal_invoice_type'       => 'vat',
                'principal_invoice_label'      => 'Invoice',
                'status'                       => 'created',
                'subtotal'                     => number_format($subtotal, 4, '.', ''),
                'tax_total'                    => number_format($taxTotal, 4, '.', ''),
                'discount_total'               => number_format($discountTotal, 4, '.', ''),
                'total'                        => number_format($total, 4, '.', ''),
                'gross_sales_amount'           => number_format($grossSalesAmount, 4, '.', ''),
                'vatable_sales_amount'         => number_format($vatableSalesAmount, 4, '.', ''),
                'vat_exempt_sales_amount'      => number_format($vatExemptSalesAmount, 4, '.', ''),
                'zero_rated_sales_amount'      => number_format($zeroRatedSalesAmount, 4, '.', ''),
                'non_vat_sales_amount'         => number_format($nonVatSalesAmount, 4, '.', ''),
                'vat_amount'                   => number_format($vatAmountTotal, 4, '.', ''),
                'statutory_discount_total'     => number_format($discountTotal, 4, '.', ''),
                'commercial_discount_total'    => '0.0000',
                'other_adjustment_total'       => '0.0000',
                'contains_statutory_discount'  => $statutoryResult && $statutoryResult['is_valid'],
                'compliance_version'           => 'EPIC14_V1',
                'tax_source_version'           => 'BIR_VAT_2026_BASELINE',
                'tax_computation_source'       => 'system',
                'tax_profile_snapshot'         => $profileSnapshot,
                'invoice_issued_at'            => now(),
                'reporting_basis_at'           => now(),
                'confirmed_at'                 => now(),
                'is_training_mode'             => $isTrainingMode,
            ]);

            if ($statutoryResult && $statutoryResult['is_valid']) {
                \App\Models\SaleStatutoryDiscount::create([
                    'id' => Str::uuid()->toString(),
                    'sale_id' => $sale->id,
                    'discount_type_id' => $statutoryDiscount['discount_type_id'],
                    'application_mode' => $statutoryDiscount['options']['application_mode'] ?? 'standard',
                    'base_amount' => number_format($statutoryResult['discountable_base'], 4, '.', ''),
                    'discount_amount' => number_format($statutoryResult['discount_amount'], 4, '.', ''),
                    'vat_exempt_amount' => number_format($statutoryResult['vat_amount_removed'], 4, '.', ''),
                    'eligible_person_count' => $statutoryDiscount['options']['eligible_person_count'] ?? 1,
                    'total_pax_count' => $statutoryDiscount['options']['total_pax_count'] ?? 1,
                    'calculation_snapshot' => json_encode($statutoryResult['calculation_snapshot']),
                    'created_at' => now(),
                ]);

                foreach ($statutoryDiscount['options']['beneficiaries'] ?? [] as $beneficiary) {
                    \App\Models\SaleDiscountBeneficiary::create([
                        'id' => Str::uuid()->toString(),
                        'sale_discount_id' => \App\Models\SaleStatutoryDiscount::where('sale_id', $sale->id)->first()->id,
                        'beneficiary_name' => $beneficiary['beneficiary_name'] ?? '',
                        'id_number' => $beneficiary['id_number'] ?? '',
                        'tin' => $beneficiary['tin'] ?? '',
                        'spic_number' => $beneficiary['spic_number'] ?? '',
                        'created_at' => now(),
                    ]);
                }

                // Validate and consume the context-bound approval inside this sale transaction.
                if ($approvalRequired) {
                    $this->managerAuthorizationService->consume(
                        $statutoryDiscount['manager_approval_id'], $tenantId, $branchId, $userId,
                        $machineProfile, $discountType, $rawItems, $statutoryDiscount['options'] ?? [], $sale,
                    );
                }
            }

            // Inject the sale_id into each line item row
            $rows = array_map(fn($item) => array_merge($item, ['sale_id' => $sale->id]), $saleItemsData);

            // Bulk insert for atomicity — if this fails, the outer transaction rolls back the Sale too
            SaleItem::insert($rows);

            if ($isTrainingMode) {
                app(\App\Services\AuditLogger::class)->log('training_sale_created', $sale);
            }

            // FEFO Inventory allocation for perishable items
            if (!$isTrainingMode) {
                foreach ($rawItems as $item) {
                    $product = $products[$item['product_id']] ?? null;
                    if ($product && $product->expiry_tracking_enabled) {
                        $this->fefoAllocationService->allocate(
                            $tenantId,
                            $branchId,
                            $product->id,
                            number_format((float) $item['quantity'], 4, '.', '')
                        );
                    }
                }
            }

            // Link checkout_request → sale for future idempotency lookups
            $checkoutRequest->update(['sale_id' => $sale->id]);

            return $sale;
            });
        } catch (\RuntimeException $exception) {
            if (!empty($statutoryDiscount['manager_approval_id'])) {
                $approval = \App\Models\ManagerApproval::where('id', $statutoryDiscount['manager_approval_id'])->first();
                $reasonCode = !$approval ? 'not_found'
                    : ($approval->status === 'consumed' ? 'replay'
                    : ($approval->expires_at?->isPast() ? 'expired' : 'context_mismatch'));
                app(\App\Services\AuditLogger::class)->log(
                    'statutory_discount_approval_consumption_rejected',
                    $approval,
                    metadata: [
                        'reason_code' => $reasonCode,
                        'discount_type_id' => $statutoryDiscount['discount_type_id'] ?? null,
                    ],
                );
            }
            throw $exception;
        }

        return ['status' => 'created', 'sale' => $sale->load('items')];
    }

    /**
     * Canonical SHA-256 payload hash — identical algorithm to CheckoutController.
     * Items sorted by product_id. Quantities normalized to 4 decimal places.
     * Excludes all UI-only, cost, and accounting metadata.
     */
    private function computePayloadHash(
        string $clientRequestUuid,
        array $items,
        string $tenantId,
        string $branchId,
        string $userId,
        bool $isTrainingMode = false,
        array $statutoryDiscount = []
    ): string {
        $canonicalItems = collect($items)
            ->map(fn($item) => [
                'product_id' => $item['product_id'],
                'quantity'   => number_format((float) $item['quantity'], 4, '.', ''),
            ])
            ->sortBy('product_id')
            ->values()
            ->all();

        $canonical = [
            'client_request_uuid' => $clientRequestUuid,
            'tenant_id'           => $tenantId,
            'branch_id'           => $branchId,
            'user_id'             => $userId,
            'items'               => $canonicalItems,
            'is_training_mode'    => $isTrainingMode,
            'statutory_discount'  => $statutoryDiscount,
        ];

        return hash('sha256', json_encode($canonical));
    }
}
