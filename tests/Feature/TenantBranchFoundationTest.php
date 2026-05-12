<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class TenantBranchFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    /** @test */
    public function test_a_tenant_can_be_created(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Acme Corp',
        ]);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Acme Corp',
        ]);
        
        $this->assertIsString($tenant->id);
        $this->assertEquals(36, strlen($tenant->id)); // UUID length
    }

    /** @test */
    public function test_a_branch_can_be_created_under_a_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);
        
        $branch = Branch::factory()->create([
            'name' => 'Manila Branch',
            'branch_code' => 'MNL-001',
        ]);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'tenant_id' => $tenant->id,
            'branch_code' => 'MNL-001',
        ]);

        $this->assertEquals($tenant->id, $branch->tenant->id);
    }

    /** @test */
    public function test_a_branch_cannot_exist_without_a_tenant(): void
    {
        // Now it throws RuntimeException from the trait instead of QueryException from DB
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context missing for scoped model creation');
        
        Branch::create([
            'name' => 'Orphan Branch',
            'branch_code' => 'ORP-001',
        ]);
    }

    /** @test */
    public function test_branch_code_is_unique_per_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);
        
        Branch::factory()->create([
            'branch_code' => 'CODE-1',
        ]);

        // This will trigger DB unique constraint (QueryException) because context is valid
        $this->expectException(QueryException::class);
        
        Branch::factory()->create([
            'branch_code' => 'CODE-1',
        ]);
    }

    /** @test */
    public function test_same_branch_code_can_exist_under_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        $this->setTenantContext($tenantA);
        $branchA = Branch::factory()->create([
            'branch_code' => 'DUPE-1',
        ]);

        app(TenantContext::class)->clear();
        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create([
            'branch_code' => 'DUPE-1',
        ]);

        $this->assertDatabaseHas('branches', ['id' => $branchA->id, 'branch_code' => 'DUPE-1']);
        $this->assertDatabaseHas('branches', ['id' => $branchB->id, 'branch_code' => 'DUPE-1']);
    }
}
