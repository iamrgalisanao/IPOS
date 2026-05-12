<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    /** @test */
    public function test_it_automatically_scopes_queries_to_the_active_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // Set context to create records for specific tenants
        $this->setTenantContext($tenantA);
        $branchA = Branch::factory()->create(['branch_code' => 'B-A']);
        
        app(TenantContext::class)->clear();
        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create(['branch_code' => 'B-B']);

        // Test Scoping for Tenant A
        app(TenantContext::class)->clear();
        $this->setTenantContext($tenantA);
        
        $branches = Branch::all();
        $this->assertCount(1, $branches);
        $this->assertEquals($branchA->id, $branches->first()->id);

        // Test Scoping for Tenant B
        app(TenantContext::class)->clear();
        $this->setTenantContext($tenantB);
        
        $branches = Branch::all();
        $this->assertCount(1, $branches);
        $this->assertEquals($branchB->id, $branches->first()->id);
    }

    /** @test */
    public function test_it_automatically_injects_tenant_id_on_creation(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);

        // Create branch without explicit tenant_id
        $branch = Branch::create([
            'name' => 'Auto Scoped Branch',
            'branch_code' => 'AUTO-001',
        ]);

        $this->assertEquals($tenant->id, $branch->tenant_id);
    }

    /** @test */
    public function test_it_fails_if_tenant_context_is_missing_during_creation(): void
    {
        app(TenantContext::class)->clear();
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context missing for scoped model creation');

        Branch::create([
            'name' => 'Orphan Branch',
            'branch_code' => 'ORP-001',
        ]);
    }

    /** @test */
    public function test_it_blocks_manual_tenant_id_that_does_not_match_active_context(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        $this->setTenantContext($tenantA);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-tenant assignment blocked');

        Branch::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Malicious Branch',
            'branch_code' => 'MAL-001',
        ]);
    }

    /** @test */
    public function test_it_fails_loudly_if_tenant_context_is_missing_during_query(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);
        Branch::factory()->create(['branch_code' => 'B-1']);
        
        app(TenantContext::class)->clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context is required for tenant-scoped model queries');

        Branch::all();
    }
}
