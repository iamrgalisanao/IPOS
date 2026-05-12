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
            abort(403, 'Branch context missing.');
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
