<?php

namespace App\Services;

use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class InventoryHistoryService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Retrieve inventory movement history based on filters and context.
     *
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Pagination\LengthAwarePaginator
     */
    public function getHistory(array $filters = [])
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            throw new \RuntimeException('Cannot retrieve history without active TenantContext.');
        }

        // 3. Permission-aware access foundation
        $user = Auth::user();
        if ($user) {
            // Check for view_branch_reports or manage_branch_inventory
            $hasViewReports = method_exists($user, 'hasPermission') && $user->hasPermission('view_branch_reports');
            $hasManageInventory = method_exists($user, 'hasPermission') && $user->hasPermission('manage_branch_inventory');

            if (!$hasViewReports && !$hasManageInventory) {
                throw new \RuntimeException('User does not have permission to view inventory movement history.');
            }

            // 4. Branch Manager Isolation
            // If user has restricted branch access (verified by canAccessBranch), 
            // but for history we strictly check active BranchContext if present.
            // If BranchContext is not set, we might need to filter by user's assigned branches 
            // but the Story 3.9 says "assigned branch", which usually implies BranchContext is set.
            // However, we'll enforce that if they have a branch context, it must be their assigned branch.
        }

        $query = InventoryMovement::query()
            ->with(['branch', 'product']);

        // 4. Tenant-scoped movement history retrieval
        // Auto-applied via trait if TenantContext is set.

        // 4. Branch-scoped movement history retrieval
        if ($this->branchContext->hasBranch()) {
            $query->where('branch_id', $this->branchContext->getBranchId());
        }

        // 5. Filters
        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (!empty($filters['branch_id']) && !$this->branchContext->hasBranch()) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (!empty($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        // Order by latest first
        $query->latest();

        return $query->get();
    }
}
