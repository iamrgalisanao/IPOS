<?php

namespace Tests\Feature\Dining;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketEvent;
use App\Models\DiningTicketItem;
use App\Models\DiningTicketVersion;
use App\Models\EmployeeTimecard;
use App\Models\Product;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiningTicketItemMutationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private SalesMachineProfile $terminal;
    private ServiceArea $area;
    private DiningTable $table;
    private Product $product;

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

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Latte',
            'sku' => 'LATTE',
            'selling_price' => '125.5000',
            'status' => 'active',
            'is_sellable' => true,
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

    public function test_cashier_can_add_item_with_price_snapshot_and_audit_records(): void
    {
        $ticket = $this->openTicket();

        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.items.store', $ticket), [
                'product_id' => $this->product->id,
                'quantity' => '2.000',
                'seat_number' => 1,
                'course_no' => 1,
                'fire_group' => 'main',
                'expected_ticket_revision' => 1,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('item.product_name', 'Latte');
        $response->assertJsonPath('item.quantity', '2.000');
        $response->assertJsonPath('item.unit_price_centavos', 12550);
        $response->assertJsonPath('item.line_total_centavos', 25100);
        $response->assertJsonPath('dining_ticket.ticket_revision', 2);
        $response->assertJsonPath('dining_ticket.subtotal_centavos', 25100);

        $itemId = $response->json('item.id');

        $this->assertDatabaseHas('dining_ticket_items', [
            'id' => $itemId,
            'dining_ticket_id' => $ticket->id,
            'seat_number' => 1,
            'unit_price_centavos' => 12550,
            'line_total_centavos' => 25100,
            'status' => DiningTicketItem::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DINING_ITEM_ADDED',
            'auditable_id' => $ticket->id,
        ]);
        $this->assertDatabaseHas('dining_ticket_versions', [
            'dining_ticket_id' => $ticket->id,
            'version' => 2,
            'operation' => 'item_added',
        ]);
        $this->assertDatabaseHas('dining_ticket_events', [
            'dining_ticket_id' => $ticket->id,
            'event_sequence' => 2,
            'event_type' => 'item_added',
        ]);

        $this->product->update(['selling_price' => '999.0000', 'status' => 'inactive']);
        $this->assertDatabaseHas('dining_ticket_items', [
            'id' => $itemId,
            'unit_price_centavos' => 12550,
            'line_total_centavos' => 25100,
        ]);
    }

    public function test_cashier_can_change_quantity_and_noop_does_not_write_history(): void
    {
        $ticket = $this->openTicket();
        $item = $this->addItem($ticket, '1.000', null);

        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->patchJson(route('pos.dining.tickets.items.quantity', [$ticket, $item]), [
                'quantity' => '1.125',
                'expected_ticket_revision' => 2,
            ]);

        $response->assertOk();
        $response->assertJsonPath('item.quantity', '1.125');
        $response->assertJsonPath('item.line_total_centavos', 14119);
        $response->assertJsonPath('dining_ticket.ticket_revision', 3);

        $versionCount = DiningTicketVersion::where('dining_ticket_id', $ticket->id)->count();
        $eventCount = DiningTicketEvent::where('dining_ticket_id', $ticket->id)->count();
        $auditCount = AuditLog::where('auditable_id', $ticket->id)->count();

        $noop = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->patchJson(route('pos.dining.tickets.items.quantity', [$ticket, $item]), [
                'quantity' => '1.125',
                'expected_ticket_revision' => 3,
            ]);

        $noop->assertOk();
        $noop->assertJsonPath('dining_ticket.ticket_revision', 3);
        $this->assertSame($versionCount, DiningTicketVersion::where('dining_ticket_id', $ticket->id)->count());
        $this->assertSame($eventCount, DiningTicketEvent::where('dining_ticket_id', $ticket->id)->count());
        $this->assertSame($auditCount, AuditLog::where('auditable_id', $ticket->id)->count());
    }

    public function test_cashier_can_assign_clear_move_and_void_item_with_traceability(): void
    {
        $ticket = $this->openTicket();
        $item = $this->addItem($ticket, '1.000', null);

        $seat = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->patchJson(route('pos.dining.tickets.items.seat', [$ticket, $item]), [
                'seat_number' => 2,
                'expected_ticket_revision' => 2,
            ]);

        $seat->assertOk();
        $seat->assertJsonPath('item.seat_number', 2);
        $seat->assertJsonPath('dining_ticket.ticket_revision', 3);

        $sameSeat = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->patchJson(route('pos.dining.tickets.items.seat', [$ticket, $item]), [
                'seat_number' => 2,
                'expected_ticket_revision' => 3,
            ]);
        $sameSeat->assertOk();
        $sameSeat->assertJsonPath('dining_ticket.ticket_revision', 3);

        $move = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.items.move-seat', [$ticket, $item]), [
                'seat_number' => 3,
                'expected_ticket_revision' => 3,
            ]);

        $move->assertOk();
        $move->assertJsonPath('source_item.status', DiningTicketItem::STATUS_MOVED);
        $move->assertJsonPath('item.source_item_id', $item->id);
        $move->assertJsonPath('item.seat_number', 3);
        $move->assertJsonPath('dining_ticket.ticket_revision', 4);

        $replacement = DiningTicketItem::findOrFail($move->json('item.id'));

        $void = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.items.void', [$ticket, $replacement]), [
                'reason' => 'Wrong item selected',
                'expected_ticket_revision' => 4,
            ]);

        $void->assertOk();
        $void->assertJsonPath('item.status', DiningTicketItem::STATUS_VOIDED);
        $void->assertJsonPath('dining_ticket.subtotal_centavos', 0);
        $this->assertDatabaseHas('dining_ticket_items', [
            'id' => $replacement->id,
            'status' => DiningTicketItem::STATUS_VOIDED,
        ]);
        $this->assertDatabaseHas('dining_ticket_events', [
            'dining_ticket_id' => $ticket->id,
            'event_type' => 'item_voided',
        ]);
    }

    public function test_move_to_same_seat_is_validation_conflict_without_mutation(): void
    {
        $ticket = $this->openTicket();
        $item = $this->addItem($ticket, '1.000', 4);
        $beforeItems = DiningTicketItem::count();

        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.items.move-seat', [$ticket, $item]), [
                'seat_number' => 4,
                'expected_ticket_revision' => 2,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'DINING_ITEM_MOVE_SAME_SEAT');
        $this->assertSame($beforeItems, DiningTicketItem::count());
        $this->assertSame(2, $ticket->fresh()->ticket_revision);
    }

    public function test_stale_revision_rejects_without_item_or_history_writes(): void
    {
        $ticket = $this->openTicket();
        $this->addItem($ticket, '1.000', null);

        $itemCount = DiningTicketItem::count();
        $auditCount = AuditLog::where('auditable_id', $ticket->id)->count();
        $versionCount = DiningTicketVersion::where('dining_ticket_id', $ticket->id)->count();
        $eventCount = DiningTicketEvent::where('dining_ticket_id', $ticket->id)->count();

        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.items.store', $ticket), [
                'product_id' => $this->product->id,
                'quantity' => '1.000',
                'expected_ticket_revision' => 1,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'DINING_TICKET_REVISION_CONFLICT');
        $response->assertJsonPath('current_ticket_revision', 2);
        $this->assertSame($itemCount, DiningTicketItem::count());
        $this->assertSame($auditCount, AuditLog::where('auditable_id', $ticket->id)->count());
        $this->assertSame($versionCount, DiningTicketVersion::where('dining_ticket_id', $ticket->id)->count());
        $this->assertSame($eventCount, DiningTicketEvent::where('dining_ticket_id', $ticket->id)->count());
    }

    public function test_closed_ticket_and_non_open_item_reject_mutations(): void
    {
        $ticket = $this->openTicket();
        $item = $this->addItem($ticket, '1.000', null);

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.items.void', [$ticket, $item]), [
                'reason' => 'Mistake',
                'expected_ticket_revision' => 2,
            ])
            ->assertOk();

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->patchJson(route('pos.dining.tickets.items.quantity', [$ticket, $item]), [
                'quantity' => '2.000',
                'expected_ticket_revision' => 3,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DINING_ITEM_NOT_OPEN');

        $secondTicket = $this->openTicketForSecondTable();
        $settling = app(DiningTicketService::class)->transitionStatus(
            $secondTicket,
            DiningTicket::STATUS_SETTLING,
            $this->cashier,
            1,
            [],
            $this->terminal,
        );
        $closed = app(DiningTicketService::class)->transitionStatus(
            $settling,
            DiningTicket::STATUS_CLOSED,
            $this->cashier,
            2,
            [],
            $this->terminal,
        );

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.items.store', $closed), [
                'product_id' => $this->product->id,
                'quantity' => '1.000',
                'expected_ticket_revision' => 3,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DINING_TICKET_NOT_ACTIVE');
    }

    public function test_authorization_terminal_and_branch_guards_are_enforced(): void
    {
        $ticket = $this->openTicket();

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $viewer->assignToBranch($this->branch);
        $this->assertFalse($viewer->hasPermission('create_sale'));

        $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson(route('pos.dining.tickets.items.store', $ticket), [
                'product_id' => $this->product->id,
                'quantity' => '1.000',
                'expected_ticket_revision' => 1,
            ])
            ->assertForbidden();

        $otherBranch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        app(BranchContext::class)->setBranch($otherBranch);
        $otherTerminal = SalesMachineProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'profile_code' => 'OTHER-REG-01',
            'terminal_identifier' => 'OTHER-REG-01',
            'status' => 'active',
            'activation_status' => SalesMachineProfile::STATUS_ACTIVE,
        ]);
        app(BranchContext::class)->setBranch($this->branch);
        $this->cashier->assignToBranch($otherBranch);
        EmployeeTimecard::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'terminal_id' => $otherTerminal->id,
            'user_id' => $this->cashier->id,
            'clocked_in_at' => now(),
            'clock_in_method' => 'pin',
            'is_active' => 1,
        ]);

        $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $otherBranch->id,
                'X-Terminal-ID' => $otherTerminal->id,
            ])
            ->postJson(route('pos.dining.tickets.items.store', $ticket), [
                'product_id' => $this->product->id,
                'quantity' => '1.000',
                'expected_ticket_revision' => 1,
            ])
            ->assertNotFound();
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

    private function openTicketForSecondTable(): DiningTicket
    {
        $table = DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'service_area_id' => $this->area->id,
            'table_number' => 'T2',
            'operational_state' => DiningTable::STATE_AVAILABLE,
            'is_active' => true,
        ]);

        return app(DiningTicketService::class)->openTicket(
            $table,
            [
                'client_request_uuid' => (string) Str::uuid(),
                'guest_count' => 1,
            ],
            $this->cashier,
            $this->terminal,
        );
    }

    private function addItem(DiningTicket $ticket, string $quantity, ?int $seatNumber): DiningTicketItem
    {
        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.items.store', $ticket), array_filter([
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'seat_number' => $seatNumber,
                'expected_ticket_revision' => $ticket->fresh()->ticket_revision,
            ], fn ($value) => $value !== null));

        $response->assertCreated();

        return DiningTicketItem::findOrFail($response->json('item.id'));
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
