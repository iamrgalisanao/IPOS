<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Support\Collection;

class InventoryReconciliationService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    public function reconcileBranch(Branch $branch, ?string $productId = null): Collection
    {
        if ($this->tenantContext->hasTenant() && $branch->tenant_id !== $this->tenantContext->getTenantId()) {
            throw new \RuntimeException('Cannot reconcile inventory for a branch belonging to a different tenant.');
        }

        if ($this->branchContext->hasBranch() && $branch->id !== $this->branchContext->getBranchId()) {
            throw new \RuntimeException('Cannot reconcile inventory outside the active branch context.');
        }

        $query = BranchInventory::where('branch_id', $branch->id)
            ->with('product:id,name,sku');

        if ($productId) {
            $query->where('product_id', $productId);
        }

        return $query->get()->map(function (BranchInventory $inventory) {
            $movementDerivedStock = (float) InventoryMovement::where('tenant_id', $inventory->tenant_id)
                ->where('branch_id', $inventory->branch_id)
                ->where('product_id', $inventory->product_id)
                ->sum('quantity_change');

            $currentStock = (float) $inventory->current_stock;
            $variance = round($currentStock - $movementDerivedStock, 4);

            return [
                'tenant_id' => $inventory->tenant_id,
                'branch_id' => $inventory->branch_id,
                'product_id' => $inventory->product_id,
                'product_name' => $inventory->product?->name,
                'sku' => $inventory->product?->sku,
                'current_stock' => $currentStock,
                'movement_derived_stock' => round($movementDerivedStock, 4),
                'system_reconciliation_variance' => $variance,
                'is_reconciled' => abs($variance) < 0.0001,
            ];
        });
    }
}
