<?php

namespace Tests\Feature\Settlement;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\SettlementPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Settlement\SettlementPeriodService;
use App\Services\Settlement\SettlementSummaryQueryService;
use App\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SettlementSummaryQueryTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $accountant;
    protected SettlementPeriodService $periodService;
    protected SettlementSummaryQueryService $summaryService;
    protected PaymentMethod $cash;
    protected PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);

        app(TenantContext::class)->setTenant($this->tenant);
        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch B']);

        $this->accountant = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->accountant->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $this->accountant->assignToBranch($this->branchA);
        $this->accountant->assignToBranch($this->branchB);

        $this->cash = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'status' => 'active',
        ]);
        $this->card = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARD',
            'name' => 'Card',
            'status' => 'active',
        ]);

        $this->periodService = app(SettlementPeriodService::class);
        $this->summaryService = app(SettlementSummaryQueryService::class);

        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_branch_scoped_summary_includes_only_branch_records(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => $this->branchA->id]);

        $this->seedBranchPeriodRecords();

        $summary = $this->summaryService->summarize($period, $this->accountant);

        $this->assertSame('120.0000', $summary['sales']['gross_sales_total']);
        $this->assertSame('90.0000', $summary['sales']['net_sales_total']);
        $this->assertSame(2, $summary['sales']['sale_count']);
        $this->assertSame('20.0000', $summary['sales']['void_total']);
        $this->assertSame(1, $summary['sales']['void_count']);
        $this->assertSame('10.0000', $summary['sales']['refund_total']);
        $this->assertSame(1, $summary['sales']['refund_count']);
        $this->assertSame('120.0000', $summary['payments']['total']);
        $this->assertSame(1, $summary['accounting_sync']['pending']);
        $this->assertSame(1, $summary['accounting_sync']['synced']);
        $this->assertSame(1, $summary['accounting_sync']['failed']);
        $this->assertSame(0, $summary['accounting_sync']['processing']);
    }

    public function test_tenant_wide_summary_includes_all_permitted_branch_records(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod([
            'branch_id' => null,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ]);

        $this->seedBranchPeriodRecords();

        $summary = $this->summaryService->summarize($period, $this->accountant);

        $this->assertSame('180.0000', $summary['sales']['gross_sales_total']);
        $this->assertSame('150.0000', $summary['sales']['net_sales_total']);
        $this->assertSame(3, $summary['sales']['sale_count']);
        $this->assertSame('20.0000', $summary['sales']['void_total']);
        $this->assertSame('10.0000', $summary['sales']['refund_total']);
        $this->assertSame('180.0000', $summary['payments']['total']);
        $this->assertCount(2, $summary['payments']['by_method']);
        $this->assertSame(2, $summary['accounting_sync']['pending']);
        $this->assertSame(1, $summary['accounting_sync']['processing']);
        $this->assertSame(1, $summary['accounting_sync']['synced']);
        $this->assertSame(1, $summary['accounting_sync']['failed']);
    }

    public function test_branch_scoped_user_cannot_summarize_tenant_wide_period_without_permission(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => null]);
        $viewer = $this->createBranchScopedSettlementManager($this->tenant, $this->branchA);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->expectException(AuthorizationException::class);
        $this->summaryService->summarize($period, $viewer);
    }

    public function test_tenant_a_cannot_summarize_tenant_b_period(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $userB->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $userB->assignToBranch($branchB);
        $periodB = $this->periodService->create([
            'branch_id' => $branchB->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $userB);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->expectException(ModelNotFoundException::class);
        $visible = $this->periodService->findVisible($periodB->id, $this->accountant);
        $this->summaryService->summarize($visible, $this->accountant);
    }

    public function test_summary_uses_period_boundaries_and_returns_decimal_strings(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => $this->branchA->id]);
        $this->seedBranchPeriodRecords();

        $summary = $this->summaryService->summarize($period, $this->accountant);

        $this->assertIsString($summary['sales']['gross_sales_total']);
        $this->assertIsString($summary['sales']['net_sales_total']);
        $this->assertIsString($summary['sales']['void_total']);
        $this->assertIsString($summary['sales']['refund_total']);
        $this->assertIsString($summary['payments']['total']);
        $this->assertSame('120.0000', $summary['sales']['gross_sales_total']);
    }

    public function test_summary_query_is_read_only_and_creates_no_side_effects(): void
    {
        Http::fake();
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => $this->branchA->id]);
        $this->seedBranchPeriodRecords();
        $countsBefore = $this->businessCounts();
        $outboxBefore = $this->outboxCount();

        $summary = $this->summaryService->summarize($period, $this->accountant);

        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, $this->outboxCount());
        $this->assertDatabaseCount('settlement_snapshots', 0);
        $this->assertFalse(Schema::hasTable('settlement_variances'));
        $this->assertSame('120.0000', $summary['payments']['total']);
        Http::assertNothingSent();
    }

    protected function seedBranchPeriodRecords(): void
    {
        $this->createSaleWithPayment($this->branchA, '100.0000', $this->cash->id, '2026-05-12 09:00:00');
        $voidSale = $this->createSaleWithPayment($this->branchA, '20.0000', $this->card->id, '2026-05-12 10:00:00');
        SaleVoid::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $voidSale->id,
            'reason_code' => 'mistake',
            'reason_notes' => 'Voided during review',
            'voided_by' => $this->accountant->id,
            'voided_at' => '2026-05-12 10:30:00',
        ]);

        $refundSale = $this->createSaleWithPayment($this->branchA, '40.0000', $this->cash->id, '2026-05-11 12:00:00');
        SaleRefund::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $refundSale->id,
            'refund_number' => 'REF-001',
            'reason_code' => 'return',
            'reason_notes' => 'Partial return',
            'refund_total' => '10.0000',
            'refunded_by' => $this->accountant->id,
            'refunded_at' => '2026-05-12 11:00:00',
        ]);

        $this->createSaleWithPayment($this->branchB, '60.0000', $this->card->id, '2026-05-12 14:00:00');
        $this->createSaleWithPayment($this->branchA, '999.0000', $this->cash->id, '2026-05-13 01:00:00');

        $this->createOutbox($this->branchA, 'pending', '2026-05-12 09:15:00');
        $this->createOutbox($this->branchA, 'synced', '2026-05-12 09:20:00');
        $this->createOutbox($this->branchA, 'failed', '2026-05-12 09:25:00');
        $this->createOutbox($this->branchB, 'pending', '2026-05-12 14:10:00');
        $this->createOutbox($this->branchB, 'processing', '2026-05-12 14:15:00');
        $this->createOutbox($this->branchA, 'pending', '2026-05-13 01:10:00');
    }

    protected function createSaleWithPayment(Branch $branch, string $total, string $paymentMethodId, string $confirmedAt): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $this->accountant->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sale_number' => 'SALE-' . strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 8)),
            'status' => 'confirmed',
            'subtotal' => $total,
            'tax_total' => '0.0000',
            'discount_total' => '0.0000',
            'total' => $total,
            'confirmed_at' => $confirmedAt,
        ]);

        SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $paymentMethodId,
            'payment_type' => 'full',
            'provider' => 'cashier',
            'amount' => $total,
            'reference_number' => null,
            'status' => 'paid',
            'paid_at' => $confirmedAt,
            'created_by' => $this->accountant->id,
        ]);

        return $sale;
    }

    protected function createOutbox(Branch $branch, string $status, string $createdAt): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $sourceId = (string) \Illuminate\Support\Str::uuid();

        DB::table('accounting_outbox')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => $sourceId,
            'payload' => json_encode(['total' => '1.0000'], JSON_THROW_ON_ERROR),
            'sync_status' => $status,
            'attempt_count' => 0,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function createPeriod(array $overrides = []): SettlementPeriod
    {
        return $this->periodService->create(array_merge([
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $overrides), $this->accountant);
    }

    protected function createBranchScopedSettlementManager(Tenant $tenant, Branch $branch): User
    {
        app(TenantContext::class)->setTenant($tenant);

        $role = Role::create([
            'name' => 'Branch Settlement Summary Manager',
            'description' => 'Branch-scoped settlement summary access',
        ]);
        $permission = Permission::where('name', 'manage_settlement_periods')->firstOrFail();
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $user->assignRole($role);
        $user->assignToBranch($branch);

        app(TenantContext::class)->clear();

        return $user;
    }

    protected function businessCounts(): array
    {
        return [
            'sales' => Sale::count(),
            'sale_payments' => SalePayment::count(),
            'inventory_movements' => InventoryMovement::count(),
            'sale_refunds' => SaleRefund::count(),
            'sale_voids' => SaleVoid::count(),
        ];
    }

    protected function outboxCount(): int
    {
        return AccountingOutbox::count();
    }
}