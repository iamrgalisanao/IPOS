<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantContextResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_protected_routes_return_403_if_tenant_header_is_missing(): void
    {
        $response = $this->getJson('/api/tenant-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tenant context missing.');
    }

    /** @test */
    public function test_protected_routes_return_403_if_tenant_id_is_invalid(): void
    {
        $response = $this->withHeader('X-Tenant-ID', '00000000-0000-0000-0000-000000000000')
            ->getJson('/api/tenant-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Invalid tenant context.');
    }

    /** @test */
    public function test_protected_routes_return_403_if_tenant_id_is_malformed(): void
    {
        $response = $this->withHeader('X-Tenant-ID', 'not-a-uuid')
            ->getJson('/api/tenant-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Invalid tenant context.');
    }

    /** @test */
    public function test_protected_routes_return_403_if_tenant_is_inactive(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'inactive']);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/tenant-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tenant account is inactive.');
    }

    /** @test */
    public function test_protected_routes_return_403_if_tenant_is_suspended(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'suspended']);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/tenant-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tenant account is suspended.');
    }

    /** @test */
    public function test_protected_routes_resolve_tenant_from_valid_header(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Test Business',
            'status' => 'active',
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/tenant-test');

        $response->assertStatus(200)
            ->assertJson([
                'tenant_id' => $tenant->id,
                'name' => 'Test Business',
            ]);
    }

    /** @test */
    public function test_public_routes_are_not_affected_by_tenant_middleware(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    /** @test */
    public function test_tenant_context_returns_correct_id_and_does_not_leak(): void
    {
        $tenantA = Tenant::factory()->create(['name' => 'Tenant A', 'status' => 'active']);
        $tenantB = Tenant::factory()->create(['name' => 'Tenant B', 'status' => 'active']);

        // Request A
        $this->withHeader('X-Tenant-ID', $tenantA->id)
            ->getJson('/api/tenant-test')
            ->assertJsonPath('tenant_id', $tenantA->id)
            ->assertJsonPath('name', 'Tenant A');

        // Request B (Context should be B, not A)
        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson('/api/tenant-test')
            ->assertJsonPath('tenant_id', $tenantB->id)
            ->assertJsonPath('name', 'Tenant B');
    }
}
