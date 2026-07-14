<?php

namespace Tests\Feature\Dining;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketTable;
use App\Models\EmployeeTimecard;
use App\Models\SalesMachineProfile;
use App\Models\ServiceArea;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Dining\DiningTicketService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiningTicketFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private User $admin;
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
        $cashierRole = \App\Models\Role::where('tenant_id', $this->tenant->id)
            ->where('name', 'Cashier')
            ->firstOrFail();
        $this->cashier->assignRole($cashierRole);
        $this->cashier->assignToBranch($this->branch);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $adminRole = \App\Models\Role::where('tenant_id', $this->tenant->id)
            ->where('name', 'Owner/Admin')
            ->firstOrFail();
        $this->admin->assignRole($adminRole);
        $this->admin->assignToBranch($this->branch);

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

        config([
            'app.enforce_terminal_binding' => true,
            'app.enforce_timecards' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_cashier_can_open_online_ticket_for_available_table(): void
    {
        $uuid = (string) Str::uuid();

        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => $uuid,
                'guest_count' => 2,
                'notes' => 'Window side',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('dining_ticket.status', DiningTicket::STATUS_OPEN);
        $response->assertJsonPath('dining_ticket.guest_count', 2);
        $response->assertJsonPath('dining_ticket.ticket_revision', 1);
        $response->assertJsonPath('dining_ticket.grand_total_centavos', 0);
        $response->assertJsonPath('dining_ticket.primary_table.id', $this->table->id);

        $ticketId = $response->json('dining_ticket.id');

        $this->assertDatabaseHas('dining_tickets', [
            'id' => $ticketId,
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->cashier->id,
            'terminal_id' => $this->terminal->id,
            'ticket_revision' => 1,
            'client_request_uuid' => $uuid,
        ]);
        $this->assertDatabaseHas('dining_ticket_tables', [
            'dining_ticket_id' => $ticketId,
            'dining_table_id' => $this->table->id,
            'role' => DiningTicketTable::ROLE_PRIMARY,
            'detached_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DINING_TICKET_OPENED',
            'auditable_id' => $ticketId,
        ]);

        $audit = AuditLog::where('action', 'DINING_TICKET_OPENED')->firstOrFail();
        $this->assertSame(1, $audit->after_values['schema_version']);
    }

    public function test_open_ticket_is_idempotent_for_same_uuid_and_payload(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'dining_table_id' => $this->table->id,
            'client_request_uuid' => $uuid,
            'guest_count' => 2,
        ];

        $first = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), $payload);

        $first->assertCreated();

        $second = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), $payload);

        $second->assertOk();
        $second->assertJsonPath('dining_ticket.id', $first->json('dining_ticket.id'));
        $second->assertJsonPath('dining_ticket.idempotent_replay', true);

        $this->assertSame(1, DiningTicket::count());
        $this->assertSame(1, DiningTicketTable::count());
        $this->assertSame(1, AuditLog::where('action', 'DINING_TICKET_OPENED')->count());
    }

    public function test_open_ticket_rejects_idempotency_drift(): void
    {
        $uuid = (string) Str::uuid();

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => $uuid,
                'guest_count' => 2,
            ])
            ->assertCreated();

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => $uuid,
                'guest_count' => 5,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_DRIFT');
    }

    public function test_opening_second_active_ticket_for_table_is_blocked(): void
    {
        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DINING_TABLE_ALREADY_HAS_ACTIVE_TICKET');

        $this->assertSame(1, DiningTicket::count());
        $this->assertSame(1, DiningTicketTable::count());
    }

    public function test_open_ticket_rejects_unavailable_tables(): void
    {
        $this->area->update(['is_active' => false]);

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SERVICE_AREA_INACTIVE');

        $this->area->update(['is_active' => true]);
        $this->table->update(['is_active' => false]);

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DINING_TABLE_INACTIVE');

        $this->table->update([
            'is_active' => true,
            'operational_state' => DiningTable::STATE_RESERVED,
        ]);

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DINING_TABLE_NOT_AVAILABLE')
            ->assertJsonPath('operational_state', DiningTable::STATE_RESERVED);
    }

    public function test_cross_branch_table_is_hidden(): void
    {
        $otherBranch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $otherArea = ServiceArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
        ]);
        $otherTable = DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'service_area_id' => $otherArea->id,
        ]);

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $otherTable->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertNotFound();
    }

    public function test_missing_terminal_and_timecard_are_rejected(): void
    {
        $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'TERMINAL_CONTEXT_INVALID');

        EmployeeTimecard::query()->delete();

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'TIMECARD_REQUIRED');
    }

    public function test_status_transition_rules_and_revision_conflicts(): void
    {
        $ticket = $this->openTicketThroughService();

        $service = app(DiningTicketService::class);
        $settling = $service->transitionStatus($ticket, DiningTicket::STATUS_SETTLING, $this->cashier, 1);
        $this->assertSame(DiningTicket::STATUS_SETTLING, $settling->status);
        $this->assertSame(2, $settling->ticket_revision);

        $this->expectException(\App\Exceptions\Dining\DiningTicketRevisionConflictException::class);
        $service->transitionStatus($settling, DiningTicket::STATUS_CLOSED, $this->cashier, 1);
    }

    public function test_settling_ticket_can_reopen_only_with_checkout_failure_context(): void
    {
        $ticket = $this->openTicketThroughService();
        $service = app(DiningTicketService::class);
        $settling = $service->transitionStatus($ticket, DiningTicket::STATUS_SETTLING, $this->cashier, 1);

        try {
            $service->transitionStatus($settling, DiningTicket::STATUS_OPEN, $this->cashier, 2);
            $this->fail('Expected transition exception.');
        } catch (\App\Exceptions\Dining\DiningTicketTransitionException $exception) {
            $this->assertSame('DINING_TICKET_INVALID_TRANSITION', $exception->errorCode());
        }

        $reopened = $service->transitionStatus($settling->fresh(), DiningTicket::STATUS_OPEN, $this->cashier, 2, [
            'reason' => 'checkout_failed',
        ]);

        $this->assertSame(DiningTicket::STATUS_OPEN, $reopened->status);
        $this->assertSame(3, $reopened->ticket_revision);
    }

    public function test_illegal_transition_does_not_increment_revision_or_change_table_state(): void
    {
        $ticket = $this->openTicketThroughService();

        try {
            app(DiningTicketService::class)->transitionStatus($ticket, DiningTicket::STATUS_CLOSED, $this->cashier, 1);
            $this->fail('Expected transition exception.');
        } catch (\App\Exceptions\Dining\DiningTicketTransitionException $exception) {
            $this->assertSame('DINING_TICKET_INVALID_TRANSITION', $exception->errorCode());
        }

        $this->assertSame(1, $ticket->fresh()->ticket_revision);
        $this->assertSame(DiningTable::STATE_AVAILABLE, $this->table->fresh()->operational_state);
    }

    public function test_closed_historical_mapping_blocks_delete_but_not_table_reuse(): void
    {
        $ticket = $this->openTicketThroughService();
        $settling = app(DiningTicketService::class)->transitionStatus($ticket, DiningTicket::STATUS_SETTLING, $this->cashier, 1);
        app(DiningTicketService::class)->transitionStatus($settling, DiningTicket::STATUS_CLOSED, $this->cashier, 2);

        $this->assertFalse($ticket->fresh()->activeTableMappings()->exists());
        $this->assertFalse($this->table->fresh()->activeTicketMappings()->exists());

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.service-areas.tables.destroy', [$this->area, $this->table]))
            ->assertStatus(409);

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertCreated();
    }

    public function test_active_ticket_blocks_table_and_service_area_deactivation(): void
    {
        $this->openTicketThroughService();

        $this->actingAs($this->admin)
            ->patchJson(route('admin.service-areas.tables.activation', [$this->area, $this->table]), [
                'is_active' => false,
            ])
            ->assertStatus(409);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.service-areas.activation', $this->area), [
                'is_active' => false,
            ])
            ->assertStatus(409);
    }

    private function openTicketThroughService(): DiningTicket
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

    private function terminalHeaders(): array
    {
        return [
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $this->terminal->id,
        ];
    }
}
