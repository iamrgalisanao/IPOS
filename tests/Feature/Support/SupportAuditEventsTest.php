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

class SupportAuditEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        app(SupportContext::class)->clear();
    }

    public function test_support_session_lifecycle_events_are_recorded_for_current_supported_transitions(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = $this->createBranch($tenant);
        $supportUser = User::factory()->platformSupport()->create();
        $service = app(SupportAccessSessionService::class);

        $session = $service->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            branch: $branch,
            reason: 'Lifecycle event verification.',
            maskingProfile: SupportPayloadMasker::PROFILE_STRICT
        );

        $service->endSession($session);

        $this->assertDatabaseHas('support_audit_events', [
            'event_type' => 'support.session.started',
            'support_session_id' => $session->id,
            'actor_id' => $supportUser->id,
            'status' => 'allowed',
        ]);

        $this->assertDatabaseHas('support_audit_events', [
            'event_type' => 'support.session.ended',
            'support_session_id' => $session->id,
            'actor_id' => $supportUser->id,
            'status' => 'allowed',
        ]);

        $startedEvent = SupportAuditEvent::where('event_type', 'support.session.started')->firstOrFail();

        $this->assertSame(SupportPayloadMasker::REDACTED, $startedEvent->metadata['tenant_id']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $startedEvent->metadata['branch_id']);
        $this->assertSame(SupportPayloadMasker::PROFILE_STRICT, $startedEvent->metadata['masking_profile']);
        $this->assertSame('Lifecycle event verification.', $startedEvent->metadata['reason']);
    }

    public function test_support_route_access_records_masked_audit_event(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        $branch = $this->createBranch($tenant);
        $supportUser = User::factory()->platformSupport()->create();
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            branch: $branch,
            reason: 'Access event verification.',
            maskingProfile: SupportPayloadMasker::PROFILE_STRICT
        );

        $counts = $this->domainCounts();

        $this->actingAs($supportUser)
            ->getJson(route('support.assisted.probe', $session))
            ->assertOk();

        $routeEvent = SupportAuditEvent::where('event_type', 'support.route.accessed')->latest()->firstOrFail();
        $sessionEvent = SupportAuditEvent::where('event_type', 'support.session.accessed')->latest()->firstOrFail();

        $this->assertSame('support.assisted.probe', $routeEvent->route_name);
        $this->assertSame('GET', $routeEvent->method);
        $this->assertSame('allowed', $routeEvent->status);
        $this->assertSame(SupportPayloadMasker::REDACTED, $routeEvent->metadata['response']['access_token']);
        $this->assertSame(SupportPayloadMasker::REDACTED_PAYLOAD, $routeEvent->metadata['response']['metadata']['provider_payload']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $routeEvent->metadata['response']['gross_total']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $routeEvent->metadata['response']['tenant_id']);
        $this->assertSame('allowed', $sessionEvent->status);

        $this->assertDomainCountsUnchanged($counts);
        Http::assertNothingSent();
    }

    public function test_blocked_support_action_attempt_records_masked_audit_event_without_side_effects(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        $branch = $this->createBranch($tenant);
        $supportUser = User::factory()->platformSupport()->create();
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            branch: $branch,
            reason: 'Blocked action verification.'
        );

        $counts = $this->domainCounts();

        $this->actingAs($supportUser)
            ->withHeader('Authorization', 'Bearer raw-secret-token')
            ->postJson(route('support.assisted.probe', $session), [
                'provider_payload' => ['secret' => 'hidden'],
                'gross_total' => 999.25,
                'notes' => 'Bearer raw-inline-token',
            ])
            ->assertStatus(403)
            ->assertSee('Support assisted routes are read-only.');

        $blockedEvent = SupportAuditEvent::where('event_type', 'support.action.blocked')->latest()->firstOrFail();

        $this->assertSame('blocked', $blockedEvent->status);
        $this->assertSame('POST', $blockedEvent->method);
        $this->assertSame('support.assisted.probe', $blockedEvent->route_name);
        $this->assertSame(SupportPayloadMasker::REDACTED_PAYLOAD, $blockedEvent->metadata['request']['provider_payload']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $blockedEvent->metadata['request']['gross_total']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $blockedEvent->metadata['request']['notes']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $blockedEvent->metadata['headers']['Authorization']);

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