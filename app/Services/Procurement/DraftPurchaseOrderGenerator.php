<?php

namespace App\Services\Procurement;

use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\Procurement\ReplenishmentService;
use Illuminate\Support\Facades\DB;

class DraftPurchaseOrderGenerator
{
    protected ReplenishmentService $replenishmentService;

    /**
     * Create a new generator instance.
     *
     * @param ReplenishmentService $replenishmentService
     */
    public function __construct(ReplenishmentService $replenishmentService)
    {
        $this->replenishmentService = $replenishmentService;
    }

    /**
     * Generate or update draft purchase orders for a given branch based on latest recommendations.
     *
     * @param Branch $branch
     * @param User $creator The user who triggers the action
     * @return array Array of created or updated PurchaseOrder models
     */
    public function generateForBranch(Branch $branch, User $creator): array
    {
        // Find existing draft POs for the branch to exclude them from the replenishment outstanding calculation
        $existingDraftPoIds = PurchaseOrder::where('branch_id', $branch->id)
            ->where('status', PurchaseOrder::STATUS_DRAFT)
            ->pluck('id')
            ->toArray();

        // 1. Fetch replenishment recommendations for the branch
        $recommendations = $this->replenishmentService->getRecommendationsForBranch($branch, $existingDraftPoIds);

        // Group recommendations by supplier_id
        $groupedRecs = [];
        foreach ($recommendations as $rec) {
            $supplierId = $rec['supplier_id'];
            if (!$supplierId) {
                // Skip recommendations with no resolved supplier
                continue;
            }
            $groupedRecs[$supplierId][] = $rec;
        }

        // If there are no recommendations but there are existing draft POs, they might need to be cleaned up
        // because their items are no longer below threshold.
        // Let's add them to groupedRecs with an empty array if they are not already present,
        // so that the line items can be successfully synchronized to empty (and deleted).
        $existingDraftPos = PurchaseOrder::where('branch_id', $branch->id)
            ->where('status', PurchaseOrder::STATUS_DRAFT)
            ->get();

        foreach ($existingDraftPos as $existingPo) {
            if (!isset($groupedRecs[$existingPo->supplier_id])) {
                $groupedRecs[$existingPo->supplier_id] = [];
            }
        }

        $purchaseOrders = [];

        foreach ($groupedRecs as $supplierId => $recs) {
            $result = DB::transaction(function () use ($branch, $creator, $supplierId, $recs) {
                // Check if a draft Purchase Order already exists for the same branch and supplier
                $po = PurchaseOrder::where('branch_id', $branch->id)
                    ->where('supplier_id', $supplierId)
                    ->where('status', PurchaseOrder::STATUS_DRAFT)
                    ->first();

                if (!$po) {
                    // Create new draft Purchase Order
                    $orderDate = now()->toDateString();
                    $poNumber = PurchaseOrder::generatePoNumber($branch->tenant_id, $branch->id, $orderDate);

                    $po = PurchaseOrder::create([
                        'tenant_id' => $branch->tenant_id,
                        'branch_id' => $branch->id,
                        'supplier_id' => $supplierId,
                        'po_number' => $poNumber,
                        'status' => PurchaseOrder::STATUS_DRAFT,
                        'order_date' => $orderDate,
                        'created_by' => $creator->id,
                        'total_estimated_amount' => 0.0000,
                    ]);
                }

                // Sync the PO lines based on recommendations
                $recommendedProductIds = [];
                $totalEstimatedAmount = 0.0000;

                foreach ($recs as $rec) {
                    $productId = $rec['product_id'];
                    $qty = (float) $rec['reorder_qty'];
                    $product = Product::find($productId);
                    $unitCost = $product ? (float) $product->cost_price : 0.0000;
                    $lineTotal = $qty * $unitCost;

                    $recommendedProductIds[] = $productId;

                    // Update or create line item
                    PurchaseOrderLine::updateOrCreate(
                        [
                            'purchase_order_id' => $po->id,
                            'product_id' => $productId,
                        ],
                        [
                            'ordered_quantity' => $qty,
                            'unit_cost' => $unitCost,
                            'line_total' => $lineTotal,
                        ]
                    );

                    $totalEstimatedAmount += $lineTotal;
                }

                // Remove line items in the draft PO that are no longer part of the recommendations
                $po->lines()
                    ->whereNotIn('product_id', $recommendedProductIds)
                    ->delete();

                // Delete the draft PO if it has no lines left
                if ($po->lines()->count() === 0) {
                    $po->delete();
                    return null;
                }

                // Recalculate PO total estimated amount
                $po->update([
                    'total_estimated_amount' => $totalEstimatedAmount,
                ]);

                return $po;
            });

            if ($result !== null) {
                $purchaseOrders[] = $result;
            }
        }

        return $purchaseOrders;
    }
}
