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
            ],
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
            $tenantContext->setTenant($user->tenant);
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
