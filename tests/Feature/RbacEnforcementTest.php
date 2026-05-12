<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_rbac_entities_can_be_created_and_assigned(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        // 1. Permission creation
        $permission = Permission::create(['name' => 'test_perm', 'description' => 'Test']);
        $this->assertDatabaseHas('permissions', ['name' => 'test_perm', 'tenant_id' => $tenant->id]);

        // 2. Role creation
        $role = Role::create(['name' => 'Test Role']);
        $this->assertDatabaseHas('roles', ['name' => 'Test Role', 'tenant_id' => $tenant->id]);

        // 3. Role-permission assignment
        $role->permissions()->attach($permission->id);
        $this->assertTrue($role->permissions->contains($permission->id));

        // 4. Tenant user role assignment
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->assertTrue($user->roles->contains($role->id));
        
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_cashier_role_permissions(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $cashier = User::factory()->create();
        $cashier->assignRole(Role::where('name', 'Cashier')->first());
        app(TenantContext::class)->clear();

        Sanctum::actingAs($cashier);

        // 6. Cashier can access POS
        $this->getJson('/api/test/rbac/pos')->assertStatus(200);

        // 7. Cashier cannot access accounting
        $this->getJson('/api/test/rbac/accounting')->assertStatus(403);
    }

    /** @test */
    public function test_accountant_role_permissions(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $accountant = User::factory()->create();
        $accountant->assignRole(Role::where('name', 'Accountant')->first());
        app(TenantContext::class)->clear();

        Sanctum::actingAs($accountant);

        // 8. Accountant can access accounting
        $this->getJson('/api/test/rbac/accounting')->assertStatus(200);

        // 9. Accountant cannot access POS (unless explicitly granted)
        $this->getJson('/api/test/rbac/pos')->assertStatus(403);
    }

    /** @test */
    public function test_owner_admin_role_permissions(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $admin = User::factory()->create();
        $admin->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        Sanctum::actingAs($admin);

        // 10. Owner can access admin route
        $this->getJson('/api/test/rbac/admin')->assertStatus(200);
        
        // Owner can access POS too
        $this->getJson('/api/test/rbac/pos')->assertStatus(200);
    }

    /** @test */
    public function test_deactivated_user_blocked_even_with_role(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create(['status' => 'inactive']); // Deactivated
        $user->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        // 12. Deactivated user blocked despite having Admin role
        $this->getJson('/api/test/rbac/admin')->assertStatus(403)
            ->assertSee('User account is deactivated');
    }

    /** @test */
    public function test_user_under_inactive_tenant_blocked_even_with_role(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'inactive']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create();
        $user->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        // 13. Inactive tenant blocked despite valid role
        $this->getJson('/api/test/rbac/admin')->assertStatus(403)
            ->assertSee('Tenant account is inactive');
    }

    /** @test */
    public function test_user_under_suspended_tenant_blocked_even_with_role(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'suspended']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create();
        $user->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        // 14. Suspended tenant blocked despite valid role
        $this->getJson('/api/test/rbac/admin')->assertStatus(403)
            ->assertSee('Tenant account is suspended');
    }

    /** @test */
    public function test_platform_support_user_cannot_be_assigned_tenant_role(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        $supportUser = User::factory()->create([
            'actor_type' => 'platform_support',
            'tenant_id' => null,
        ]);

        app(TenantContext::class)->setTenant($tenant);
        $role = Role::first();
        app(TenantContext::class)->clear();

        // 15. Platform support user cannot receive normal tenant role through standard flow
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-tenant role assignment blocked.');

        $supportUser->assignRole($role);
    }

    /** @test */
    public function test_cross_tenant_role_assignment_is_blocked(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(RbacSeeder::class)->seedForTenant($tenantA);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        app(TenantContext::class)->setTenant($tenantA);
        $userA = User::factory()->create();
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $roleB = Role::first();
        app(TenantContext::class)->clear();

        // 5. User cannot be assigned a role from another tenant
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-tenant role assignment blocked.');

        $userA->assignRole($roleB);
    }

    /** @test */
    public function test_rbac_isolation_and_scoping(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(RbacSeeder::class)->seedForTenant($tenantA);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        // 16. Permission checks are tenant-scoped
        app(TenantContext::class)->setTenant($tenantA);
        $permA = Permission::where('name', 'access_pos')->first();
        $roleA = Role::where('name', 'Cashier')->first();
        $this->assertEquals($tenantA->id, $permA->tenant_id);
        $this->assertEquals($tenantA->id, $roleA->tenant_id);
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $permB = Permission::where('name', 'access_pos')->first();
        $this->assertEquals($tenantB->id, $permB->tenant_id);
        $this->assertNotEquals($permA->id, $permB->id);
        app(TenantContext::class)->clear();
    }
}
