<?php

namespace Tests\Feature\Dining;

use App\Models\BillSplitAllocation;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketItem;
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

class DiningOfflinePolicyTest extends TestCase
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
            'selling_price' => '100.0000',
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

    public function test_open_ticket_is_blocked_by_offline_dining_policy_without_writes(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->offlineHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'guest_count' => 2,
                'client_request_uuid' => (string) Str::uuid(),
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'DINING_ONLINE_REQUIRED');
        $this->assertSame(0, DiningTicket::count());
    }

    public function test_item_mutations_are_blocked_by_offline_dining_policy_without_history_writes(): void
    {
        $ticket = $this->openTicket();
        $item = $this->sourceItem($ticket, seatNumber: 1);
        $this->syncTicketTotals($ticket, revision: 2);

        $routes = [
            ['POST', route('pos.dining.tickets.items.store', $ticket), [
                'product_id' => $this->product->id,
                'quantity' => '1.000',
                'expected_ticket_revision' => 2,
            ]],
            ['PATCH', route('pos.dining.tickets.items.quantity', [$ticket, $item]), [
                'quantity' => '2.000',
                'expected_ticket_revision' => 2,
            ]],
            ['PATCH', route('pos.dining.tickets.items.seat', [$ticket, $item]), [
                'seat_number' => 2,
                'expected_ticket_revision' => 2,
            ]],
            ['POST', route('pos.dining.tickets.items.move-seat', [$ticket, $item]), [
                'seat_number' => 3,
                'expected_ticket_revision' => 2,
            ]],
            ['POST', route('pos.dining.tickets.items.void', [$ticket, $item]), [
                'reason' => 'Offline attempt',
                'expected_ticket_revision' => 2,
            ]],
        ];

        foreach ($routes as [$method, $url, $payload]) {
            $response = $this->actingAs($this->cashier)
                ->withHeaders($this->offlineHeaders())
                ->json($method, $url, $payload);

            $response->assertStatus(409);
            $response->assertJsonPath('code', 'DINING_ONLINE_REQUIRED');
        }

        $this->assertSame(1, DiningTicketItem::where('dining_ticket_id', $ticket->id)->count());
        $this->assertSame(2, $ticket->fresh()->ticket_revision);
    }

    public function test_split_mutations_are_blocked_when_connectivity_is_checking_or_offline(): void
    {
        $ticket = $this->openTicket();
        $first = $this->sourceItem($ticket, seatNumber: 1);
        $second = $this->sourceItem($ticket, seatNumber: 2);
        $this->syncTicketTotals($ticket, revision: 3);

        $seat = $this->actingAs($this->cashier)
            ->withHeaders($this->offlineHeaders('checking'))
            ->postJson(route('pos.dining.tickets.splits.seat', $ticket), [
                'expected_ticket_revision' => 3,
                'client_request_uuid' => (string) Str::uuid(),
                'groups' => [
                    ['seat_numbers' => [1]],
                    ['seat_numbers' => [2]],
                ],
            ]);
        $seat->assertStatus(409);
        $seat->assertJsonPath('code', 'DINING_ONLINE_REQUIRED');

        $items = $this->actingAs($this->cashier)
            ->withHeaders($this->offlineHeaders())
            ->postJson(route('pos.dining.tickets.splits.items', $ticket), [
                'expected_ticket_revision' => 3,
                'client_request_uuid' => (string) Str::uuid(),
                'groups' => [
                    ['items' => [['dining_ticket_item_id' => $first->id, 'quantity' => '1.000']]],
                    ['items' => [['dining_ticket_item_id' => $second->id, 'quantity' => '1.000']]],
                ],
            ]);
        $items->assertStatus(409);
        $items->assertJsonPath('code', 'DINING_ONLINE_REQUIRED');

        $this->assertSame(0, DiningTicket::where('parent_ticket_id', $ticket->id)->count());
        $this->assertSame(0, BillSplitAllocation::where('parent_ticket_id', $ticket->id)->count());
    }

    public function test_terminal_context_failure_is_not_masked_by_dining_online_guard(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
                'X-IPOS-Online-Only' => 'dining',
                'X-IPOS-Connectivity' => 'offline',
            ])
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'guest_count' => 2,
                'client_request_uuid' => (string) Str::uuid(),
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'TERMINAL_CONTEXT_INVALID');
    }

    public function test_online_signal_allows_existing_dining_validation_to_run(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeaders($this->onlineHeaders())
            ->postJson(route('pos.dining.tickets.store'), [
                'dining_table_id' => $this->table->id,
                'guest_count' => 2,
                'client_request_uuid' => (string) Str::uuid(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('dining_ticket.status', DiningTicket::STATUS_OPEN);
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

    private function sourceItem(DiningTicket $ticket, ?int $seatNumber): DiningTicketItem
    {
        return DiningTicketItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'dining_ticket_id' => $ticket->id,
            'product_id' => $this->product->id,
            'seat_number' => $seatNumber,
            'quantity' => '1.000',
            'unit_price_centavos' => 10000,
            'line_total_centavos' => 10000,
            'status' => DiningTicketItem::STATUS_OPEN,
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

    private function offlineHeaders(string $connectivity = 'offline'): array
    {
        return array_merge($this->terminalHeaders(), [
            'X-IPOS-Online-Only' => 'dining',
            'X-IPOS-Connectivity' => $connectivity,
        ]);
    }

    private function onlineHeaders(): array
    {
        return array_merge($this->terminalHeaders(), [
            'X-IPOS-Online-Only' => 'dining',
            'X-IPOS-Connectivity' => 'online',
        ]);
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
