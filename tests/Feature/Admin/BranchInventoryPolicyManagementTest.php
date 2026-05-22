<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchInventoryPolicyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $owner;
    protected User $branchManager;
    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        
        app(TenantContext::class)->setTenant($this->tenant);
        $this->branchA = Branch::factory()->create([
            'tenant_id' => $this->tenant->id, 
            'status' => 'active', 
            'name' => 'Branch A',
            'inventory_deduction_policy' => 'strict_block'
        ]);
        $this->branchB = Branch::factory()->create([
            'tenant_id' => $this->tenant->id, 
            'status' => 'active', 
            'name' => 'Branch B',
            'inventory_deduction_policy' => 'strict_block'
        ]);
        
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branchA);

        $this->branchManager = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->branchManager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->branchManager->assignToBranch($this->branchA);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branchA);
        
        app(TenantContext::class)->clear();
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get(route('admin.branches.index'))
            ->assertRedirect(route('login'));
    }

    public function test_cashier_is_forbidden_from_viewing_branches_list(): void
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('admin.branches.index'))
            ->assertForbidden();
    }

    public function test_branch_manager_can_view_branches_list(): void
    {
        $response = $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('admin.branches.index'));

        $response->assertOk();
    }

    public function test_authorized_user_can_update_inventory_policy_to_valid_value(): void
    {
        $response = $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('admin.branches.inventory-policy.update', $this->branchA->id), [
                'inventory_deduction_policy' => 'allow_negative_with_warning'
            ]);

        $response->assertRedirect();
        
        // Assert updated in database under tenant context
        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertEquals('allow_negative_with_warning', $this->branchA->refresh()->inventory_deduction_policy);
    }

    public function test_invalid_policy_fails_validation(): void
    {
        $response = $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('admin.branches.inventory-policy.update', $this->branchA->id), [
                'inventory_deduction_policy' => 'corrupt_policy'
            ]);

        $response->assertSessionHasErrors(['inventory_deduction_policy']);
        
        // Assert policy remains unchanged
        app(TenantContext::class)->setTenant($this->tenant);
        $this->assertEquals('strict_block', $this->branchA->refresh()->inventory_deduction_policy);
    }

    public function test_branch_policy_update_enforces_tenant_isolation(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'active',
            'inventory_deduction_policy' => 'strict_block'
        ]);

        $response = $this->actingAs($this->branchManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('admin.branches.inventory-policy.update', $otherBranch->id), [
                'inventory_deduction_policy' => 'allow_negative_with_warning'
            ]);

        $response->assertNotFound();
    }
}
