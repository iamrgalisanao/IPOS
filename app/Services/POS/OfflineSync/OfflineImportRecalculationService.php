<?php

namespace App\Services\POS\OfflineSync;

use App\Models\OfflineSalesImport;
use App\Models\Product;
use App\Models\SaleItem;
use App\Services\Tax\TaxSourceSnapshotService;
use Illuminate\Support\Facades\Log;

class OfflineImportRecalculationService
{
    public function __construct(
        protected TaxSourceSnapshotService $taxSourceSnapshotService
    ) {}

    /**
     * Recalculate and validate the economic content of an offline import.
     *
     * @return array{
     *   status: string,
     *   server_subtotal?: float,
     *   server_tax_total?: float,
     *   server_total?: float,
     *   server_recalculation?: array,
     *   reason?: string,
     *   conflict_notes?: string
     * }
     */
    public function recalculate(OfflineSalesImport $import): array
    {
        $payload = $import->raw_payload;
        $items = $payload['items'] ?? [];

        // 1. Resolve products
        $productIds = collect($items)->pluck('product_id')->unique()->values()->all();
        $products = Product::where('tenant_id', $import->tenant_id)
            ->whereIn('id', $productIds)
            ->active()
            ->with('taxCategory')
            ->get()
            ->keyBy('id');

        $missingProducts = array_diff($productIds, $products->keys()->all());
        if (!empty($missingProducts)) {
            $missingStr = implode(',', $missingProducts);
            $reason = "product_not_found:{$missingStr}";
            $import->update([
                'status'           => OfflineSalesImport::STATUS_REJECTED,
                'rejection_reason' => $reason,
            ]);
            return ['status' => OfflineSalesImport::STATUS_REJECTED, 'reason' => $reason];
        }

        // 2. Compute server-side totals
        $serverSubtotal = 0.0;
        $serverTaxTotal = 0.0;
        $serverTotal = 0.0;
        $serverItems = [];

        foreach ($items as $item) {
            $product = $products[$item['product_id']];
            $snapshot = $product->getSaleSnapshotBase();
            $quantity = (float) $item['quantity'];

            // NOTE: Story 28.8 uses base selling price only. BranchProductPricing is deferred.
            $lineSubtotal = $snapshot['selling_price'] * $quantity;
            $taxTypeNormalized = strtolower($snapshot['tax_type'] ?? 'non-vat');

            if ($taxTypeNormalized === 'vatable' || $taxTypeNormalized === 'vat') {
                $taxBucket = SaleItem::TAX_BUCKET_VATABLE;
                $rate = (float) ($snapshot['tax_rate'] ?? 0.0);
                $netLineTotal = $lineSubtotal / (1.00 + ($rate / 100.0));
                $taxAmount = $lineSubtotal - $netLineTotal;

                $netAmount = $netLineTotal;
                $vatableAmount = $netLineTotal;
                $vatExemptAmount = 0.0;
                $zeroRatedAmount = 0.0;
                $nonVatAmount = 0.0;
            } elseif ($taxTypeNormalized === 'exempt' || $taxTypeNormalized === 'exm') {
                $taxBucket = SaleItem::TAX_BUCKET_VAT_EXEMPT;
                $taxAmount = 0.0;
                $netAmount = $lineSubtotal;
                $vatableAmount = 0.0;
                $vatExemptAmount = $lineSubtotal;
                $zeroRatedAmount = 0.0;
                $nonVatAmount = 0.0;
            } elseif ($taxTypeNormalized === 'zero-rated' || $taxTypeNormalized === 'zero_rated' || $taxTypeNormalized === 'zro') {
                $taxBucket = SaleItem::TAX_BUCKET_ZERO_RATED;
                $taxAmount = 0.0;
                $netAmount = $lineSubtotal;
                $vatableAmount = 0.0;
                $vatExemptAmount = 0.0;
                $zeroRatedAmount = $lineSubtotal;
                $nonVatAmount = 0.0;
            } else {
                $taxBucket = SaleItem::TAX_BUCKET_NON_VAT;
                $taxAmount = 0.0;
                $netAmount = $lineSubtotal;
                $vatableAmount = 0.0;
                $vatExemptAmount = 0.0;
                $zeroRatedAmount = 0.0;
                $nonVatAmount = $lineSubtotal;
            }

            $serverSubtotal += $lineSubtotal;
            $serverTaxTotal += $taxAmount;

            $taxSnapshot = $this->taxSourceSnapshotService->prepareSaleItemTaxSnapshot([
                'tax_category_id'   => $snapshot['tax_category_id'],
                'tax_type'          => $snapshot['tax_type'],
                'tax_rate'          => $snapshot['tax_rate'],
                'tax_bucket'        => $taxBucket,
                'net_amount'        => $netAmount,
                'vatable_amount'    => $vatableAmount,
                'vat_exempt_amount' => $vatExemptAmount,
                'zero_rated_amount' => $zeroRatedAmount,
                'non_vat_amount'    => $nonVatAmount,
                'tax_source'        => SaleItem::TAX_SOURCE_SYSTEM,
                'is_discountable'   => $snapshot['is_discountable'] ?? false,
            ]);

            $serverItems[] = array_merge($snapshot, [
                'quantity'       => number_format($quantity, 4, '.', ''),
                'subtotal'       => number_format($lineSubtotal, 4, '.', ''),
                'tax_amount'     => number_format($taxAmount, 4, '.', ''),
                'tax_bucket'     => $taxBucket,
                'tax_snapshot'   => $taxSnapshot,
            ]);
        }

        $serverTotal = $serverSubtotal; // VAT is inclusive, no discount in 28.8

        $clientSubtotal = (float) ($payload['client_subtotal'] ?? 0);
        $clientTaxTotal = (float) ($payload['client_tax_total'] ?? 0);
        $clientTotal    = (float) ($payload['client_total'] ?? 0);

        $tolerance = config('offline.recalculation_tolerance', 0.01);

        $totalDiff = abs($serverTotal - $clientTotal);
        $taxDiff   = abs($serverTaxTotal - $clientTaxTotal);

        $isMatch = $totalDiff <= $tolerance && $taxDiff <= $tolerance;

        $serverRecalculation = [
            'server_subtotal'  => number_format($serverSubtotal, 4, '.', ''),
            'server_tax_total' => number_format($serverTaxTotal, 4, '.', ''),
            'server_total'     => number_format($serverTotal, 4, '.', ''),
            'items'            => $serverItems,
            'client_submitted' => [
                'client_subtotal'  => number_format($clientSubtotal, 4, '.', ''),
                'client_tax_total' => number_format($clientTaxTotal, 4, '.', ''),
                'client_total'     => number_format($clientTotal, 4, '.', ''),
            ]
        ];

        if ($isMatch) {
            $newStatus = $import->status === OfflineSalesImport::STATUS_ACCEPTED_WITH_WARNING
                ? OfflineSalesImport::STATUS_ACCEPTED_WITH_WARNING
                : OfflineSalesImport::STATUS_SERVER_VERIFIED;

            $import->update([
                'status'               => $newStatus,
                'server_recalculation' => $serverRecalculation,
            ]);

            return [
                'status'               => $newStatus,
                'server_subtotal'      => $serverRecalculation['server_subtotal'],
                'server_tax_total'     => $serverRecalculation['server_tax_total'],
                'server_total'         => $serverRecalculation['server_total'],
                'server_recalculation' => $serverRecalculation,
            ];
        }

        $conflictNotes = sprintf(
            'client_total=%.2f server_total=%.2f difference=%.2f',
            $clientTotal,
            $serverTotal,
            $totalDiff
        );

        $import->update([
            'status'               => OfflineSalesImport::STATUS_CONFLICT,
            'server_recalculation' => $serverRecalculation,
            'conflict_notes'       => $conflictNotes,
        ]);

        return [
            'status'               => OfflineSalesImport::STATUS_CONFLICT,
            'server_subtotal'      => $serverRecalculation['server_subtotal'],
            'server_tax_total'     => $serverRecalculation['server_tax_total'],
            'server_total'         => $serverRecalculation['server_total'],
            'server_recalculation' => $serverRecalculation,
            'conflict_notes'       => $conflictNotes,
        ];
    }
}
