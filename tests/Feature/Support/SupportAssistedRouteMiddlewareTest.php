<?php

namespace Tests\Feature\Support;

use App\Models\Branch;
use App\Models\SupportAccessSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\SupportAccessSessionService;
use App\Services\SupportContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportAssistedRouteMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        app(SupportContext::class)->clear();
    }

    public function test_probe_route_requires_authenticated_support_user(): void
    {
        $session = SupportAccessSession::factory()->create();

        $this->get(route('support.assisted.probe', $session))
            ->assertRedirect(route('login'));
    }

    public function test_probe_route_requires_platform_support_user_and_ignores_support_user_tenant_identity(): void
    {
        $targetTenant = Tenant::factory()->create();
        $supportUserTenant = Tenant::factory()->create();
        $branch = $this->createBranch($targetTenant);
        $supportUser = $this->createPlatformSupportUserWithTenant($supportUserTenant);
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $targetTenant,
            reason: 'Probe assisted route tenant resolution.',
            branch: $branch
        );

        $this->actingAs($supportUser)
            ->withHeaders([
                'X-Tenant-ID' => $supportUserTenant->id,
                'X-Branch-ID' => 'ignored-branch-header',
            ])
            ->getJson(route('support.assisted.probe', $session))
            ->assertOk()
            ->assertJson([
                'support_session_id' => $session->id,
                'tenant_id' => $targetTenant->id,
                'branch_id' => $branch->id,
                'mode' => 'support_assisted',
            ]);

        $this->assertSame($targetTenant->id, app(TenantContext::class)->getTenantId());
        $this->assertSame($branch->id, app(BranchContext::class)->getBranchId());

        $tenantUser = $this->createTenantUser($targetTenant);

        $this->actingAs($tenantUser)
            ->getJson(route('support.assisted.probe', $session))
            ->assertStatus(403)
            ->assertSee('Platform support access required.');
    }

    public function test_probe_route_rejects_expired_revoked_and_ended_sessions(): void
    {
        $supportUser = User::factory()->platformSupport()->create();
        $expired = SupportAccessSession::factory()->create([
            'support_user_id' => $supportUser->id,
            'expires_at' => now()->subMinute(),
        ]);
        $revoked = SupportAccessSession::factory()->create([
            'support_user_id' => $supportUser->id,
            'status' => SupportAccessSession::STATUS_REVOKED,
            'ended_at' => now(),
        ]);
        $ended = SupportAccessSession::factory()->create([
            'support_user_id' => $supportUser->id,
            'status' => SupportAccessSession::STATUS_ENDED,
            'ended_at' => now(),
        ]);

        $this->actingAs($supportUser)
            ->getJson(route('support.assisted.probe', $expired))
            ->assertStatus(403)
            ->assertSee('Active support access session not found.');

        $this->assertSame(SupportAccessSession::STATUS_EXPIRED, $expired->fresh()->status);

        $this->actingAs($supportUser)
            ->getJson(route('support.assisted.probe', $revoked))
            ->assertStatus(403)
            ->assertSee('Active support access session not found.');

        $this->actingAs($supportUser)
            ->getJson(route('support.assisted.probe', $ended))
            ->assertStatus(403)
            ->assertSee('Active support access session not found.');
    }

    public function test_probe_route_allows_null_branch_and_sets_support_mode_context(): void
    {
        $supportUser = User::factory()->platformSupport()->create();
        $tenant = Tenant::factory()->create();
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            reason: 'Probe route without branch.'
        );

        $this->actingAs($supportUser)
            ->getJson(route('support.assisted.probe', $session))
            ->assertOk()
            ->assertJson([
                'support_session_id' => $session->id,
                'tenant_id' => $tenant->id,
                'branch_id' => null,
                'mode' => 'support_assisted',
            ]);

        $this->assertTrue(app(SupportContext::class)->hasSession());
        $this->assertSame($session->id, app(SupportContext::class)->getSessionId());
        $this->assertFalse(app(BranchContext::class)->hasBranch());
    }

    public function test_probe_route_rejects_session_owned_by_another_support_user(): void
    {
        $owner = User::factory()->platformSupport()->create();
        $otherSupportUser = User::factory()->platformSupport()->create();
        $tenant = Tenant::factory()->create();
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $owner,
            tenant: $tenant,
            reason: 'Ownership isolation test.'
        );

        $this->actingAs($otherSupportUser)
            ->getJson(route('support.assisted.probe', $session))
            ->assertStatus(403)
            ->assertSee('Support access session does not belong to the authenticated support user.');
    }

    public function test_probe_route_blocks_unsafe_methods_and_creates_no_side_effects(): void
    {
        Http::fake();

        $supportUser = User::factory()->platformSupport()->create();
        $tenant = Tenant::factory()->create();
        $branch = $this->createBranch($tenant);
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            branch: $branch,
            reason: 'Unsafe method rejection test.'
        );

        $counts = [
            'accounting_outbox' => DB::table('accounting_outbox')->count(),
            'sales' => DB::table('sales')->count(),
            'sale_payments' => DB::table('sale_payments')->count(),
            'branch_inventories' => DB::table('branch_inventories')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'sale_refunds' => DB::table('sale_refunds')->count(),
            'sale_voids' => DB::table('sale_voids')->count(),
        ];

        $this->actingAs($supportUser)
            ->postJson(route('support.assisted.probe', $session))
            ->assertStatus(403)
            ->assertSee('Support assisted routes are read-only.');

        $this->assertSame($counts['accounting_outbox'], DB::table('accounting_outbox')->count());
        $this->assertSame($counts['sales'], DB::table('sales')->count());
        $this->assertSame($counts['sale_payments'], DB::table('sale_payments')->count());
        $this->assertSame($counts['branch_inventories'], DB::table('branch_inventories')->count());
        $this->assertSame($counts['inventory_movements'], DB::table('inventory_movements')->count());
        $this->assertSame($counts['sale_refunds'], DB::table('sale_refunds')->count());
        $this->assertSame($counts['sale_voids'], DB::table('sale_voids')->count());

        Http::assertNothingSent();
    }

    protected function createBranch(Tenant $tenant): Branch
    {
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        return $branch;
    }

    protected function createTenantUser(Tenant $tenant): User
    {
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        return $user;
    }

    protected function createPlatformSupportUserWithTenant(Tenant $tenant): User
    {
        app(TenantContext::class)->setTenant($tenant);
        $user = User::factory()->create([
            'actor_type' => 'platform_support',
            'tenant_id' => $tenant->id,
        ]);
        app(TenantContext::class)->clear();

        return $user;
    }
}