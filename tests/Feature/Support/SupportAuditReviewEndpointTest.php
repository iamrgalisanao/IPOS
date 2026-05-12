<?php

namespace Tests\Feature\Support;

use App\Models\Branch;
use App\Models\SupportAuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Support\SupportPayloadMasker;
use App\Services\SupportAccessSessionService;
use App\Services\SupportContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportAuditReviewEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        app(SupportContext::class)->clear();
    }

    public function test_authorized_support_assisted_context_can_access_masked_audit_review_endpoint(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        $branch = $this->createBranch($tenant);
        $supportUser = User::factory()->platformSupport()->create();
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            branch: $branch,
            reason: 'Audit review endpoint test.',
            maskingProfile: SupportPayloadMasker::PROFILE_STRICT
        );

        SupportAuditEvent::create([
            'event_type' => 'support.route.accessed',
            'support_session_id' => $session->id,
            'actor_id' => $supportUser->id,
            'route_name' => 'support.assisted.probe',
            'path' => 'support/assisted/' . $session->id . '/probe',
            'method' => 'GET',
            'status' => 'allowed',
            'metadata' => [
                'Authorization' => 'Bearer raw-secret-token',
                'provider_payload' => ['secret' => 'hidden'],
                'gross_total' => 999.99,
                'support_session_id' => $session->id,
            ],
        ]);

        $otherSession = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            reason: 'Other session.'
        );

        SupportAuditEvent::create([
            'event_type' => 'support.route.accessed',
            'support_session_id' => $otherSession->id,
            'actor_id' => $supportUser->id,
            'route_name' => 'support.assisted.probe',
            'path' => 'support/assisted/' . $otherSession->id . '/probe',
            'method' => 'GET',
            'status' => 'allowed',
            'metadata' => ['safe_status' => 'other-session'],
        ]);

        $counts = $this->domainCounts();

        $response = $this->actingAs($supportUser)
            ->getJson(route('support.assisted.audit-events.index', ['supportAccessSession' => $session, 'limit' => 25]));

        $response->assertOk()
            ->assertJsonPath('meta.limit', 25)
            ->assertJsonPath('meta.mode', 'support_assisted')
            ->assertJsonPath('meta.support_session_id', $session->id)
            ->assertJsonCount(3, 'data');

        $records = $response->json('data');

        foreach ($records as $record) {
            $this->assertSame($session->id, $record['support_session_id']);
        }

        $seededRecord = collect($records)->firstWhere('event_type', 'support.route.accessed');
        $reviewRecord = collect($records)->firstWhere('event_type', 'support.audit_review.accessed');
        $sessionAccessRecord = collect($records)->firstWhere('event_type', 'support.session.accessed');

        $this->assertNotNull($seededRecord);
        $this->assertSame(SupportPayloadMasker::REDACTED, $seededRecord['metadata']['Authorization']);
        $this->assertSame(SupportPayloadMasker::REDACTED_PAYLOAD, $seededRecord['metadata']['provider_payload']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $seededRecord['metadata']['gross_total']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $seededRecord['metadata']['support_session_id']);
        $this->assertNull($reviewRecord);
        $this->assertNotNull($sessionAccessRecord);
        $this->assertSame('allowed', $sessionAccessRecord['status']);

        $persistedReviewRecord = SupportAuditEvent::where('event_type', 'support.audit_review.accessed')->latest()->firstOrFail();
        $this->assertSame('support.assisted.audit-events.index', $persistedReviewRecord->route_name);
        $this->assertSame('GET', $persistedReviewRecord->method);
        $this->assertSame('allowed', $persistedReviewRecord->status);

        $this->assertDomainCountsUnchanged($counts);
        Http::assertNothingSent();
    }

    public function test_unauthorized_or_non_support_context_cannot_access_audit_review_endpoint(): void
    {
        $tenant = Tenant::factory()->create();
        $supportUser = User::factory()->platformSupport()->create();
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            reason: 'Unauthorized access test.'
        );

        $this->get(route('support.assisted.audit-events.index', $session))
            ->assertRedirect(route('login'));

        $tenantUser = $this->createTenantUser($tenant);

        $this->actingAs($tenantUser)
            ->getJson(route('support.assisted.audit-events.index', $session))
            ->assertStatus(403)
            ->assertSee('Platform support access required.');
    }

    public function test_endpoint_enforces_safe_limit_and_remains_read_only(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        $supportUser = User::factory()->platformSupport()->create();
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            reason: 'Limit enforcement test.'
        );

        for ($index = 0; $index < 30; $index++) {
            SupportAuditEvent::create([
                'event_type' => 'support.route.accessed',
                'support_session_id' => $session->id,
                'actor_id' => $supportUser->id,
                'route_name' => 'support.assisted.probe',
                'path' => 'support/assisted/' . $session->id . '/probe',
                'method' => 'GET',
                'status' => 'allowed',
                'metadata' => ['sequence' => $index],
            ]);
        }

        $counts = $this->domainCounts();

        $this->actingAs($supportUser)
            ->getJson(route('support.assisted.audit-events.index', ['supportAccessSession' => $session, 'limit' => 999]))
            ->assertOk()
            ->assertJsonPath('meta.limit', 100)
            ->assertJsonCount(32, 'data');

        $this->actingAs($supportUser)
            ->postJson(route('support.assisted.audit-events.index', ['supportAccessSession' => $session]))
            ->assertStatus(405);

        $this->assertDomainCountsUnchanged($counts);
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

    protected function domainCounts(): array
    {
        return [
            'accounting_outbox' => DB::table('accounting_outbox')->count(),
            'sales' => DB::table('sales')->count(),
            'sale_payments' => DB::table('sale_payments')->count(),
            'branch_inventories' => DB::table('branch_inventories')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'sale_refunds' => DB::table('sale_refunds')->count(),
            'sale_voids' => DB::table('sale_voids')->count(),
        ];
    }

    protected function assertDomainCountsUnchanged(array $before): void
    {
        $this->assertSame($before['accounting_outbox'], DB::table('accounting_outbox')->count());
        $this->assertSame($before['sales'], DB::table('sales')->count());
        $this->assertSame($before['sale_payments'], DB::table('sale_payments')->count());
        $this->assertSame($before['branch_inventories'], DB::table('branch_inventories')->count());
        $this->assertSame($before['inventory_movements'], DB::table('inventory_movements')->count());
        $this->assertSame($before['sale_refunds'], DB::table('sale_refunds')->count());
        $this->assertSame($before['sale_voids'], DB::table('sale_voids')->count());
    }
}