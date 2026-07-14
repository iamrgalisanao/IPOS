<?php

namespace Tests\Feature\Dining;

use App\Exceptions\Dining\DiningDomainException;
use App\Exceptions\Dining\DiningTicketRevisionConflictException;
use App\Exceptions\Dining\DiningTicketTransitionException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketEvent;
use App\Models\DiningTicketVersion;
use App\Models\EmployeeTimecard;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\ServiceArea;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Dining\DiningTicketService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Values\Dining\DiningAuditPayload;
use App\Values\Dining\DiningTicketSnapshot;
use App\Values\Dining\DiningTimelinePayload;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiningTicketAuditRevisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private SalesMachineProfile $terminal;
    private ServiceArea $area;
    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->terminal = SalesMachineProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'MAIN-REG-01',
            'terminal_identifier' => 'MAIN-REG-01',
            'status' => 'active',
            'activation_status' => SalesMachineProfile::STATUS_ACTIVE,
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $cashierRole = Role::where('tenant_id', $this->tenant->id)
            ->where('name', 'Cashier')
            ->firstOrFail();
        $this->cashier->assignRole($cashierRole);
        $this->cashier->assignToBranch($this->branch);

        $this->area = ServiceArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->table = DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'service_area_id' => $this->area->id,
            'table_number' => 'T1',
            'operational_state' => DiningTable::STATE_AVAILABLE,
            'is_active' => true,
        ]);

        Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        EmployeeTimecard::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->cashier->id,
            'clocked_in_at' => now(),
            'clock_in_method' => 'pin',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_open_ticket_creates_audit_version_and_timeline_records(): void
    {
        $ticket = $this->openTicket();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DINING_TICKET_OPENED',
            'auditable_id' => $ticket->id,
            'actor_user_id' => $this->cashier->id,
        ]);
        $this->assertDatabaseHas('dining_ticket_versions', [
            'dining_ticket_id' => $ticket->id,
            'version' => 1,
            'operation' => 'opened',
            'actor_user_id' => $this->cashier->id,
            'terminal_id' => $this->terminal->id,
        ]);
        $this->assertDatabaseHas('dining_ticket_events', [
            'dining_ticket_id' => $ticket->id,
            'event_sequence' => 1,
            'event_type' => 'opened',
            'summary' => 'Ticket '.$ticket->ticket_number.' opened for Table T1.',
        ]);

        $version = DiningTicketVersion::where('dining_ticket_id', $ticket->id)->firstOrFail();
        $event = DiningTicketEvent::where('dining_ticket_id', $ticket->id)->firstOrFail();
        $audit = AuditLog::where('action', 'DINING_TICKET_OPENED')->firstOrFail();

        $this->assertSame(1, $version->after_snapshot['schema_version']);
        $this->assertSame(1, $event->payload['schema_version']);
        $this->assertSame($this->cashier->id, $audit->after_values['actor_user_id']);
        $this->assertSame($this->terminal->id, $audit->after_values['terminal_id']);
        $this->assertSame(1, $ticket->ticket_revision);
    }

    public function test_idempotent_replay_does_not_duplicate_audit_version_or_timeline_records(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'client_request_uuid' => $uuid,
            'guest_count' => 2,
        ];

        $first = app(DiningTicketService::class)->openTicket($this->table, $payload, $this->cashier, $this->terminal);
        $second = app(DiningTicketService::class)->openTicket($this->table, $payload, $this->cashier, $this->terminal);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue((bool) $second->idempotent_replay);
        $this->assertSame(1, AuditLog::where('action', 'DINING_TICKET_OPENED')->count());
        $this->assertSame(1, DiningTicketVersion::where('dining_ticket_id', $first->id)->count());
        $this->assertSame(1, DiningTicketEvent::where('dining_ticket_id', $first->id)->count());
    }

    public function test_status_transition_creates_atomic_version_and_timeline_record(): void
    {
        $ticket = $this->openTicket();
        $secondTerminal = SalesMachineProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'PATIO-REG-02',
            'terminal_identifier' => 'PATIO-REG-02',
            'status' => 'active',
            'activation_status' => SalesMachineProfile::STATUS_ACTIVE,
        ]);

        $settling = app(DiningTicketService::class)->transitionStatus(
            $ticket,
            DiningTicket::STATUS_SETTLING,
            $this->cashier,
            1,
            [],
            $secondTerminal
        );

        $this->assertSame(2, $settling->ticket_revision);
        $this->assertDatabaseHas('dining_ticket_versions', [
            'dining_ticket_id' => $ticket->id,
            'version' => 2,
            'operation' => 'status_changed',
            'terminal_id' => $secondTerminal->id,
        ]);
        $this->assertDatabaseHas('dining_ticket_events', [
            'dining_ticket_id' => $ticket->id,
            'event_sequence' => 2,
            'event_type' => 'status_changed',
            'summary' => 'Ticket status changed from open to settling.',
            'terminal_id' => $secondTerminal->id,
        ]);

        $version = DiningTicketVersion::where('dining_ticket_id', $ticket->id)
            ->where('version', 2)
            ->firstOrFail();
        $this->assertSame(1, $version->before_snapshot['schema_version']);
        $this->assertSame(1, $version->after_snapshot['schema_version']);
        $this->assertSame(2, $version->after_snapshot['ticket_revision']);

        $audit = AuditLog::where('action', 'DINING_TICKET_STATUS_CHANGED')->firstOrFail();
        $this->assertSame($this->cashier->id, $audit->actor_user_id);
        $this->assertSame($secondTerminal->id, $audit->after_values['terminal_id']);
    }

    public function test_failed_transition_writes_no_partial_audit_version_or_timeline_records(): void
    {
        $ticket = $this->openTicket();

        try {
            app(DiningTicketService::class)->transitionStatus($ticket, DiningTicket::STATUS_CLOSED, $this->cashier, 1);
            $this->fail('Expected transition exception.');
        } catch (DiningTicketTransitionException $exception) {
            $this->assertSame('DINING_TICKET_INVALID_TRANSITION', $exception->errorCode());
        }

        $this->assertSame(1, $ticket->fresh()->ticket_revision);
        $this->assertSame(1, DiningTicketVersion::where('dining_ticket_id', $ticket->id)->count());
        $this->assertSame(1, DiningTicketEvent::where('dining_ticket_id', $ticket->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'DINING_TICKET_STATUS_CHANGED')->count());
    }

    public function test_guest_count_change_uses_shared_audit_revision_timeline_pipeline(): void
    {
        $ticket = $this->openTicket();

        $changed = app(DiningTicketService::class)->changeGuestCount(
            $ticket,
            4,
            $this->cashier,
            $this->terminal,
            1,
            'Guests joined table'
        );

        $this->assertSame(4, $changed->guest_count);
        $this->assertSame(2, $changed->ticket_revision);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DINING_GUEST_COUNT_CHANGED',
            'auditable_id' => $ticket->id,
        ]);
        $this->assertDatabaseHas('dining_ticket_versions', [
            'dining_ticket_id' => $ticket->id,
            'version' => 2,
            'operation' => 'guest_count_changed',
            'reason' => 'Guests joined table',
        ]);
        $this->assertDatabaseHas('dining_ticket_events', [
            'dining_ticket_id' => $ticket->id,
            'event_sequence' => 2,
            'event_type' => 'guest_count_changed',
            'summary' => 'Guest count changed from 2 to 4.',
        ]);
    }

    public function test_guest_count_noop_and_failures_do_not_write_records(): void
    {
        $ticket = $this->openTicket();

        $noop = app(DiningTicketService::class)->changeGuestCount(
            $ticket,
            2,
            $this->cashier,
            $this->terminal,
            1
        );

        $this->assertSame(1, $noop->ticket_revision);
        $this->assertSame(1, DiningTicketVersion::where('dining_ticket_id', $ticket->id)->count());
        $this->assertSame(1, DiningTicketEvent::where('dining_ticket_id', $ticket->id)->count());

        try {
            app(DiningTicketService::class)->changeGuestCount($ticket, 0, $this->cashier, $this->terminal, 1);
            $this->fail('Expected domain exception.');
        } catch (DiningDomainException $exception) {
            $this->assertSame('DINING_GUEST_COUNT_INVALID', $exception->errorCode());
        }

        try {
            app(DiningTicketService::class)->changeGuestCount($ticket, 3, $this->cashier, $this->terminal, 99);
            $this->fail('Expected revision exception.');
        } catch (DiningTicketRevisionConflictException $exception) {
            $this->assertSame('DINING_TICKET_REVISION_CONFLICT', $exception->errorCode());
        }

        $this->assertSame(1, DiningTicketVersion::where('dining_ticket_id', $ticket->id)->count());
        $this->assertSame(1, DiningTicketEvent::where('dining_ticket_id', $ticket->id)->count());
    }

    public function test_event_and_version_records_are_append_only(): void
    {
        $ticket = $this->openTicket();
        $version = DiningTicketVersion::where('dining_ticket_id', $ticket->id)->firstOrFail();
        $event = DiningTicketEvent::where('dining_ticket_id', $ticket->id)->firstOrFail();

        try {
            $version->update(['operation' => 'tampered']);
            $this->fail('Expected append-only version update failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Expected append-only event delete failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            $ticket->delete();
            $this->fail('Expected ticket delete to be blocked by append-only history references.');
        } catch (QueryException $exception) {
            $this->assertNotNull($exception->getCode());
        }

        $this->assertDatabaseHas('dining_ticket_versions', ['id' => $version->id]);
        $this->assertDatabaseHas('dining_ticket_events', ['id' => $event->id]);
    }

    public function test_payload_value_objects_are_versioned_and_sanitized(): void
    {
        $ticket = $this->openTicket();

        $snapshot = DiningTicketSnapshot::fromTicket($ticket, [
            'payment_token' => 'secret-token',
            'notes' => str_repeat('x', 600),
        ])->toArray();
        $audit = DiningAuditPayload::fromTicket($ticket)->toArray();
        $timeline = DiningTimelinePayload::fromTicket($ticket)->toArray();

        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame(1, $audit['schema_version']);
        $this->assertSame(1, $timeline['schema_version']);
        $this->assertArrayNotHasKey('payment_token', $snapshot);
        $this->assertSame(500, mb_strlen($snapshot['notes']));
    }

    private function openTicket(): DiningTicket
    {
        return app(DiningTicketService::class)->openTicket(
            $this->table,
            [
                'client_request_uuid' => (string) Str::uuid(),
                'guest_count' => 2,
            ],
            $this->cashier,
            $this->terminal,
        );
    }
}
