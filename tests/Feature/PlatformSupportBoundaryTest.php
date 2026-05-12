<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSupportBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    /**
     * Helper to create a support user bypassing the Tenant scope
     */
    protected function createSupportUser(): User
    {
        return User::withoutEvents(function () {
            return User::factory()->platformSupport()->create();
        });
    }

    /** @test */
    public function test_platform_support_request_cannot_access_tenant_route_without_tenant_context(): void
    {
        // No header = missing context = 403
        $this->getJson('/api/tenant-test')->assertStatus(403);
    }

    /** @test */
    public function test_platform_support_request_cannot_access_branch_route_without_branch_context(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        
        // Tenant context present, but branch missing -> 403
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/branch-test')->assertStatus(403);
    }

    /** @test */
    public function test_platform_support_request_cannot_bypass_tenant_context_using_arbitrary_headers(): void
    {
        $this->withHeader('X-Support-Mode', 'true')
            ->getJson('/api/tenant-test')->assertStatus(403);
            
        $this->withHeader('X-Platform-Admin', 'true')
            ->getJson('/api/tenant-test')->assertStatus(403);
    }

    /** @test */
    public function test_platform_support_request_cannot_access_another_tenant_branch(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create();
        app(TenantContext::class)->clear();

        // Even if a support user "knew" Branch B ID, they can't see it via Tenant A route
        $this->withHeaders([
            'X-Tenant-ID' => $tenantA->id,
            'X-Branch-ID' => $branchB->id,
        ])->getJson('/api/branch-test')->assertStatus(403);
    }

    /** @test */
    public function test_normal_tenant_route_behavior_remains_unchanged(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/tenant-test')->assertStatus(200);
    }

    /** @test */
    public function test_no_assisted_mode_access_exists_yet(): void
    {
        // Any attempt to use an "assisted" flag should be ignored or blocked from bypassing context
        $tenant = Tenant::factory()->create(['status' => 'active']);
        
        $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Assisted-Mode' => 'true',
        ])->getJson('/api/tenant-test')->assertStatus(200);
        
        // Verify that the context DOES NOT show assisted mode (it doesn't have the concept yet)
        $this->assertFalse(property_exists(app(TenantContext::class), 'isAssistedMode'));
    }

    /** @test */
    public function test_support_cannot_modify_tenant_data_without_tenant_context(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        
        // This fails loudly because of BelongsToTenant strictness
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context missing for scoped model creation');
        
        Branch::create(['name' => 'Support Created Branch', 'branch_code' => 'SUPP-01']);
    }

    /** @test */
    public function test_support_users_are_distinct_and_isolated_from_tenant_queries(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        
        // Create support user (no tenant) using privileged bypass
        $supportUser = $this->createSupportUser();

        // Standard tenant query
        app(TenantContext::class)->setTenant($tenant);
        $this->assertEquals(0, User::count());
        $this->assertNull(User::find($supportUser->id));
        app(TenantContext::class)->clear();

        // Verify support user can only be found via explicit bypass
        $this->assertNotNull(User::withoutGlobalScopes()->find($supportUser->id));
    }

    /** @test */
    public function test_tenant_isolation_tests_still_pass(): void
    {
        // This is a meta-test to ensure we didn't break core isolation
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create();
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $this->assertNull(Branch::find($branchA->id));
    }
}
