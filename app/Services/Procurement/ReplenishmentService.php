<?php

namespace App\Services\Procurement;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\ExpiryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SaleItem;
use App\Models\Supplier;
use Carbon\Carbon;

class ReplenishmentService
{
    /**
     * Get replenishment recommendations for a specific branch.
     *
     * @param Branch $branch
     * @param array $excludePoIds PO IDs to exclude from outstanding calculations
     * @return array
     */
    public function getRecommendationsForBranch(Branch $branch, array $excludePoIds = []): array
    {
        // 1. Fetch active branch inventories with their products
        $inventories = BranchInventory::with(['product'])
            ->where('branch_id', $branch->id)
            ->where('status', 'active')
            ->get();

        $recommendations = [];

        foreach ($inventories as $inventory) {
            $product = $inventory->product;
            if (!$product || !$product->is_inventory_tracked) {
                continue;
            }

            // A. Calculate clean stock basis (excluding expired stock if expiry_tracking_enabled is true)
            $currentStock = (float) $inventory->current_stock;
            $expiredStock = 0.0;

            if ($product->expiry_tracking_enabled) {
                $expiredStock = (float) ExpiryLot::where('branch_id', $branch->id)
                    ->where('product_id', $product->id)
                    ->where('expiry_date', '<', Carbon::now())
                    ->where('quantity_remaining', '>', 0)
                    ->sum('quantity_remaining');
            }

            $cleanStockBasis = max(0.0, $currentStock - $expiredStock);

            // B. Calculate outstanding PO quantity for this branch/product
            // Purchase orders under status 'draft', 'approved', or 'sent'
            $outstandingPoQty = (float) PurchaseOrderLine::whereHas('purchaseOrder', function ($query) use ($branch, $excludePoIds) {
                $query->where('branch_id', $branch->id)
                    ->whereIn('status', [
                        PurchaseOrder::STATUS_DRAFT,
                        PurchaseOrder::STATUS_APPROVED,
                        PurchaseOrder::STATUS_SENT
                    ]);
                
                if (!empty($excludePoIds)) {
                    $query->whereNotIn('id', $excludePoIds);
                }
            })
            ->where('product_id', $product->id)
            ->sum('ordered_quantity');

            // C. Calculate daily consumption velocity in this branch in the last 30 days
            $thirtyDaysAgo = Carbon::now()->subDays(30);
            $totalSoldIn30Days = (float) SaleItem::where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->sum('quantity');

            $dailyConsumptionRate = $totalSoldIn30Days / 30.0;

            // D. Calculate active Reorder Point (ROP)
            // If explicit reorder_level > 0, use it as override.
            // Otherwise, calculate ROP = (Daily Consumption * Lead Time) + Safety Stock Buffer
            $reorderLevel = (float) $inventory->reorder_level;
            $leadTimeDays = (int) $inventory->lead_time_days;
            $safetyStockBuffer = (float) $inventory->safety_stock_buffer;

            $calculatedRop = ($dailyConsumptionRate * $leadTimeDays) + $safetyStockBuffer;
            $reorderPoint = $reorderLevel > 0 ? $reorderLevel : $calculatedRop;

            // E. Calculate trigger: Clean Stock Basis + Outstanding PO Qty < Reorder Point
            $effectiveStock = $cleanStockBasis + $outstandingPoQty;

            if ($effectiveStock < $reorderPoint) {
                // F. Calculate recommendation quantity up to PAR level (Target Stock)
                $parLevel = (float) $inventory->par_level;
                $recommendQty = max(0.0, $parLevel - $effectiveStock);

                if ($recommendQty <= 0.0) {
                    continue;
                }

                // G. Resolve supplier following the hierarchy:
                // 1. Direct Pivot Match: check Product's direct preferred_supplier_id
                // 2. Fallback (Historical PO): resolve to the supplier of the most recently completed PO for that product in the same tenant
                // 3. Placeholder: unassigned supplier placeholder
                $supplierId = null;
                $supplierName = null;

                if ($product->preferred_supplier_id) {
                    $supplier = Supplier::find($product->preferred_supplier_id);
                    if ($supplier) {
                        $supplierId = $supplier->id;
                        $supplierName = $supplier->name;
                    }
                }

                if (!$supplierId) {
                    $lastCompletedPo = PurchaseOrder::where('tenant_id', $branch->tenant_id)
                        ->where('status', PurchaseOrder::STATUS_COMPLETED)
                        ->whereHas('lines', function ($query) use ($product) {
                            $query->where('product_id', $product->id);
                        })
                        ->orderBy('completed_at', 'desc')
                        ->first();

                    if ($lastCompletedPo) {
                        $supplier = $lastCompletedPo->supplier;
                        if ($supplier) {
                            $supplierId = $supplier->id;
                            $supplierName = $supplier->name;
                        }
                    }
                }

                $recommendations[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'branch_id' => $branch->id,
                    'current_stock' => $currentStock,
                    'expired_stock' => $expiredStock,
                    'clean_stock_basis' => $cleanStockBasis,
                    'reorder_level' => $reorderLevel,
                    'safety_stock_buffer' => $safetyStockBuffer,
                    'lead_time_days' => $leadTimeDays,
                    'daily_consumption_rate' => $dailyConsumptionRate,
                    'calculated_reorder_point' => $calculatedRop,
                    'reorder_point' => $reorderPoint,
                    'par_level' => $parLevel,
                    'outstanding_po_qty' => $outstandingPoQty,
                    'reorder_qty' => $recommendQty,
                    'supplier_id' => $supplierId,
                    'supplier_name' => $supplierName ?? 'Unassigned Supplier',
                ];
            }
        }

        return $recommendations;
    }
}
