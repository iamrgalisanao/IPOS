<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSubscriptionGate
{
    public function __construct(protected TenantContext $tenantContext)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = $this->tenantContext->getTenant();

        // If no active tenant context exists in memory, fail-closed
        if (!$tenant) {
            return $this->respondForbidden($request);
        }

        // Verify if active tenant has the required feature
        if (!$tenant->hasFeature($feature)) {
            return $this->respondForbidden($request);
        }

        return $next($request);
    }

    /**
     * Respond with a standardized 403 Forbidden payload or standard abort.
     */
    protected function respondForbidden(Request $request): Response
    {
        // Fail-closed standard JSON response for API or AJAX/Inertia requests
        if ($request->expectsJson() || $request->header('X-Inertia')) {
            return response()->json([
                'status' => 'error',
                'code' => 'TSMS_SUB_001',
                'message' => 'This feature requires a premium subscription upgrade.'
            ], 403);
        }

        // Standard web fallback abort
        abort(403, 'This feature requires a premium subscription upgrade.');
    }
}
