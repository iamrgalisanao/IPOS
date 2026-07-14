<?php

namespace Tests\Feature\Dining;

use App\Models\AuditLog;
use App\Models\BillSplitAllocation;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketEvent;
use App\Models\DiningTicketItem;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillSplitAllocatorTest extends TestCase
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

    public function test_ticket_can_be_split_by_seat_with_atomic_history_and_parent_guard(): void
    {
        $ticket = $this->openTicket();
        $this->sourceItem($ticket, seatNumber: 1, quantity: '1.000', unitPrice: 10000, lineTotal: 10000, promotionDiscount: 100);
        $this->sourceItem($ticket, seatNumber: 2, quantity: '1.000', unitPrice: 15000, lineTotal: 15000, promotionDiscount: 200);
        $this->syncTicketTotals($ticket, revision: 3);

        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.splits.seat', $ticket), [
                'expected_ticket_revision' => 3,
                'client_request_uuid' => (string) Str::uuid(),
                'groups' => [
                    ['label' => 'Seat 1', 'seat_numbers' => [1]],
                    ['label' => 'Seat 2', 'seat_numbers' => [2]],
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('parent_ticket.status', DiningTicket::STATUS_SETTLING);
        $response->assertJsonPath('parent_ticket.ticket_revision', 4);
        $response->assertJsonPath('allocation_summary.allocated_amount_centavos', 25000);
        $response->assertJsonPath('allocation_summary.promotion_discount_centavos', 300);
        $response->assertJsonCount(2, 'children');

        $this->assertSame(2, DiningTicket::where('parent_ticket_id', $ticket->id)->count());
        $this->assertSame(2, BillSplitAllocation::where('parent_ticket_id', $ticket->id)->count());
        $this->assertSame(25000, (int) BillSplitAllocation::where('parent_ticket_id', $ticket->id)->sum('allocated_amount_centavos'));
        $this->assertSame(300, (int) BillSplitAllocation::where('parent_ticket_id', $ticket->id)->sum('promotion_discount_centavos'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DINING_BILL_SPLIT_CREATED',
            'auditable_id' => $ticket->id,
        ]);
        $this->assertDatabaseHas('dining_ticket_versions', [
            'dining_ticket_id' => $ticket->id,
            'version' => 4,
            'operation' => 'bill_split_created',
        ]);
        $this->assertDatabaseHas('dining_ticket_events', [
            'dining_ticket_id' => $ticket->id,
            'event_type' => 'bill_split_created',
        ]);
        $this->assertSame(2, DiningTicketEvent::where('event_type', 'child_bill_created')->count());

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->patchJson(route('pos.dining.tickets.items.quantity', [$ticket, $ticket->items()->firstOrFail()]), [
                'quantity' => '2.000',
                'expected_ticket_revision' => 4,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DINING_TICKET_ALREADY_SPLIT');
    }

    public function test_item_quantity_split_preserves_centavos_with_deterministic_rounding(): void
    {
        $ticket = $this->openTicket();
        $item = $this->sourceItem($ticket, seatNumber: null, quantity: '3.000', unitPrice: 33, lineTotal: 100, promotionDiscount: 10);
        $this->syncTicketTotals($ticket, revision: 2);

        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.splits.items', $ticket), [
                'expected_ticket_revision' => 2,
                'client_request_uuid' => (string) Str::uuid(),
                'groups' => [
                    ['label' => 'A', 'items' => [['dining_ticket_item_id' => $item->id, 'quantity' => '1.000']]],
                    ['label' => 'B', 'items' => [['dining_ticket_item_id' => $item->id, 'quantity' => '1.000']]],
                    ['label' => 'C', 'items' => [['dining_ticket_item_id' => $item->id, 'quantity' => '1.000']]],
                ],
            ]);

        $response->assertCreated();
        $this->assertSame([33, 33, 34], DiningTicket::where('parent_ticket_id', $ticket->id)
            ->orderBy('ticket_number')
            ->pluck('grand_total_centavos')
            ->all());
        $this->assertSame([0, 0, 1], BillSplitAllocation::where('parent_ticket_id', $ticket->id)
            ->orderBy('allocation_sequence')
            ->pluck('rounding_adjustment_centavos')
            ->all());
        $this->assertSame(100, (int) BillSplitAllocation::where('parent_ticket_id', $ticket->id)->sum('allocated_amount_centavos'));
        $this->assertSame(10, (int) BillSplitAllocation::where('parent_ticket_id', $ticket->id)->sum('promotion_discount_centavos'));

        $lastSnapshot = BillSplitAllocation::where('parent_ticket_id', $ticket->id)
            ->orderByDesc('allocation_sequence')
            ->firstOrFail()
            ->promotion_allocation_snapshot;
        $this->assertArrayHasKey('promotion_snapshot_version', $lastSnapshot);
    }

    public function test_split_request_is_idempotent_and_detects_drift(): void
    {
        $ticket = $this->openTicket();
        $this->sourceItem($ticket, seatNumber: 1, quantity: '1.000', unitPrice: 10000, lineTotal: 10000);
        $this->sourceItem($ticket, seatNumber: 2, quantity: '1.000', unitPrice: 10000, lineTotal: 10000);
        $this->syncTicketTotals($ticket, revision: 3);
        $uuid = (string) Str::uuid();

        $payload = [
            'expected_ticket_revision' => 3,
            'client_request_uuid' => $uuid,
            'groups' => [
                ['label' => 'First label ignored', 'seat_numbers' => [1]],
                ['label' => 'Second label ignored', 'seat_numbers' => [2]],
            ],
        ];

        $first = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.splits.seat', $ticket), $payload);
        $first->assertCreated();

        $replay = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.splits.seat', $ticket), array_replace($payload, [
                'groups' => [
                    ['label' => 'Different presentation label', 'seat_numbers' => [1]],
                    ['label' => 'Still ignored', 'seat_numbers' => [2]],
                ],
            ]));
        $replay->assertOk();
        $this->assertSame(2, DiningTicket::where('parent_ticket_id', $ticket->id)->count());
        $this->assertSame(2, BillSplitAllocation::where('parent_ticket_id', $ticket->id)->count());

        $drift = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.splits.seat', $ticket), [
                'expected_ticket_revision' => 3,
                'client_request_uuid' => $uuid,
                'groups' => [
                    ['seat_numbers' => [2]],
                    ['seat_numbers' => [1]],
                ],
            ]);
        $drift->assertStatus(409);
        $drift->assertJsonPath('code', 'DINING_SPLIT_IDEMPOTENCY_DRIFT');
    }

    public function test_stale_revision_and_statutory_discount_block_without_partial_writes(): void
    {
        $ticket = $this->openTicket();
        $this->sourceItem($ticket, seatNumber: 1, quantity: '1.000', unitPrice: 10000, lineTotal: 10000);
        $this->sourceItem($ticket, seatNumber: 2, quantity: '1.000', unitPrice: 10000, lineTotal: 10000);
        $this->syncTicketTotals($ticket, revision: 3);

        $stale = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.splits.seat', $ticket), [
                'expected_ticket_revision' => 2,
                'client_request_uuid' => (string) Str::uuid(),
                'groups' => [
                    ['seat_numbers' => [1]],
                    ['seat_numbers' => [2]],
                ],
            ]);

        $stale->assertStatus(409);
        $stale->assertJsonPath('code', 'DINING_TICKET_REVISION_CONFLICT');
        $this->assertSame(0, DiningTicket::where('parent_ticket_id', $ticket->id)->count());
        $this->assertSame(0, BillSplitAllocation::where('parent_ticket_id', $ticket->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'DINING_BILL_SPLIT_CREATED')->count());
        $this->assertSame(0, DiningTicketVersion::where('operation', 'bill_split_created')->count());

        $ticket->update(['discount_engine_version' => 'statutory-v1']);
        $blocked = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.splits.seat', $ticket), [
                'expected_ticket_revision' => 3,
                'client_request_uuid' => (string) Str::uuid(),
                'groups' => [
                    ['seat_numbers' => [1]],
                    ['seat_numbers' => [2]],
                ],
            ]);

        $blocked->assertStatus(409);
        $blocked->assertJsonPath('code', 'DINING_TICKET_STATUTORY_DISCOUNT_SPLIT_BLOCKED');
        $this->assertSame(0, DiningTicket::where('parent_ticket_id', $ticket->id)->count());
        $this->assertSame(0, BillSplitAllocation::where('parent_ticket_id', $ticket->id)->count());
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

    private function sourceItem(
        DiningTicket $ticket,
        ?int $seatNumber,
        string $quantity,
        int $unitPrice,
        int $lineTotal,
        int $promotionDiscount = 0
    ): DiningTicketItem {
        return DiningTicketItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'dining_ticket_id' => $ticket->id,
            'product_id' => null,
            'seat_number' => $seatNumber,
            'quantity' => $quantity,
            'unit_price_centavos' => $unitPrice,
            'line_total_centavos' => $lineTotal,
            'status' => DiningTicketItem::STATUS_OPEN,
            'promotion_allocation_snapshot' => [
                'promotion_snapshot_version' => 1,
                'promotion_discount_centavos' => $promotionDiscount,
            ],
        ]);
    }

    private function syncTicketTotals(DiningTicket $ticket, int $revision): void
    {
        $subtotal = (int) $ticket->items()->activeForTotals()->sum('line_total_centavos');
        $ticket->forceFill([
            'subtotal_centavos' => $subtotal,
            'discount_centavos' => 0,
            'service_charge_centavos' => 0,
            'tax_centavos' => 0,
            'grand_total_centavos' => $subtotal,
            'ticket_revision' => $revision,
        ])->save();
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
