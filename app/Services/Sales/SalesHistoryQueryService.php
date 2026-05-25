<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesHistoryQueryService
{
    /**
     * Query transactional history with multi-dimensional filtering.
     * Enforces strict tenant and branch isolation.
     */
    public function query(User $user, array $filters = []): LengthAwarePaginator
    {
        return $this->getBuilder($user, $filters)->paginate($filters['per_page'] ?? 25);
    }

    /**
     * Get the underlying query builder for history queries.
     * Useful for exports and custom aggregations.
     */
    public function getBuilder(User $user, array $filters = []): Builder
    {
        $query = Sale::query()->with([
            'branch:id,name',
            'user:id,name',
            'salesMachineProfile:id,profile_code,terminal_identifier',
        ]);

        // 1. Branch Isolation
        $this->applyBranchScoping($query, $user, $filters['branch_id'] ?? null);

        // 2. Date Range Filter
        if (!empty($filters['start_date'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereRaw('COALESCE(reporting_basis_at, confirmed_at, created_at) >= ?', [$filters['start_date']]);
            });
        }
        if (!empty($filters['end_date'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereRaw('COALESCE(reporting_basis_at, confirmed_at, created_at) <= ?', [$filters['end_date']]);
            });
        }

        // 3. Status Filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // 4. Cashier Filter
        if (!empty($filters['cashier_id'])) {
            $query->where('user_id', $filters['cashier_id']);
        }

        // 5. Payment Method Filter
        if (!empty($filters['payment_method_id'])) {
            $query->whereHas('payments', function ($q) use ($filters) {
                $q->where('payment_method_id', $filters['payment_method_id']);
            });
        }

        // 6. Search (Sale Number or Client Request UUID)
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                  ->orWhere('client_request_uuid', $search);
            });
        }

        // 7. Stable Ordering
        return $query->orderBy(DB::raw('COALESCE(reporting_basis_at, confirmed_at, created_at)'), 'desc')
                     ->orderBy('created_at', 'desc');
    }

    /**
     * Fetch a single sale with full history and reversal details.
     */
    public function find(User $user, string $saleId): Sale
    {
        $query = Sale::with([
            'items',
            'payments.paymentMethod',
            'user:id,name',
            'branch:id,name',
            'reversals',
            'reversalOfSale'
        ]);

        // Branch Isolation for single record
        $this->applyBranchScoping($query, $user, null);

        return $query->findOrFail($saleId);
    }

    /**
     * Apply branch scoping based on user permissions and requested branch.
     */
    protected function applyBranchScoping(Builder $query, User $user, ?string $requestedBranchId): void
    {
        // If user has cross-branch view permission
        if ($user->hasPermission('view_multi_branch_dashboard')) {
            if ($requestedBranchId) {
                $query->where('branch_id', $requestedBranchId);
            }
            return;
        }

        // Otherwise, restrict to user's assigned branches
        $assignedBranchIds = $user->branches()->pluck('branches.id')->map(fn($id) => (string) $id)->all();

        if (empty($assignedBranchIds)) {
            // User has no assigned branches and no global access -> return empty
            $query->whereRaw('1 = 0');
            return;
        }

        if ($requestedBranchId) {
            if (in_array($requestedBranchId, $assignedBranchIds, true)) {
                $query->where('branch_id', $requestedBranchId);
            } else {
                // Requested branch not in assigned list -> fail closed
                $query->whereRaw('1 = 0');
            }
        } else {
            $query->whereIn('branch_id', $assignedBranchIds);
        }
    }
}
