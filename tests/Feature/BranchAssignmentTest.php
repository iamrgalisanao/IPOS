<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\User;
use App\Models\Role;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_user_can_be_assigned_to_branch_in_same_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        
        // 1. Tenant user can be assigned to a branch in the same tenant
        $user->assignToBranch($branch);
        $this->assertTrue($user->branches->contains($branch->id));
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_cross_tenant_branch_assignment_is_blocked(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($tenantA);
        $userA = User::factory()->create();
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create();
        app(TenantContext::class)->clear();

        // 2. User cannot be assigned to branch from another tenant
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-tenant branch assignment blocked.');

        $userA->assignToBranch($branchB);
    }

    /** @test */
    public function test_platform_support_user_cannot_be_assigned_to_tenant_branch(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $supportUser = User::factory()->create([
            'actor_type' => 'platform_support',
            'tenant_id' => null,
        ]);

        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create();
        app(TenantContext::class)->clear();

        // 3. Platform support user cannot be assigned to tenant branch through normal flow
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-tenant branch assignment blocked.');

        $supportUser->assignToBranch($branch);
    }

    /** @test */
    public function test_cashier_branch_access_enforcement(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $branchA = Branch::factory()->create(['name' => 'Branch A']);
        $branchB = Branch::factory()->create(['name' => 'Branch B']);
        
        $cashier = User::factory()->create();
        $cashier->assignRole(Role::where('name', 'Cashier')->first());
        $cashier->assignToBranch($branchA);
        app(TenantContext::class)->clear();

        Sanctum::actingAs($cashier);

        // 4. Cashier with assigned branch can access that branch route
        $this->withHeader('X-Branch-ID', $branchA->id)
            ->getJson('/api/branch-test')
            ->assertStatus(200);

        // 5. Cashier cannot access unassigned branch route
        $this->withHeader('X-Branch-ID', $branchB->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403)
            ->assertSee('User not assigned to this branch');
    }

    /** @test */
    public function test_branch_manager_branch_access_enforcement(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $branchA = Branch::factory()->create(['name' => 'Branch A']);
        $branchB = Branch::factory()->create(['name' => 'Branch B']);
        
        $manager = User::factory()->create();
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->assignToBranch($branchA);
        app(TenantContext::class)->clear();

        Sanctum::actingAs($manager);

        // 6. Branch Manager with assigned branch can access that branch route
        $this->withHeader('X-Branch-ID', $branchA->id)
            ->getJson('/api/branch-test')
            ->assertStatus(200);

        // 7. Branch Manager cannot access another branch without assignment
        $this->withHeader('X-Branch-ID', $branchB->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403)
            ->assertSee('User not assigned to this branch');
    }

    /** @test */
    public function test_owner_admin_tenant_wide_access(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantA);
        
        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['name' => 'Branch A']);
        $owner = User::factory()->create();
        $owner->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['name' => 'Branch B']);
        app(TenantContext::class)->clear();

        Sanctum::actingAs($owner);

        // 8. Owner/Admin with tenant-level permission can access all branches within own tenant
        $this->withHeader('X-Branch-ID', $branchA->id)
            ->getJson('/api/branch-test')
            ->assertStatus(200);

        // 9. Owner/Admin cannot access branch from another tenant
        $this->withHeader('X-Branch-ID', $branchB->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403)
            ->assertSee('Invalid branch context or access denied');
    }

    /** @test */
    public function test_accountant_branch_access_is_permission_based(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);
        
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole(Role::where('name', 'Accountant')->first());
        app(TenantContext::class)->clear();

        Sanctum::actingAs($accountant);

        // 10. Accountant can access tenant branches only if granted appropriate tenant-level permission
        // In RbacSeeder, Accountant has 'view_multi_branch_dashboard'
        $this->withHeader('X-Branch-ID', $branch->id)
            ->getJson('/api/branch-test')
            ->assertStatus(200);
            
        // If we remove the permission, access should fail
        $accountant->roles()->detach();
        $this->withHeader('X-Branch-ID', $branch->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403)
            ->assertSee('User not assigned to this branch');
    }

    /** @test */
    public function test_user_without_assignment_and_without_permission_is_blocked(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create();
        $user = User::factory()->create(); // No role, no assignment
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        // 11. User without branch assignment and without tenant-level permission receives 403
        $this->withHeader('X-Branch-ID', $branch->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403)
            ->assertSee('User not assigned to this branch');
    }

    /** @test */
    public function test_deactivated_user_blocked_despite_branch_assignment(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['status' => 'inactive']);
        $user->assignToBranch($branch);
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        // 13. Deactivated user cannot access assigned branch
        $this->withHeader('X-Branch-ID', $branch->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403)
            ->assertSee('User account is deactivated');
    }

    /** @test */
    public function test_user_under_inactive_tenant_blocked_despite_branch_assignment(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'inactive']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        $user->assignToBranch($branch);
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        // 14. User under inactive tenant cannot access assigned branch
        $this->withHeader('X-Branch-ID', $branch->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403)
            ->assertSee('Tenant account is inactive');
    }

    /** @test */
    public function test_user_under_suspended_tenant_blocked_despite_branch_assignment(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'suspended']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        $user->assignToBranch($branch);
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        // 15. User under suspended tenant cannot access assigned branch
        $this->withHeader('X-Branch-ID', $branch->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403)
            ->assertSee('Tenant account is suspended');
    }
}
