<?php

namespace Tests\Feature\Dining;

use App\Jobs\Inventory\ProcessSaleInventoryDeductionJob;
use App\Models\BillSplitAllocation;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketItem;
use App\Models\EmployeeTimecard;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiningTicketCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private SalesMachineProfile $terminal;
    private ServiceArea $area;
    private DiningTable $table;
    private Product $coffee;
    private Product $tea;
    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessSaleInventoryDeductionJob::class]);
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
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
        $this->cashier->assignRole(Role::where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->firstOrFail());
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

        $this->coffee = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'selling_price' => 100.00,
            'is_inventory_tracked' => false,
            'status' => 'active',
        ]);
        $this->tea = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'selling_price' => 150.00,
            'is_inventory_tracked' => false,
            'status' => 'active',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'reference_required' => false,
            'status' => 'active',
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

    public function test_split_child_checkout_uses_sales_authority_and_payment_closes_derived_parent_state(): void
    {
        $parent = $this->openSplitTicket();
        $children = DiningTicket::query()->where('parent_ticket_id', $parent->id)->orderBy('ticket_number')->get();
        $firstChild = $children[0];
        $secondChild = $children[1];

        $checkoutUuid = (string) Str::uuid();
        $saleResponse = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.checkout.create-sale', $firstChild), [
                'checkout_request_uuid' => $checkoutUuid,
                'expected_ticket_revision' => 1,
            ]);

        $saleResponse->assertOk()
            ->assertJsonPath('status', 'created')
            ->assertJsonPath('dining_ticket.status', DiningTicket::STATUS_SETTLING)
            ->assertJsonPath('payment_status', 'pending');

        $firstChild->refresh();
        $this->assertSame(DiningTicket::STATUS_SETTLING, $firstChild->status);
        $this->assertNotNull($firstChild->source_sale_id);
        $this->assertNull($firstChild->closed_at);
        $this->assertSame(DiningTicket::STATUS_SETTLING, $parent->fresh()->status);

        $sale = Sale::with('items')->findOrFail($firstChild->source_sale_id);
        $this->assertSame('created', $sale->status);
        $this->assertSame('100.0000', (string) $sale->total);
        $this->assertSame(1, $sale->items->count());
        $this->assertSame(100, (int) $sale->items->first()->promotion_discount_centavos);
        $this->assertSame('100.0000', (string) $sale->items->first()->line_total);

        $replay = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.checkout.create-sale', $firstChild), [
                'checkout_request_uuid' => $checkoutUuid,
                'expected_ticket_revision' => 1,
            ]);
        $replay->assertOk()
            ->assertJsonPath('status', 'duplicate_seen')
            ->assertJsonPath('sale.id', $sale->id);
        $this->assertSame(1, Sale::where('client_request_uuid', $checkoutUuid)->count());

        $drift = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.checkout.create-sale', $secondChild), [
                'checkout_request_uuid' => $checkoutUuid,
                'expected_ticket_revision' => 1,
            ]);
        $drift->assertStatus(409)
            ->assertJsonPath('code', 'DINING_CHECKOUT_IDEMPOTENCY_DRIFT');

        $payment = $this->paySale($sale);
        $payment->assertOk()
            ->assertJsonPath('dining_ticket.status', DiningTicket::STATUS_CLOSED)
            ->assertJsonPath('parent_settlement.status', 'partially_paid')
            ->assertJsonPath('parent_settlement.closed_child_count', 1);

        $this->assertSame(DiningTicket::STATUS_CLOSED, $firstChild->fresh()->status);
        $this->assertSame(DiningTicket::STATUS_SETTLING, $parent->fresh()->status);
        Queue::assertPushed(ProcessSaleInventoryDeductionJob::class, 1);

        $secondSaleResponse = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.checkout.create-sale', $secondChild), [
                'checkout_request_uuid' => (string) Str::uuid(),
                'expected_ticket_revision' => 1,
            ]);
        $secondSaleResponse->assertOk();
        $secondSale = Sale::findOrFail($secondSaleResponse->json('sale.id'));

        $secondPayment = $this->paySale($secondSale);
        $secondPayment->assertOk()
            ->assertJsonPath('dining_ticket.status', DiningTicket::STATUS_CLOSED)
            ->assertJsonPath('parent_settlement.status', 'paid')
            ->assertJsonPath('parent_settlement.remaining_centavos', 0);

        $this->assertSame(DiningTicket::STATUS_CLOSED, $parent->fresh()->status);
    }

    public function test_split_parent_cannot_be_checked_out_directly(): void
    {
        $parent = $this->openSplitTicket();

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.checkout.create-sale', $parent), [
                'checkout_request_uuid' => (string) Str::uuid(),
                'expected_ticket_revision' => 4,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DINING_CHECKOUT_PARENT_NOT_PAYABLE');
    }

    public function test_failed_payment_does_not_close_dining_ticket(): void
    {
        $parent = $this->openSplitTicket();
        $child = DiningTicket::query()->where('parent_ticket_id', $parent->id)->orderBy('ticket_number')->firstOrFail();

        $saleResponse = $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.checkout.create-sale', $child), [
                'checkout_request_uuid' => (string) Str::uuid(),
                'expected_ticket_revision' => 1,
            ]);
        $saleResponse->assertOk();

        $sale = Sale::findOrFail($saleResponse->json('sale.id'));
        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.sales.payments.split', ['sale_id' => $sale->id]), [
                'payments' => [
                    ['payment_method_id' => $this->cashMethod->id, 'amount' => '1.0000'],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(DiningTicket::STATUS_SETTLING, $child->fresh()->status);
        $this->assertNull($child->fresh()->closed_at);
        $this->assertSame(DiningTicket::STATUS_SETTLING, $parent->fresh()->status);
    }

    private function openSplitTicket(): DiningTicket
    {
        $ticket = app(DiningTicketService::class)->openTicket(
            $this->table,
            [
                'client_request_uuid' => (string) Str::uuid(),
                'guest_count' => 2,
            ],
            $this->cashier,
            $this->terminal,
        );

        $this->sourceItem($ticket, $this->coffee, seatNumber: 1, quantity: '1.000', unitPrice: 10000, lineTotal: 10000, promotionDiscount: 100);
        $this->sourceItem($ticket, $this->tea, seatNumber: 2, quantity: '1.000', unitPrice: 15000, lineTotal: 15000, promotionDiscount: 200);
        $this->syncTicketTotals($ticket, revision: 3);

        $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.dining.tickets.splits.seat', $ticket), [
                'expected_ticket_revision' => 3,
                'client_request_uuid' => (string) Str::uuid(),
                'groups' => [
                    ['seat_numbers' => [1]],
                    ['seat_numbers' => [2]],
                ],
            ])
            ->assertCreated();

        return $ticket->fresh();
    }

    private function sourceItem(
        DiningTicket $ticket,
        Product $product,
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
            'product_id' => $product->id,
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

    private function paySale(Sale $sale): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)
            ->withHeaders($this->terminalHeaders())
            ->postJson(route('pos.sales.payments.split', ['sale_id' => $sale->id]), [
                'payments' => [
                    ['payment_method_id' => $this->cashMethod->id, 'amount' => (string) $sale->total],
                ],
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
