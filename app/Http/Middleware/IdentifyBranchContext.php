<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyBranchContext
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->tenantContext->hasTenant()) {
            abort(403, 'Tenant context required for branch resolution.');
        }

        $branchId = $request->header('X-Branch-ID');

        // Handle case where header is literally string "null" from frontend
        if ($branchId === 'null' || !$branchId) {
            $branchId = null;
        }

        if (!$branchId) {
            $user = $request->user();
            if ($user) {
                // Primary fallback: User's first assigned branch
                $firstAssigned = $user->branches()->first();
                if ($firstAssigned) {
                    $branchId = $firstAssigned->id;
                } 
                // Secondary fallback: For Admins/Owners without explicit assignment, pick the first branch of the tenant
                elseif ($user->hasPermission('view_multi_branch_dashboard')) {
                    $tenantBranch = \App\Models\Branch::where('tenant_id', $this->tenantContext->getTenantId())->first();
                    if ($tenantBranch) {
                        $branchId = $tenantBranch->id;
                    }
                }
                
                if ($branchId) {
                    session(['active_branch_id' => $branchId]);
                }
            }
        }

        if (!$branchId) {
            abort(403, 'Branch context missing. Please select a branch from the dashboard.');
        }

        // Branch model is already scoped to the active tenant via BelongsToTenant trait.
        // If the branch belongs to another tenant, this find will fail (return null) because of the global scope.
        $branch = Branch::where('id', $branchId)->first();

        if (!$branch) {
            abort(403, 'Invalid branch context or access denied.');
        }

        if ($branch->status !== 'active') {
            abort(403, 'Branch account is ' . $branch->status . '.');
        }

        // Verify user access to this branch
        $user = $request->user();
        if ($user && !$user->canAccessBranch($branch)) {
            abort(403, 'User not assigned to this branch.');
        }

        $this->branchContext->setBranch($branch);

        return $next($request);
    }
}
