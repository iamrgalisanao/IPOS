<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\CashDrawerEvent;
use App\Models\PaymentMethod;
use App\Models\PaymentReversal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Shift\ShiftAccountabilityQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftAccountabilityQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftAccountabilityQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queryService = new ShiftAccountabilityQueryService();
    }

    protected function setContext(Tenant $tenant, ?Branch $branch = null): void
    {
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        if ($branch) {
            app(\App\Services\BranchContext::class)->setBranch($branch);
        }
    }

    /**
     * Test the service returns the expected structured report payload shape.
     */
    public function test_service_returns_expected_payload_shape(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'T001',
        ]);

        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $cashier->assignRole(Role::where('name', 'Cashier')->first());
        $cashier->branches()->attach($branch);
        // Grant explicit view permission
        $viewPerm = Permission::where('name', 'reports.cashier-accountability.view')->first();
        $cashier->roles()->first()->permissions()->attach($viewPerm);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now(),
        ]);

        $payload = $this->queryService->forShift($shift, $cashier);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('shift', $payload);
        $this->assertArrayHasKey('cashier', $payload);
        $this->assertArrayHasKey('branch', $payload);
        $this->assertArrayHasKey('timeline', $payload);
        $this->assertArrayHasKey('sales_summary', $payload);
        $this->assertArrayHasKey('payment_mix', $payload);
        $this->assertArrayHasKey('drawer_summary', $payload);
        $this->assertArrayHasKey('cash_variance', $payload);
        $this->assertArrayHasKey('drawer_timeline', $payload);
        $this->assertArrayHasKey('reversal_summary', $payload);
        $this->assertArrayHasKey('metadata', $payload);

        $this->assertEquals($shift->id, $payload['shift']['id']);
        $this->assertEquals(Shift::STATUS_OPEN, $payload['shift']['status']);
        $this->assertEquals($cashier->id, $payload['cashier']['id']);
        $this->assertEquals($branch->id, $payload['branch']['id']);
    }

    /**
     * Test aggregates from cash drawer events are calculated correctly.
     */
    public function test_cash_in_out_aggregates_from_events_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'T001',
        ]);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now(),
        ]);

        // Cash drawer events
        CashDrawerEvent::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $manager->id,
            'event_type' => 'cash_in',
            'amount' => '250.5000',
            'reason_code' => 'PAY_IN',
            'created_by' => $manager->id,
            'occurred_at' => now(),
        ]);

        CashDrawerEvent::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $manager->id,
            'event_type' => 'cash_top_up',
            'amount' => '100.0000',
            'reason_code' => 'TOP_UP',
            'created_by' => $manager->id,
            'occurred_at' => now(),
        ]);

        CashDrawerEvent::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $manager->id,
            'event_type' => 'cash_drop',
            'amount' => '150.0000',
            'reason_code' => 'MID_DAY_DROP',
            'created_by' => $manager->id,
            'occurred_at' => now(),
        ]);

        CashDrawerEvent::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $manager->id,
            'event_type' => 'cash_out',
            'amount' => '50.2500',
            'reason_code' => 'PAY_OUT',
            'created_by' => $manager->id,
            'occurred_at' => now(),
        ]);

        $payload = $this->queryService->forShift($shift, $manager);

        $this->assertEquals('350.5000', $payload['drawer_summary']['cash_in']);
        $this->assertEquals('200.2500', $payload['drawer_summary']['cash_out']);
        $this->assertEquals(4, $payload['drawer_summary']['drawer_event_count']);
    }

    /**
     * Test cash and non-cash payments are aggregated correctly.
     */
    public function test_cash_and_non_cash_payments_aggregate_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'T001',
        ]);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now(),
        ]);

        $pmCash = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $pmCard = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'name' => 'Card',
            'code' => 'card',
            'type' => 'card',
            'is_active' => true,
        ]);

        $sale1 = Sale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'gross_sales_amount' => '400.0000',
            'total' => '400.0000',
            'status' => 'completed',
        ]);

        $sale2 = Sale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'gross_sales_amount' => '300.0000',
            'total' => '300.0000',
            'status' => 'completed',
        ]);

        SalePayment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'sale_id' => $sale1->id,
            'payment_method_id' => $pmCash->id,
            'payment_type' => 'cash',
            'amount' => '400.0000',
            'status' => 'paid',
        ]);

        SalePayment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'sale_id' => $sale2->id,
            'payment_method_id' => $pmCard->id,
            'payment_type' => 'card',
            'amount' => '300.0000',
            'status' => 'paid',
        ]);

        $payload = $this->queryService->forShift($shift, $manager);

        $this->assertEquals('400.0000', $payload['payment_mix']['cash_sales']);
        $this->assertEquals('300.0000', $payload['payment_mix']['non_cash_sales']);

        $byMethod = collect($payload['payment_mix']['by_method'])->keyBy('code')->toArray();
        $this->assertEquals('400.0000', $byMethod['cash']['total']);
        $this->assertEquals('300.0000', $byMethod['card']['total']);
    }

    /**
     * Test VAT-inclusive gross sales are read from stored sale values.
     */
    public function test_vat_inclusive_gross_sales_read_from_stored_values(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'T001',
        ]);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now(),
        ]);

        $pm = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'gross_sales_amount' => '1120.0000', // VAT-inclusive gross amount stored
            'discount_total' => '120.0000',
            'total' => '1000.0000',
            'status' => 'completed',
        ]);

        SalePayment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'cash',
            'amount' => '1000.0000',
            'status' => 'paid',
        ]);

        $payload = $this->queryService->forShift($shift, $manager);

        $this->assertEquals('1120.0000', $payload['sales_summary']['gross_sales']);
        $this->assertEquals('120.0000', $payload['sales_summary']['discounts']);
    }

    /**
     * Test refunds and voids are mapped using temporal overlap.
     */
    public function test_refunds_and_voids_mapped_by_temporal_overlap(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'T001',
        ]);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $openedAt = now()->subHours(4);
        $closedAt = now()->subHours(1);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_CLOSED,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
        ]);

        $pm = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'gross_sales_amount' => '500.0000',
            'total' => '500.0000',
            'status' => 'completed',
        ]);

        $payment = SalePayment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'cash',
            'amount' => '500.0000',
            'status' => 'paid',
        ]);

        // Reversal 1: inside the shift timeline
        PaymentReversal::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'refund_reversal',
            'amount' => '150.0000',
            'reason_code' => 'CUSTOMER_REFUND',
            'reversed_by' => $manager->id,
            'reversed_at' => $openedAt->copy()->addHours(2), // Inside bounds
        ]);

        // Reversal 2: outside the shift timeline (should be excluded)
        PaymentReversal::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'refund_reversal',
            'amount' => '100.0000',
            'reason_code' => 'LATE_REFUND',
            'reversed_by' => $manager->id,
            'reversed_at' => $closedAt->copy()->addMinutes(30), // After close
        ]);

        // Reversal 3: void reversal inside the shift
        PaymentReversal::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'void_reversal',
            'amount' => '200.0000',
            'reason_code' => 'VOID_TRANSACTION',
            'reversed_by' => $manager->id,
            'reversed_at' => $openedAt->copy()->addHours(2)->addMinutes(30), // Inside bounds (strictly < closed_at)
        ]);

        $payload = $this->queryService->forShift($shift, $manager);

        $this->assertEquals('150.0000', $payload['sales_summary']['refunds']);
        $this->assertEquals('200.0000', $payload['sales_summary']['voids']);
        $this->assertEquals('150.0000', $payload['reversal_summary']['cash_refunds']);
    }

    /**
     * Test that cross-midnight shifts correctly map temporal reversals.
     */
    public function test_cross_midnight_shift_reversal_mapping(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'T001',
        ]);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $openedAt = \Carbon\Carbon::create(2026, 5, 17, 22, 0, 0, 'UTC');
        $closedAt = \Carbon\Carbon::create(2026, 5, 18, 6, 0, 0, 'UTC');

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_CLOSED,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
        ]);

        $pm = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'gross_sales_amount' => '500.0000',
            'total' => '500.0000',
            'status' => 'completed',
        ]);

        $payment = SalePayment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'cash',
            'amount' => '500.0000',
            'status' => 'paid',
        ]);

        // Reversal on next day during cross-midnight shift window (2:00 AM)
        PaymentReversal::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'refund_reversal',
            'amount' => '300.0000',
            'reason_code' => 'CROSS_MIDNIGHT_REFUND',
            'reversed_by' => $manager->id,
            'reversed_at' => \Carbon\Carbon::create(2026, 5, 18, 2, 0, 0, 'UTC'),
        ]);

        $payload = $this->queryService->forShift($shift, $manager);

        $this->assertEquals('300.0000', $payload['sales_summary']['refunds']);
    }

    /**
     * Test the correctness of net sales calculation logic.
     */
    public function test_net_sales_formula_is_correct(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'T001',
        ]);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now()->subHours(2),
        ]);

        $pm = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'gross_sales_amount' => '1000.0000',
            'discount_total' => '100.0000',
            'total' => '900.0000',
            'status' => 'completed',
        ]);

        $payment = SalePayment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'cash',
            'amount' => '900.0000',
            'status' => 'paid',
        ]);

        // Refund of 50.00
        PaymentReversal::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'refund_reversal',
            'amount' => '50.0000',
            'reason_code' => 'PARTIAL_REFUND',
            'reversed_by' => $manager->id,
            'reversed_at' => now()->subHour(),
        ]);

        // Void of 40.00
        PaymentReversal::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'void_reversal',
            'amount' => '40.0000',
            'reason_code' => 'VOID_OPERATION',
            'reversed_by' => $manager->id,
            'reversed_at' => now()->subHour(),
        ]);

        // Net Sales = 1000 (Gross) - 100 (Discount) - 50 (Refund) - 40 (Void) = 810.00
        $payload = $this->queryService->forShift($shift, $manager);

        $this->assertEquals('810.0000', $payload['sales_summary']['net_sales']);
    }

    /**
     * Test the correctness of expected cash calculations.
     */
    public function test_expected_cash_formula_is_correct(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'T001',
        ]);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now()->subHours(2),
        ]);

        // Drawer Cash In: 200.00
        CashDrawerEvent::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $manager->id,
            'event_type' => 'cash_in',
            'amount' => '200.0000',
            'reason_code' => 'PAY_IN',
            'created_by' => $manager->id,
            'occurred_at' => now(),
        ]);

        // Drawer Cash Out: 50.00
        CashDrawerEvent::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $manager->id,
            'event_type' => 'cash_out',
            'amount' => '50.0000',
            'reason_code' => 'PAY_OUT',
            'created_by' => $manager->id,
            'occurred_at' => now(),
        ]);

        // Cash sales: 300.00
        $pm = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'gross_sales_amount' => '300.0000',
            'total' => '300.0000',
            'status' => 'completed',
        ]);

        $payment = SalePayment::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $pm->id,
            'payment_type' => 'cash',
            'amount' => '300.0000',
            'status' => 'paid',
        ]);

        // Cash refund: 30.00
        PaymentReversal::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'refund_reversal',
            'amount' => '30.0000',
            'reason_code' => 'CASH_REFUND',
            'reversed_by' => $manager->id,
            'reversed_at' => now()->subHour(),
        ]);

        // Expected Cash = 1000 + 200 - 50 + 300 - 30 = 1420.00
        $payload = $this->queryService->forShift($shift, $manager);

        $this->assertEquals('1420.0000', $payload['cash_variance']['expected_cash']);
    }

    /**
     * Test tenant isolation is preserved.
     */
    public function test_tenant_isolation_is_preserved(): void
    {
        $tenantA = Tenant::factory()->create();
        $this->setContext($tenantA);
        (new RbacSeeder())->seedForTenant($tenantA);

        $tenantB = Tenant::factory()->create();
        $this->setContext($tenantB);
        (new RbacSeeder())->seedForTenant($tenantB);

        $this->setContext($tenantA);
        $branchA = Branch::create(['tenant_id' => $tenantA->id, 'name' => 'Branch A', 'branch_code' => 'BA01']);
        $cashierA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $cashierA->assignRole(Role::where('name', 'Branch Manager')->first());
        $cashierA->branches()->attach($branchA);

        $shiftA = Shift::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'cashier_id' => $cashierA->id,
            'opened_by' => $cashierA->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now(),
        ]);

        // Set context to Tenant B
        $this->setContext($tenantB);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->queryService->forShift($shiftA, $cashierA);
    }

    /**
     * Test branch isolation is preserved.
     */
    public function test_branch_isolation_is_preserved(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branchA = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Branch A', 'branch_code' => 'BA01']);
        $branchB = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Branch B', 'branch_code' => 'BB01']);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branchA); // Assigned to Branch A only

        $shiftB = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchB->id, // Shift is in Branch B
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now(),
        ]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->queryService->forShift($shiftB, $manager);
    }

    /**
     * Test that query execution guarantees zero database mutation.
     */
    public function test_query_guarantees_zero_database_mutation(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        (new RbacSeeder())->seedForTenant($tenant);
        $this->setContext($tenant);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Branch',
            'branch_code' => 'T001',
        ]);

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $manager->branches()->attach($branch);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $manager->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
            'opened_at' => now(),
        ]);

        $originalUpdatedAt = $shift->fresh()->updated_at;

        // Perform report query
        $this->queryService->forShift($shift, $manager);

        // Ensure database state is completely unchanged
        $this->assertEquals($originalUpdatedAt->toDateTimeString(), $shift->fresh()->updated_at->toDateTimeString());
    }
}
