<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantBranchIsolationFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear contexts to ensure clean start for each test
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    protected function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    protected function setBranchContext(Branch $branch): void
    {
        app(BranchContext::class)->setBranch($branch);
    }

    /** @test */
    public function test_missing_tenant_context_fails_closed_on_protected_routes(): void
    {
        $this->getJson('/api/tenant-test')->assertStatus(403);
        $this->getJson('/api/branch-test')->assertStatus(403);
    }

    /** @test */
    public function test_invalid_tenant_context_fails_closed(): void
    {
        $this->withHeader('X-Tenant-ID', '00000000-0000-0000-0000-000000000000')
            ->getJson('/api/tenant-test')
            ->assertStatus(403);
    }

    /** @test */
    public function test_inactive_suspended_tenant_fails_closed(): void
    {
        $inactive = Tenant::factory()->create(['status' => 'inactive']);
        $suspended = Tenant::factory()->create(['status' => 'suspended']);

        $this->withHeader('X-Tenant-ID', $inactive->id)
            ->getJson('/api/tenant-test')
            ->assertStatus(403);

        $this->withHeader('X-Tenant-ID', $suspended->id)
            ->getJson('/api/tenant-test')
            ->assertStatus(403);
    }

    /** @test */
    public function test_tenant_a_cannot_access_tenant_b_branch_by_direct_id(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // Create Branch for B
        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create();
        app(TenantContext::class)->clear();

        // Tenant A tries to access Branch B
        $this->withHeaders([
            'X-Tenant-ID' => $tenantA->id,
            'X-Branch-ID' => $branchB->id,
        ])->getJson('/api/branch-test')->assertStatus(403);
    }

    /** @test */
    public function test_tenant_a_cannot_update_tenant_b_branch_through_eloquent(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create(['name' => 'Original Name']);
        app(TenantContext::class)->clear();

        $this->setTenantContext($tenantA);

        // Try to update Branch B while context is A
        $foundBranchB = Branch::find($branchB->id);
        $this->assertNull($foundBranchB);

        $affected = Branch::where('id', $branchB->id)->update(['name' => 'Hacked']);
        $this->assertEquals(0, $affected);

        // Verify it didn't change
        app(TenantContext::class)->clear();
        $this->setTenantContext($tenantB);
        $this->assertEquals('Original Name', $branchB->fresh()->name);
    }

    /** @test */
    public function test_tenant_a_cannot_delete_tenant_b_branch_through_eloquent(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create();
        app(TenantContext::class)->clear();

        $this->setTenantContext($tenantA);
        
        $affected = Branch::where('id', $branchB->id)->delete();
        $this->assertEquals(0, $affected);

        // Verify it still exists
        app(TenantContext::class)->clear();
        $this->setTenantContext($tenantB);
        $this->assertDatabaseHas('branches', ['id' => $branchB->id]);
    }

    /** @test */
    public function test_missing_branch_context_on_branch_scoped_route_fails_closed(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/branch-test')
            ->assertStatus(403);
    }

    /** @test */
    public function test_invalid_branch_context_fails_closed(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => '00000000-0000-0000-0000-000000000000',
        ])->getJson('/api/branch-test')->assertStatus(403);
    }

    /** @test */
    public function test_inactive_branch_context_fails_closed(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);
        $inactiveBranch = Branch::factory()->create(['status' => 'inactive']);
        app(TenantContext::class)->clear();

        $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => $inactiveBranch->id,
        ])->getJson('/api/branch-test')->assertStatus(403);
    }

    /** @test */
    public function test_tenant_level_route_works_without_branch_context(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/tenant-test')
            ->assertStatus(200);
    }

    /** @test */
    public function test_branch_scoped_route_requires_both_tenant_and_branch_context(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);
        $branch = Branch::factory()->create(['status' => 'active']);
        app(TenantContext::class)->clear();

        // 1. No headers -> 403
        $this->getJson('/api/branch-test')->assertStatus(403);

        // 2. Tenant only -> 403 (Branch missing)
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/branch-test')->assertStatus(403);

        // 3. Branch only -> 403 (Tenant missing)
        $this->flushHeaders();
        $this->withHeader('X-Branch-ID', $branch->id)
            ->getJson('/api/branch-test')->assertStatus(403);

        // 4. Both -> 200
        $this->flushHeaders();
        $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => $branch->id,
        ])->getJson('/api/branch-test')->assertStatus(200);
    }

    /** @test */
    public function test_background_execution_without_tenant_context_fails_loudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context is required');

        Branch::all();
    }

    /** @test */
    public function test_contexts_do_not_leak_between_requests(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        $this->withHeader('X-Tenant-ID', $tenantA->id)
            ->getJson('/api/tenant-test')
            ->assertJsonPath('tenant_id', $tenantA->id);

        $this->flushHeaders();
        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson('/api/tenant-test')
            ->assertJsonPath('tenant_id', $tenantB->id);
            
        $this->flushHeaders();
        $this->getJson('/api/tenant-test')->assertStatus(403);
    }

    /** @test */
    public function test_branch_contexts_do_not_leak_between_requests(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);
        $branchA = Branch::factory()->create(['status' => 'active']);
        $branchB = Branch::factory()->create(['status' => 'active']);
        app(TenantContext::class)->clear();

        $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => $branchA->id,
        ])->getJson('/api/branch-test')->assertJsonPath('branch_id', $branchA->id);

        $this->flushHeaders();
        $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => $branchB->id,
        ])->getJson('/api/branch-test')->assertJsonPath('branch_id', $branchB->id);
            
        $this->flushHeaders();
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/branch-test')->assertStatus(403); // Missing Branch ID
    }

    /** @test */
    public function test_no_fallback_behavior_exists(): void
    {
        Tenant::factory()->count(3)->create(['status' => 'active']);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context is required');
        
        Branch::all();
    }

    /** @test */
    public function test_no_fallback_branch_behavior_exists(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);
        Branch::factory()->count(3)->create(['status' => 'active']);
        
        // Even though tenant is set and branches exist, 
        // code that expects BranchContext must fail if it's not explicitly set.
        $this->assertFalse(app(BranchContext::class)->hasBranch());
        $this->assertNull(app(BranchContext::class)->getBranch());
    }

    /** @test */
    public function test_support_style_platform_access_is_not_allowed_to_bypass_yet(): void
    {
        $this->withHeader('X-Support-Mode', 'true');
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context is required');
        
        Branch::all();
    }
}
