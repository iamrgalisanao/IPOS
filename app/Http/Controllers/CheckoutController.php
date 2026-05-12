<?php

namespace App\Http\Controllers;

use App\Http\Requests\ValidateCheckoutRequest;
use App\Models\BranchInventory;
use App\Models\CheckoutRequest;
use App\Models\Product;
use App\Services\BranchContext;
use App\Services\POS\SaleCreationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    /**
     * Validate a POS draft cart submission and establish the idempotency record.
     *
     * This story validates only. It does NOT:
     * - Create a final sale record
     * - Create sale line items
     * - Deduct inventory
     * - Create payment records
     * - Create accounting outbox entries
     */
    public function validateDraft(
        ValidateCheckoutRequest $request,
        TenantContext $tenantContext,
        BranchContext $branchContext
    ): JsonResponse {
        $tenantId  = $tenantContext->getTenantId();
        $branchId  = $branchContext->getBranchId();
        $userId    = Auth::id();
        $clientUuid = $request->input('client_request_uuid');
        $rawItems   = $request->input('items');

        // --- 1. Compute canonical SHA-256 payload hash ---
        $hash = $this->computePayloadHash(
            clientRequestUuid: $clientUuid,
            items: $rawItems,
            tenantId: $tenantId,
            branchId: $branchId,
            userId: $userId
        );

        // --- 2. Idempotency Lookup ---
        $existing = CheckoutRequest::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('user_id', $userId)
            ->where('client_request_uuid', $clientUuid)
            ->first();

        if ($existing) {
            if ($existing->payload_hash === $hash) {
                // Same request re-submitted — safe idempotent response
                $existing->last_seen_at = now();
                $existing->save();

                return response()->json([
                    'status'              => 'duplicate_seen',
                    'client_request_uuid' => $clientUuid,
                ]);
            }

            // Same UUID but different payload — conflict, do NOT modify original record
            return response()->json([
                'status'  => 'conflict',
                'message' => 'This checkout request was already used with a different cart payload.',
            ], 409);
        }

        // --- 3. Domain Validation: Resolve products server-side ---
        $productIds = collect($rawItems)->pluck('product_id')->unique()->values()->all();

        $products = Product::where('tenant_id', $tenantId)
            ->whereIn('id', $productIds)
            ->active()
            ->with('taxCategory')
            ->get()
            ->keyBy('id');

        // Validate all products are found, active, and tenant-scoped
        $missingProducts = array_diff($productIds, $products->keys()->all());
        if (!empty($missingProducts)) {
            return response()->json([
                'message' => 'One or more products are invalid, inactive, or do not belong to this tenant.',
                'invalid_product_ids' => array_values($missingProducts),
            ], 422);
        }

        // --- 4. Build validated items snapshot & calculate server totals ---
        $validatedItems = [];
        $inventoryErrors = [];
        $subtotal  = 0.0;
        $taxTotal  = 0.0;

        foreach ($rawItems as $item) {
            $product  = $products[$item['product_id']];
            $snapshot = $product->getSaleSnapshotBase();
            $quantity = (float) $item['quantity'];

            // Inventory check for tracked products
            if ($product->is_inventory_tracked) {
                $inventory = BranchInventory::where('product_id', $product->id)
                    ->where('branch_id', $branchId)
                    ->active()
                    ->first();

                if (!$inventory || $inventory->current_stock <= 0) {
                    $inventoryErrors[] = [
                        'product_id'   => $product->id,
                        'product_name' => $product->name,
                        'reason'       => 'Insufficient or unavailable branch inventory.',
                    ];
                    continue;
                }
            }

            $lineSubtotal = $snapshot['selling_price'] * $quantity;
            $lineTax      = $lineSubtotal * ($snapshot['tax_rate'] / 100);

            $subtotal += $lineSubtotal;
            $taxTotal += $lineTax;

            $validatedItems[] = [
                'product_id'    => $snapshot['product_id'],
                'product_name'  => $snapshot['product_name'],
                'quantity'      => number_format($quantity, 4, '.', ''),
                'unit_price'    => number_format($snapshot['selling_price'], 4, '.', ''),
                'tax_type'      => $snapshot['tax_type'],
                'tax_rate'      => number_format($snapshot['tax_rate'], 4, '.', ''),
            ];
        }

        if (!empty($inventoryErrors)) {
            return response()->json([
                'message'          => 'One or more inventory-tracked products are unavailable at this branch.',
                'inventory_errors' => $inventoryErrors,
            ], 422);
        }

        if (empty($validatedItems)) {
            return response()->json([
                'message' => 'No valid items remain after domain validation.',
            ], 422);
        }

        $total = $subtotal + $taxTotal;

        // --- 5. Persist idempotency record ---
        CheckoutRequest::create([
            'id'                  => Str::uuid()->toString(),
            'tenant_id'           => $tenantId,
            'branch_id'           => $branchId,
            'user_id'             => $userId,
            'client_request_uuid' => $clientUuid,
            'status'              => 'validated',
            'payload_hash'        => $hash,
            'validated_at'        => now(),
            'last_seen_at'        => now(),
        ]);

        // --- 6. Return validated response contract ---
        return response()->json([
            'status'              => 'validated',
            'client_request_uuid' => $clientUuid,
            'server_totals' => [
                'subtotal'  => number_format($subtotal, 4, '.', ''),
                'tax_total' => number_format($taxTotal, 4, '.', ''),
                'total'     => number_format($total, 4, '.', ''),
            ],
            'items' => $validatedItems,
        ]);
    }

    /**
     * Compute a canonical SHA-256 hash of the checkout payload.
     *
     * The hash is based solely on server-relevant identity fields.
     * It excludes: display names, UI labels, cost_price, accounting metadata.
     * Items are sorted by product_id to prevent false conflicts from client ordering.
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

    /**
     * Create an atomic, idempotent sale from a validated cart payload.
     *
     * Middleware: auth, tenant, branch, permission:create_sale (same as validateDraft).
     * Route: POST /pos/checkout/create-sale
     *
     * This action does NOT:
     * - Create payment records
     * - Deduct inventory
     * - Create accounting outbox records
     * - Generate receipts
     */
    public function createSale(
        ValidateCheckoutRequest $request,
        TenantContext $tenantContext,
        BranchContext $branchContext,
        SaleCreationService $saleCreationService
    ): JsonResponse {
        $tenantId   = $tenantContext->getTenantId();
        $branchId   = $branchContext->getBranchId();
        $userId     = Auth::id();
        $clientUuid = $request->input('client_request_uuid');
        $rawItems   = $request->input('items');

        $result = $saleCreationService->createFromPayload(
            tenantId: $tenantId,
            branchId: $branchId,
            userId: $userId,
            clientRequestUuid: $clientUuid,
            rawItems: $rawItems
        );

        return match ($result['status']) {
            'created' => $this->buildSaleResponse($result['sale'], $clientUuid, 'created'),
            'duplicate_seen' => $this->buildSaleResponse($result['sale'], $clientUuid, 'duplicate_seen'),
            'conflict' => response()->json([
                'status'  => 'conflict',
                'message' => 'This checkout request was already used with a different cart payload.',
            ], 409),
            default => response()->json(['message' => 'Sale creation failed.'], 422),
        };
    }

    /**
     * Build the exact response contract for a sale creation result.
     */
    private function buildSaleResponse($sale, string $clientUuid, string $status): JsonResponse
    {
        $sale->loadMissing('items');

        $items = $sale->items->map(fn($item) => [
            'product_id'   => $item->product_id,
            'product_name' => $item->product_name,
            'quantity'     => number_format((float) $item->quantity, 4, '.', ''),
            'unit_price'   => number_format((float) $item->unit_price, 4, '.', ''),
            'tax_type'     => $item->tax_type,
            'tax_rate'     => number_format((float) $item->tax_rate, 4, '.', ''),
            'line_total'   => number_format((float) $item->line_total, 4, '.', ''),
        ])->values();

        return response()->json([
            'status'              => $status,
            'client_request_uuid' => $clientUuid,
            'sale_id'             => $sale->id,
            'server_totals'       => [
                'subtotal'       => number_format((float) $sale->subtotal, 4, '.', ''),
                'tax_total'      => number_format((float) $sale->tax_total, 4, '.', ''),
                'discount_total' => number_format((float) $sale->discount_total, 4, '.', ''),
                'total'          => number_format((float) $sale->total, 4, '.', ''),
            ],
            'items' => $items,
        ]);
    }
}
