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
        protected \App\Services\Inventory\FefoAllocationService $fefoAllocationService
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
     * @return array{status: string, sale?: Sale, message?: string}
     */
    public function createFromPayload(
        string $tenantId,
        string $branchId,
        string $userId,
        string $clientRequestUuid,
        array $rawItems,
        bool $isTrainingMode = false
    ): array {
        $hash = $this->computePayloadHash($clientRequestUuid, $rawItems, $tenantId, $branchId, $userId, $isTrainingMode);

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

        foreach ($rawItems as $item) {
            $product  = $products[$item['product_id']];
            $snapshot = $product->getSaleSnapshotBase();
            $quantity = (float) $item['quantity'];

            $lineSubtotal = $snapshot['selling_price'] * $quantity;
            $discountAmt  = 0.0; // placeholder — discounts not implemented in this story

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
        $total         = $subtotal - $discountTotal;

        // Resolve branch active SalesMachineProfile if exists
        $machineProfile = \App\Models\SalesMachineProfile::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->first();

        $profileSnapshot = app(\App\Services\Tax\TaxSourceSnapshotService::class)->prepareSaleTaxProfileSnapshot($machineProfile, [
            'tax_source'             => Sale::TAX_SOURCE_SYSTEM,
            'tax_computation_source' => Sale::TAX_SOURCE_SYSTEM,
            'tax_source_version'     => 'BIR_VAT_2026_BASELINE',
        ]);

        // ---- 4. Atomic creation of Sale + SaleItems ----
        $sale = DB::transaction(function () use (
            $tenantId, $branchId, $userId, $clientRequestUuid,
            $checkoutRequest, $subtotal, $taxTotal, $discountTotal, $total,
            $grossSalesAmount, $vatableSalesAmount, $vatExemptSalesAmount,
            $zeroRatedSalesAmount, $nonVatSalesAmount, $vatAmountTotal,
            $machineProfile, $profileSnapshot, $saleItemsData,
            $rawItems, $products, $isTrainingMode
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
                'statutory_discount_total'     => '0.0000',
                'commercial_discount_total'    => '0.0000',
                'other_adjustment_total'       => '0.0000',
                'contains_statutory_discount'  => false,
                'compliance_version'           => 'EPIC14_V1',
                'tax_source_version'           => 'BIR_VAT_2026_BASELINE',
                'tax_computation_source'       => 'system',
                'tax_profile_snapshot'         => $profileSnapshot,
                'invoice_issued_at'            => now(),
                'reporting_basis_at'           => now(),
                'confirmed_at'                 => now(),
                'is_training_mode'             => $isTrainingMode,
            ]);

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
        bool $isTrainingMode = false
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
        ];

        return hash('sha256', json_encode($canonical));
    }
}
