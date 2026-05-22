<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenantContext
{
    public function __construct(protected TenantContext $tenantContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $headerTenantId = $request->header('X-Tenant-ID') ?: $request->query('test_tenant_id');
        $user = $request->user();
        
        $resolvedTenantId = null;

        if ($user) {
            // Block deactivated users
            if (!$user->isActive()) {
                abort(403, 'User account is deactivated.');
            }

            // Platform support users with no tenant_id are accessing system-admin routes
            // Allow them to bypass tenant context requirement
            if ($user->isPlatformSupport()) {
                if ($user->tenant_id === null) {
                    // Platform admin accessing cross-tenant endpoints; skip tenant context
                    return $next($request);
                }
                // Platform support user with tenant_id is not allowed on tenant routes
                abort(403, 'Platform support access restricted.');
            }

            // Tenant user context comes from their assigned tenant_id
            $resolvedTenantId = $user->tenant_id;

            // Security check: If header is provided, it MUST match user tenant
            if ($headerTenantId && $headerTenantId !== $resolvedTenantId) {
                abort(403, 'Tenant context mismatch.');
            }
        } else {
            $resolvedTenantId = $headerTenantId;
        }

        if (!$resolvedTenantId) {
            abort(403, 'Tenant context missing.');
        }

        $tenant = Tenant::where('id', $resolvedTenantId)->first();

        if (!$tenant) {
            abort(403, 'Invalid tenant context.');
        }

        if ($tenant->status !== 'active') {
            abort(403, 'Tenant account is ' . $tenant->status . '.');
        }

        $this->tenantContext->setTenant($tenant);

        return $next($request);
    }
}
