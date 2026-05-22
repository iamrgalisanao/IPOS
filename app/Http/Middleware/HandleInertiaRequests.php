<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'permissions' => $this->resolvePermissions($request),
                'tenant' => $this->resolveTenantData($request),
            ],
            'tenant_id' => app(\App\Services\TenantContext::class)->getTenantId(),
            'branch_id' => app(\App\Services\BranchContext::class)->getBranchId(),
        ];
    }

    protected function resolveTenantData(Request $request): ?array
    {
        $tenantContext = app(TenantContext::class);
        $tenant = $tenantContext->getTenant();

        if (!$tenant && $request->user()?->tenant_id) {
            $tenant = $request->user()->tenant;
        }

        if (!$tenant) {
            return null;
        }

        $metadata = $tenant->subscription_metadata ?? [];
        $plan = $metadata['plan'] ?? config('subscriptions.default_tier', 'basic');
        $tierConfig = config("subscriptions.tiers.{$plan}") ?? config('subscriptions.tiers.' . config('subscriptions.default_tier', 'basic'));
        
        $features = $tierConfig['features'] ?? [];
        if (isset($metadata['features']) && is_array($metadata['features'])) {
            $features = array_merge($features, $metadata['features']);
        }

        $limits = $tierConfig['limits'] ?? [];
        if (isset($metadata['limits']) && is_array($metadata['limits'])) {
            $limits = array_merge($limits, $metadata['limits']);
        }

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'subscription' => [
                'plan' => $plan,
                'features' => array_keys(array_filter($features)),
                'limits' => $limits,
            ]
        ];
    }


    protected function resolvePermissions(Request $request): array
    {
        $user = $request->user();

        if (!$user) {
            return [];
        }

        $tenantContext = app(TenantContext::class);

        if (!$tenantContext->hasTenant() && $user->tenant_id) {
            $tenant = $user->tenant;
            if ($tenant) {
                $tenantContext->setTenant($tenant);
            }
        }

        if (!$tenantContext->hasTenant()) {
            return [];
        }

        return $user->roles()
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }
}
