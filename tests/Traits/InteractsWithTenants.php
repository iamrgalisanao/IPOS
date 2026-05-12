<?php

namespace Tests\Traits;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\BranchContext;

trait InteractsWithTenants
{
    protected ?Tenant $tenant = null;

    protected function setupTenantContext(?Tenant $tenant = null): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = $tenant ?? Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
    }

    protected function createTenantUser(string $name = 'user', array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => $name,
            'tenant_id' => $this->tenant->id,
            'email' => $name . '@example.com',
        ], $attributes));
    }

    protected function givePermissionTo(User $user, string $permissionName): void
    {
        $permission = \App\Models\Permission::firstOrCreate([
            'tenant_id' => $this->tenant->id,
            'name' => $permissionName,
        ]);

        $role = \App\Models\Role::firstOrCreate([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Role ' . $permissionName,
        ]);

        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->assignRole($role);
    }
}
