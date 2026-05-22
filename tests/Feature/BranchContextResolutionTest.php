<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchContextResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_branch_routes_return_403_if_tenant_context_is_missing(): void
    {
        // Even if we provide branch ID, if tenant middleware hasn't run or failed
        $response = $this->withHeader('X-Branch-ID', 'any-id')
            ->getJson('/api/branch-test');

        // Middleware order: tenant runs first and fails with 403
        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tenant context missing.');
    }

    /** @test */
    public function test_branch_routes_return_403_if_branch_header_is_missing(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/branch-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Branch context missing. Please select a branch from the dashboard.');
    }

    /** @test */
    public function test_branch_routes_resolve_branch_from_valid_header(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        
        // Use TenantContext to bypass scoping for creation in test
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => $branch->id,
        ])->getJson('/api/branch-test');

        $response->assertStatus(200)
            ->assertJson([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'branch_name' => 'Main Branch',
            ]);
    }

    /** @test */
    public function test_branch_routes_deny_access_to_branch_belonging_to_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // Create Branch for Tenant B
        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        app(TenantContext::class)->clear();

        // Try to access Branch B using Tenant A context
        $response = $this->withHeaders([
            'X-Tenant-ID' => $tenantA->id,
            'X-Branch-ID' => $branchB->id,
        ])->getJson('/api/branch-test');

        // Should return 403 because Branch B is invisible to Tenant A
        $response->assertStatus(403)
            ->assertJsonPath('message', 'Invalid branch context or access denied.');
    }

    /** @test */
    public function test_branch_routes_return_403_if_branch_id_is_invalid(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => '00000000-0000-0000-0000-000000000000',
        ])->getJson('/api/branch-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Invalid branch context or access denied.');
    }

    /** @test */
    public function test_branch_routes_return_403_if_branch_id_is_malformed(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => 'not-a-uuid',
        ])->getJson('/api/branch-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Invalid branch context or access denied.');
    }

    /** @test */
    public function test_branch_routes_return_403_if_branch_is_inactive(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['status' => 'inactive']);
        app(TenantContext::class)->clear();

        $response = $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => $branch->id,
        ])->getJson('/api/branch-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Branch account is inactive.');
    }

    /** @test */
    public function test_branch_context_does_not_leak_and_returns_correct_id(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branchA = Branch::factory()->create(['name' => 'Branch A', 'status' => 'active']);
        $branchB = Branch::factory()->create(['name' => 'Branch B', 'status' => 'active']);
        app(TenantContext::class)->clear();

        // Request A
        $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => $branchA->id,
        ])->getJson('/api/branch-test')
          ->assertJsonPath('branch_id', $branchA->id)
          ->assertJsonPath('branch_name', 'Branch A');

        // Request B
        $this->withHeaders([
            'X-Tenant-ID' => $tenant->id,
            'X-Branch-ID' => $branchB->id,
        ])->getJson('/api/branch-test')
          ->assertJsonPath('branch_id', $branchB->id)
          ->assertJsonPath('branch_name', 'Branch B');
    }

    /** @test */
    public function test_tenant_level_protected_routes_still_work_without_branch_context(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/tenant-test');

        $response->assertStatus(200);
    }
}
