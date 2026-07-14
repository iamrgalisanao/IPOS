<?php

namespace Tests\Feature\Dining;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketEvent;
use App\Models\DiningTicketVersion;
use App\Models\EmployeeTimecard;
use App\Models\SalesMachineProfile;
use App\Models\ServiceArea;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Dining\DiningFloorReadModel;
use App\Services\Dining\DiningTicketService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiningFloorMapReadModelTest extends TestCase
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
        $cashierRole = \App\Models\Role::where('tenant_id', $this->tenant->id)
            ->where('name', 'Cashier')
            ->firstOrFail();
        $this->cashier->assignRole($cashierRole);
        $this->cashier->assignToBranch($this->branch);

        $this->area = ServiceArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Main Dining',
            'is_active' => true,
            'layout_metadata' => array_merge(ServiceArea::DEFAULT_LAYOUT_METADATA, [
                'canvas_width' => 1200,
                'canvas_height' => 720,
            ]),
        ]);
        $this->table = DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'service_area_id' => $this->area->id,
            'table_number' => 'T1',
            'capacity' => 4,
            'operational_state' => DiningTable::STATE_AVAILABLE,
            'position_metadata' => array_merge(DiningTable::DEFAULT_POSITION_METADATA, [
                'x' => 100,
                'y' => 120,
            ]),
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

    public function test_floor_map_endpoint_returns_read_only_layout_and_vacant_status(): void
    {
        $beforeAuditCount = AuditLog::count();
        $beforeVersionCount = DiningTicketVersion::count();
        $beforeTimelineCount = DiningTicketEvent::count();

        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->getJson(route('pos.dining.floor-map.index'));

        $response->assertOk();
        $response->assertJsonPath('data.schema_version', 1);
        $response->assertJsonPath('data.branch_id', $this->branch->id);
        $response->assertJsonPath('data.terminal_id', $this->terminal->id);
        $response->assertJsonPath('data.service_areas.0.id', $this->area->id);
        $response->assertJsonPath('data.service_areas.0.tables.0.id', $this->table->id);
        $response->assertJsonPath('data.service_areas.0.tables.0.status', 'vacant');
        $response->assertJsonPath('data.service_areas.0.tables.0.status_reason', 'table_available');
        $response->assertJsonMissingPath('data.service_areas.0.tables.0.active_ticket');
        $response->assertJsonStructure([
            'data' => [
                'cache_key',
                'generated_at',
                'layout_revision',
                'occupancy_revision',
                'service_areas' => [
                    [
                        'layout_metadata',
                        'tables' => [
                            [
                                'position_metadata',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame($beforeAuditCount, AuditLog::count());
        $this->assertSame($beforeVersionCount, DiningTicketVersion::count());
        $this->assertSame($beforeTimelineCount, DiningTicketEvent::count());
    }

    public function test_active_ticket_takes_precedence_over_operational_state(): void
    {
        $ticket = app(DiningTicketService::class)->openTicket(
            $this->table->fresh(),
            [
                'client_request_uuid' => (string) Str::uuid(),
                'guest_count' => 3,
            ],
            $this->cashier,
            $this->terminal,
        );
        $this->table->update(['operational_state' => DiningTable::STATE_CLEANING]);

        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->getJson(route('pos.dining.floor-map.index'));

        $response->assertOk();
        $response->assertJsonPath('data.service_areas.0.tables.0.status', 'occupied');
        $response->assertJsonPath('data.service_areas.0.tables.0.status_reason', 'active_primary_ticket');
        $response->assertJsonPath('data.service_areas.0.tables.0.active_ticket.id', $ticket->id);
        $response->assertJsonPath('data.service_areas.0.tables.0.active_ticket.ticket_number', $ticket->ticket_number);
        $response->assertJsonPath('data.service_areas.0.tables.0.active_ticket.guest_count', 3);
        $response->assertJsonMissingPath('data.service_areas.0.tables.0.active_ticket.opened_minutes_ago');
    }

    public function test_operational_states_are_projected_when_table_has_no_active_ticket(): void
    {
        $this->table->update(['operational_state' => DiningTable::STATE_RESERVED]);

        $reserved = $this->floorMapPayload();
        $this->assertSame('reserved', $reserved['service_areas'][0]['tables'][0]['status']);

        $this->table->update(['operational_state' => DiningTable::STATE_CLEANING]);

        $cleaning = $this->floorMapPayload();
        $this->assertSame('cleaning', $cleaning['service_areas'][0]['tables'][0]['status']);
    }

    public function test_inactive_service_areas_and_tables_are_hidden_from_default_pos_read_model(): void
    {
        DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'service_area_id' => $this->area->id,
            'table_number' => 'T2',
            'is_active' => false,
        ]);

        $inactiveArea = ServiceArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Closed Patio',
            'is_active' => false,
        ]);
        DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'service_area_id' => $inactiveArea->id,
            'table_number' => 'P1',
            'is_active' => true,
        ]);

        $payload = $this->floorMapPayload();

        $this->assertCount(1, $payload['service_areas']);
        $this->assertSame($this->area->id, $payload['service_areas'][0]['id']);
        $this->assertCount(1, $payload['service_areas'][0]['tables']);
        $this->assertSame($this->table->id, $payload['service_areas'][0]['tables'][0]['id']);
    }

    public function test_unknown_table_operational_state_returns_unavailable_and_logs_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('Unknown dining table operational state.', \Mockery::on(fn (array $context) => $context['dining_table_id'] === $this->table->id
                && $context['operational_state'] === 'blocked'));

        $this->table->update(['operational_state' => 'blocked']);

        $payload = $this->floorMapPayload();

        $this->assertSame('unavailable', $payload['service_areas'][0]['tables'][0]['status']);
        $this->assertSame('unknown_operational_state', $payload['service_areas'][0]['tables'][0]['status_reason']);
    }

    public function test_layout_and_occupancy_revisions_change_independently(): void
    {
        $initial = $this->floorMapPayload();

        $this->area->update([
            'layout_revision' => 2,
            'layout_metadata' => array_merge(ServiceArea::DEFAULT_LAYOUT_METADATA, ['grid_size' => 20]),
        ]);
        $layoutChanged = $this->floorMapPayload();

        $this->assertNotSame($initial['layout_revision'], $layoutChanged['layout_revision']);
        $this->assertSame($initial['occupancy_revision'], $layoutChanged['occupancy_revision']);

        app(DiningTicketService::class)->openTicket(
            $this->table->fresh(),
            [
                'client_request_uuid' => (string) Str::uuid(),
                'guest_count' => 2,
            ],
            $this->cashier,
            $this->terminal,
        );
        $occupancyChanged = $this->floorMapPayload();

        $this->assertSame($layoutChanged['layout_revision'], $occupancyChanged['layout_revision']);
        $this->assertNotSame($layoutChanged['occupancy_revision'], $occupancyChanged['occupancy_revision']);
    }

    public function test_endpoint_requires_create_sale_permission(): void
    {
        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $viewer->assignToBranch($this->branch);

        $this->actingAs($viewer)
            ->withHeaders($this->terminalHeaders())
            ->getJson(route('pos.dining.floor-map.index'))
            ->assertForbidden();
    }

    public function test_endpoint_requires_terminal_context(): void
    {
        $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->getJson(route('pos.dining.floor-map.index'))
            ->assertForbidden();
    }

    private function floorMapPayload(): array
    {
        return app(DiningFloorReadModel::class)->forBranch($this->tenant->id, $this->branch->id, $this->terminal);
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
