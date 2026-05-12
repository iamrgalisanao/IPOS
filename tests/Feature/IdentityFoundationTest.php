<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdentityFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_tenant_user_can_be_created_under_a_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        
        $this->assertEquals($tenant->id, $user->tenant_id);
        $this->assertEquals('tenant_user', $user->actor_type);
        $this->assertEquals('active', $user->status);
    }

    /** @test */
    public function test_user_email_is_platform_unique(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($tenantA);
        User::factory()->create(['email' => 'test@ipos.com']);
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        User::factory()->create(['email' => 'test@ipos.com']);
    }

    /** @test */
    public function test_active_tenant_user_can_access_protected_tenant_route(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create();
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/authenticated-tenant-test');

        $response->assertStatus(200)
            ->assertJson([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id
            ]);
    }

    /** @test */
    public function test_deactivated_user_cannot_access_protected_tenant_route(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->deactivated()->create();
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/authenticated-tenant-test');

        $response->assertStatus(403)
            ->assertSee('User account is deactivated');
    }

    /** @test */
    public function test_user_under_inactive_tenant_cannot_access_protected_tenant_route(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'inactive']);
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create();
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/authenticated-tenant-test');

        $response->assertStatus(403)
            ->assertSee('Tenant account is inactive');
    }

    /** @test */
    public function test_authenticated_user_tenant_is_used_for_tenant_context(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create();
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        // No X-Tenant-ID header provided, should resolve from user
        $response = $this->getJson('/api/authenticated-tenant-test');

        $response->assertStatus(200)
            ->assertJson(['tenant_id' => $tenant->id]);
            
        $this->assertEquals($tenant->id, app(TenantContext::class)->getTenantId());
    }

    /** @test */
    public function test_header_cannot_override_authenticated_user_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($tenantA);
        $userA = User::factory()->create();
        app(TenantContext::class)->clear();

        Sanctum::actingAs($userA);

        // Trying to override with Tenant B header
        $response = $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson('/api/authenticated-tenant-test');

        $response->assertStatus(403)
            ->assertSee('Tenant context mismatch');
    }

    /** @test */
    public function test_platform_support_user_cannot_access_normal_tenant_route_as_tenant_user(): void
    {
        $supportUser = User::withoutEvents(function () {
            return User::factory()->platformSupport()->create();
        });

        Sanctum::actingAs($supportUser);

        $response = $this->getJson('/api/authenticated-tenant-test');

        $response->assertStatus(403)
            ->assertSee('Platform support access restricted');
    }

    /** @test */
    public function test_tenant_user_defaults_to_correct_actor_type(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        
        $user = User::factory()->create();
        
        $this->assertEquals('tenant_user', $user->actor_type);
        $this->assertEquals($tenant->id, $user->tenant_id);
    }

    /** @test */
    public function test_user_under_suspended_tenant_cannot_access_protected_tenant_route(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'suspended']);
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create();
        app(TenantContext::class)->clear();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/authenticated-tenant-test');

        $response->assertStatus(403)
            ->assertSee('Tenant account is suspended');
    }

    /** @test */
    public function test_logout_invalidates_access(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create();
        app(TenantContext::class)->clear();

        // Simulate login (token creation)
        $token = $user->createToken('test-token')->plainTextToken;

        // Verify access with token
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/authenticated-tenant-test')
            ->assertStatus(200);

        // Revoke token (logout)
        $user->tokens()->delete();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        // Verify access denied
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/authenticated-tenant-test');
        
        $response->assertStatus(401);
    }
}
