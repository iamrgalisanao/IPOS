<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OfflineReadinessController extends Controller
{
    public function __construct(
        protected CacheBootstrapService $bootstrapService,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * GET /api/pos/bootstrap-cache
     *
     * Serves the bootstrap cache payload for the current tenant and branch context.
     */
    public function bootstrapCache(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $branch = $this->branchContext->getBranch();

        if (!$tenant || !$branch) {
            return response()->json([
                'error' => 'MISSING_CONTEXT',
                'message' => 'Tenant and Branch contexts are required.'
            ], 403);
        }

        $payload = $this->bootstrapService->generatePayload($tenant, $branch, $request->user());

        return response()->json($payload);
    }
}
