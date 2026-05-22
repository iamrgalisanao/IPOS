<?php

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;

test('cashier redirects from dashboard to pos', function () {
    app(TenantContext::class)->clear();
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app(RbacSeeder::class)->seedForTenant($tenant);
    app(TenantContext::class)->setTenant($tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

    $cashier = User::factory()->create([
        'tenant_id' => $tenant->id,
        'actor_type' => 'tenant_user',
        'status' => 'active'
    ]);
    $cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
    $cashier->assignToBranch($branch);

    $response = $this->actingAs($cashier)->get('/dashboard');

    $response->assertRedirect(route('pos.index'));
});
