<?php

namespace Tests\Feature\Dashboard;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\SettlementPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dashboard\DashboardQueryService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $owner;
    protected User $managerA;
    protected User $cashier;
    protected DashboardQueryService $dashboardService;
    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-12 14:00:00', 'Asia/Manila'));

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch B']);

        // Owner (Tenant Admin)
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branchA);
        $this->owner->assignToBranch($this->branchB);

        // Manager A (Branch scoped)
        $this->managerA = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->managerA->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->managerA->assignToBranch($this->branchA);

        // Cashier
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());

        $this->cash = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'CASH', 'name' => 'Cash', 'status' => 'active']);

        $this->dashboardService = app(DashboardQueryService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_view_tenant_wide_pulse(): void
    {
        Carbon::setTestNow('2026-05-12 14:00:00'); // Manila Time (approx UTC+6 in this test env usually, but we care about the service's use of Asia/Manila)
        
        $this->createSale($this->branchA, '100.00', '2026-05-12 10:00:00');
        $this->createSale($this->branchB, '50.00', '2026-05-12 11:00:00');

        $pulse = $this->dashboardService->getPulse($this->owner);

        $this->assertEquals('tenant', $pulse['scope']['mode']);
        $this->assertNull($pulse['scope']['branch_id']);
        $this->assertEquals('150.0000', $pulse['sales']['gross_sales_total']);
        $this->assertEquals(2, $pulse['sales']['sale_count']);
    }

    public function test_owner_can_filter_by_branch_with_multi_branch_permission(): void
    {
        $this->createSale($this->branchA, '100.00', '2026-05-12 10:00:00');
        $this->createSale($this->branchB, '50.00', '2026-05-12 11:00:00');

        $pulse = $this->dashboardService->getPulse($this->owner, $this->branchA->id);

        $this->assertEquals('branch', $pulse['scope']['mode']);
        $this->assertEquals($this->branchA->id, $pulse['scope']['branch_id']);
        $this->assertEquals('100.0000', $pulse['sales']['gross_sales_total']);
    }

    public function test_branch_manager_gets_scoped_pulse_by_default(): void
    {
        $this->createSale($this->branchA, '100.00', '2026-05-12 10:00:00');
        $this->createSale($this->branchB, '50.00', '2026-05-12 11:00:00');

        $pulse = $this->dashboardService->getPulse($this->managerA);

        $this->assertEquals('branch', $pulse['scope']['mode']);
        $this->assertEquals($this->branchA->id, $pulse['scope']['branch_id']);
        $this->assertEquals('100.0000', $pulse['sales']['gross_sales_total']);
    }

    public function test_branch_manager_cannot_view_unassigned_branch(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->dashboardService->getPulse($this->managerA, $this->branchB->id);
    }

    public function test_cashier_access_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->dashboardService->getPulse($this->cashier);
    }

    public function test_today_window_is_half_open_asia_manila(): void
    {
        // Asia/Manila 2026-05-12 00:00:00 is UTC 2026-05-11 16:00:00
        Carbon::setTestNow(Carbon::parse('2026-05-12 12:00:00', 'Asia/Manila'));

        // Edge Cases
        // 1. Exactly at start of day Manila (2026-05-12 00:00:00 Manila = 2026-05-11 16:00:00 UTC)
        $this->createSale($this->branchA, '10.00', Carbon::parse('2026-05-12 00:00:00', 'Asia/Manila'));
        
        // 2. Just before end of day Manila
        $this->createSale($this->branchA, '20.00', Carbon::parse('2026-05-12 23:59:59', 'Asia/Manila'));

        // 3. Just before start of day Manila (EXCLUDE)
        $this->createSale($this->branchA, '100.00', Carbon::parse('2026-05-11 23:59:59', 'Asia/Manila'));

        // 4. Exactly at start of next day Manila (EXCLUDE - half-open)
        $this->createSale($this->branchA, '200.00', Carbon::parse('2026-05-13 00:00:00', 'Asia/Manila'));

        $pulse = $this->dashboardService->getPulse($this->owner);

        $this->assertEquals('30.0000', $pulse['sales']['gross_sales_total']);
        $this->assertEquals(2, $pulse['sales']['sale_count']);
    }

    public function test_sales_and_payment_metrics_correctness(): void
    {
        Carbon::setTestNow('2026-05-12 14:00:00');
        
        // Gross: 100
        $sale1 = $this->createSale($this->branchA, '100.00', '2026-05-12 10:00:00');
        
        // Void: 20
        $sale2 = $this->createSale($this->branchA, '20.00', '2026-05-12 11:00:00');
        SaleVoid::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale2->id,
            'voided_at' => '2026-05-12 11:30:00',
            'voided_by' => $this->owner->id,
            'reason_code' => 'error'
        ]);

        // Refund: 10
        $sale3 = $this->createSale($this->branchA, '50.00', '2026-05-12 12:00:00');
        SaleRefund::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale3->id,
            'refund_total' => '10.00',
            'refunded_at' => '2026-05-12 12:30:00',
            'refunded_by' => $this->owner->id,
            'reason_code' => 'return'
        ]);

        $pulse = $this->dashboardService->getPulse($this->owner);

        $this->assertEquals('170.0000', $pulse['sales']['gross_sales_total']);
        $this->assertEquals('20.0000', $pulse['sales']['void_total']);
        $this->assertEquals('10.0000', $pulse['sales']['refund_total']);
        $this->assertEquals('140.0000', $pulse['sales']['net_sales_total']);
        $this->assertEquals('170.0000', $pulse['payments']['total']);
        $this->assertCount(1, $pulse['payments']['by_method']);
    }

    public function test_sync_health_metrics_correctness(): void
    {
        Carbon::setTestNow('2026-05-12 14:00:00');
        
        $this->createOutbox('pending', '2026-05-12 10:00:00');
        $this->createOutbox('failed', '2026-05-12 11:00:00');
        $this->createOutbox('synced', '2026-05-12 12:00:00');

        $pulse = $this->dashboardService->getPulse($this->owner);

        $this->assertEquals(1, $pulse['accounting_sync']['pending']);
        $this->assertEquals(1, $pulse['accounting_sync']['failed']);
        $this->assertEquals(1, $pulse['accounting_sync']['synced']);
    }

    public function test_inventory_metrics_correctness(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'is_inventory_tracked' => true]);
        
        // Low stock in Branch A
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'product_id' => $product->id,
            'current_stock' => '5.0000',
            'reorder_level' => '10.0000',
            'status' => 'active'
        ]);

        // Healthy stock in Branch B
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchB->id,
            'product_id' => $product->id,
            'current_stock' => '20.0000',
            'reorder_level' => '10.0000',
            'status' => 'active'
        ]);

        // Owner (Tenant wide)
        $pulse = $this->dashboardService->getPulse($this->owner);
        $this->assertEquals(1, $pulse['inventory']['low_stock_count']);
        $this->assertCount(1, $pulse['inventory']['critical_items']);
        $this->assertEquals($product->id, $pulse['inventory']['critical_items'][0]['product_id']);

        // Manager A
        $pulse = $this->dashboardService->getPulse($this->managerA);
        $this->assertEquals(1, $pulse['inventory']['low_stock_count']);

        // Filtered to Branch B
        $pulse = $this->dashboardService->getPulse($this->owner, $this->branchB->id);
        $this->assertEquals(0, $pulse['inventory']['low_stock_count']);
    }

    public function test_settlement_context_correctness(): void
    {
        Carbon::setTestNow('2026-05-12 14:00:00');
        
        // Create a locked period for Branch A
        $period = SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-10 00:00:00',
            'period_end_at' => '2026-05-10 23:59:59',
            'status' => 'locked',
            'locked_at' => '2026-05-11 09:00:00'
        ]);

        // Create an open period for "Yesterday" (May 11)
        SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-11 00:00:00',
            'period_end_at' => '2026-05-11 23:59:59',
            'status' => 'approved'
        ]);

        $pulse = $this->dashboardService->getPulse($this->owner, $this->branchA->id);

        $this->assertEquals($period->id, $pulse['settlement']['latest_locked_period_id']);
        $this->assertEquals('approved', $pulse['settlement']['yesterday_status']);
    }

    public function test_query_is_read_only_and_payload_matches_contract(): void
    {
        Carbon::setTestNow('2026-05-12 14:00:00');
        
        $pulse = $this->dashboardService->getPulse($this->owner);

        // Shape check
        $this->assertArrayHasKey('scope', $pulse);
        $this->assertArrayHasKey('window', $pulse);
        $this->assertArrayHasKey('sales', $pulse);
        $this->assertArrayHasKey('payments', $pulse);
        $this->assertArrayHasKey('accounting_sync', $pulse);
        $this->assertArrayHasKey('inventory', $pulse);
        $this->assertArrayHasKey('settlement', $pulse);
        $this->assertArrayHasKey('freshness', $pulse);

        // Monetary value check
        $this->assertIsString($pulse['sales']['gross_sales_total']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $pulse['sales']['gross_sales_total']);

        // No mutation check
        $this->assertDatabaseCount('audit_logs', 0); // Read-only dashboard viewing should not log
    }

    protected function createSale(Branch $branch, string $total, $confirmedAt): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $this->owner->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sale_number' => 'SALE-' . strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 8)),
            'status' => 'confirmed',
            'total' => $total,
            'confirmed_at' => $confirmedAt,
        ]);

        SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $this->cash->id,
            'payment_type' => 'full',
            'provider' => 'cashier',
            'amount' => $total,
            'status' => 'paid',
            'paid_at' => $confirmedAt,
        ]);

        return $sale;
    }

    protected function createOutbox(string $status, $createdAt): void
    {
        AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'payload' => ['total' => '1.00'],
            'sync_status' => $status,
            'created_at' => $createdAt
        ]);
    }
}
