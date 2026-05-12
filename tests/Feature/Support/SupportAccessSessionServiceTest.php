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
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SupportAccessSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        app(SupportContext::class)->clear();
    }

    public function test_start_session_creates_active_support_session_and_sets_support_context(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = $this->createBranch($tenant);
        $supportUser = User::factory()->platformSupport()->create();
        $service = app(SupportAccessSessionService::class);

        $session = $service->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            reason: 'Investigate tenant sync readiness.',
            branch: $branch,
            metadata: ['ticket' => 'SUP-132']
        );

        $this->assertSame(SupportAccessSession::STATUS_ACTIVE, $session->status);
        $this->assertSame($supportUser->id, $session->support_user_id);
        $this->assertSame($tenant->id, $session->tenant_id);
        $this->assertSame($branch->id, $session->branch_id);
        $this->assertSame('Investigate tenant sync readiness.', $session->reason);
        $this->assertSame(['ticket' => 'SUP-132'], $session->metadata);
        $this->assertTrue($session->expires_at->gt(now()->addMinutes(59)));
        $this->assertTrue($session->expires_at->lt(now()->addMinutes(61)));

        $supportContext = app(SupportContext::class);

        $this->assertTrue($supportContext->hasSession());
        $this->assertSame($session->id, $supportContext->getSessionId());
        $this->assertSame($tenant->id, $supportContext->getTenantId());
        $this->assertSame($branch->id, $supportContext->getBranchId());
        $this->assertSame($supportUser->id, $supportContext->getSupportUserId());

        $this->assertFalse(app(TenantContext::class)->hasTenant());
        $this->assertFalse(app(BranchContext::class)->hasBranch());
    }

    public function test_start_session_rejects_non_support_users(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantUser = $this->createTenantUser($tenant);
        $service = app(SupportAccessSessionService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Support access sessions are limited to platform support users.');

        $service->startSession($tenantUser, $tenant, 'Tenant user should not open support session.');
    }

    public function test_start_session_rejects_blank_reason(): void
    {
        $tenant = Tenant::factory()->create();
        $supportUser = User::factory()->platformSupport()->create();
        $service = app(SupportAccessSessionService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Support session reason is required.');

        $service->startSession($supportUser, $tenant, '   ');
    }

    public function test_start_session_rejects_cross_tenant_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $crossTenantBranch = $this->createBranch($otherTenant);
        $supportUser = User::factory()->platformSupport()->create();
        $service = app(SupportAccessSessionService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Support session branch must belong to the target tenant.');

        $service->startSession($supportUser, $tenant, 'Cross-tenant branch should be blocked.', $crossTenantBranch);
    }

    public function test_start_session_rejects_past_expiry(): void
    {
        $tenant = Tenant::factory()->create();
        $supportUser = User::factory()->platformSupport()->create();
        $service = app(SupportAccessSessionService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Support session expiry must be in the future.');

        $service->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            reason: 'Past expiry check.',
            expiresAt: now()->subMinute()
        );
    }

    public function test_resolve_active_session_marks_expired_session_and_assert_active_session_fails(): void
    {
        $session = SupportAccessSession::factory()->create([
            'expires_at' => now()->subMinute(),
        ]);
        $service = app(SupportAccessSessionService::class);

        $this->assertNull($service->resolveActiveSession($session->id));

        $expiredSession = $session->fresh();

        $this->assertSame(SupportAccessSession::STATUS_EXPIRED, $expiredSession->status);
        $this->assertNotNull($expiredSession->ended_at);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Active support access session not found.');

        $service->assertActiveSession($session->id);
    }

    public function test_end_and_revoke_session_transition_status_and_clear_support_context(): void
    {
        $service = app(SupportAccessSessionService::class);
        $supportContext = app(SupportContext::class);

        $activeSession = SupportAccessSession::factory()->create();
        $supportContext->setSession($activeSession);

        $endedSession = $service->endSession($activeSession->id);

        $this->assertSame(SupportAccessSession::STATUS_ENDED, $endedSession->status);
        $this->assertNotNull($endedSession->ended_at);
        $this->assertFalse($supportContext->hasSession());

        $revokedSession = SupportAccessSession::factory()->create();
        $supportContext->setSession($revokedSession);

        $revokedSession = $service->revokeSession($revokedSession);

        $this->assertSame(SupportAccessSession::STATUS_REVOKED, $revokedSession->status);
        $this->assertNotNull($revokedSession->ended_at);
        $this->assertFalse($supportContext->hasSession());
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
}