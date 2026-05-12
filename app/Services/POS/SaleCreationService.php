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
        array $rawItems
    ): array {
        $hash = $this->computePayloadHash($clientRequestUuid, $rawItems, $tenantId, $branchId, $userId);

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

        foreach ($rawItems as $item) {
            $product  = $products[$item['product_id']];
            $snapshot = $product->getSaleSnapshotBase();
            $quantity = (float) $item['quantity'];

            $lineSubtotal = $snapshot['selling_price'] * $quantity;
            $discountAmt  = 0.0; // placeholder — discounts not implemented in this story
            $taxAmount    = $lineSubtotal * ($snapshot['tax_rate'] / 100);
            $lineTotal    = $lineSubtotal - $discountAmt + $taxAmount;

            $subtotal += $lineSubtotal;
            $taxTotal += $taxAmount;

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
                'tax_rate'             => number_format($snapshot['tax_rate'], 4, '.', ''),
                'tax_amount'           => number_format($taxAmount, 4, '.', ''),
                'line_total'           => number_format($lineTotal, 4, '.', ''),
                'is_inventory_tracked' => $product->is_inventory_tracked,
                'created_at'           => now(),
            ];
        }

        $discountTotal = 0.0;
        $total         = $subtotal + $taxTotal - $discountTotal;

        // ---- 4. Atomic creation of Sale + SaleItems ----
        $sale = DB::transaction(function () use (
            $tenantId, $branchId, $userId, $clientRequestUuid,
            $checkoutRequest, $subtotal, $taxTotal, $discountTotal, $total,
            $saleItemsData
        ) {
            $sale = Sale::create([
                'id'                  => Str::uuid()->toString(),
                'tenant_id'           => $tenantId,
                'branch_id'           => $branchId,
                'user_id'             => $userId,
                'client_request_uuid' => $clientRequestUuid,
                'checkout_request_id' => $checkoutRequest->id,
                'status'              => 'created',
                'subtotal'            => number_format($subtotal, 4, '.', ''),
                'tax_total'           => number_format($taxTotal, 4, '.', ''),
                'discount_total'      => number_format($discountTotal, 4, '.', ''),
                'total'               => number_format($total, 4, '.', ''),
            ]);

            // Inject the sale_id into each line item row
            $rows = array_map(fn($item) => array_merge($item, ['sale_id' => $sale->id]), $saleItemsData);

            // Bulk insert for atomicity — if this fails, the outer transaction rolls back the Sale too
            SaleItem::insert($rows);

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
        string $userId
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
        ];

        return hash('sha256', json_encode($canonical));
    }
}
