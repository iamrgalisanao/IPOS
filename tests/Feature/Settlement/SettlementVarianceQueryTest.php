<?php

namespace Tests\Feature\Settlement;

use App\Models\AccountingMapping;
use App\Models\AccountingOutbox;
use App\Models\BranchInventory;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\PaymentReversal;
use App\Models\Permission;
use App\Models\Product;
use App\Models\QuickBooksConnection;
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
use App\Services\Settlement\SettlementVarianceQueryService;
use App\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettlementVarianceQueryTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $accountant;
    protected SettlementPeriodService $periodService;
    protected SettlementVarianceQueryService $varianceService;
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
        $this->varianceService = app(SettlementVarianceQueryService::class);

        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_pending_outbox_creates_timing_gap(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        $this->createOutbox($this->branchA, 'pending', '2026-05-12 09:00:00', ['total' => '112.0000']);

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(1, $summary['summary']['by_category']['timing_gap']);
        $this->assertSame('timing_gap', $summary['items'][0]['category']);
        $this->assertSame('112.0000', $summary['items'][0]['amount']);
    }

    public function test_processing_outbox_creates_timing_gap(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        $this->createOutbox($this->branchA, 'processing', '2026-05-12 09:00:00', ['total' => '44.0000']);

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(1, $summary['summary']['by_category']['timing_gap']);
        $this->assertSame('44.0000', $summary['items'][0]['amount']);
    }

    public function test_failed_outbox_creates_sync_failure(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        $this->createOutbox($this->branchA, 'failed', '2026-05-12 09:00:00', ['total' => '55.0000'], 'system', 'network timeout');

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(1, $summary['summary']['by_category']['sync_failure']);
        $this->assertSame('sync_failure', $summary['items'][0]['category']);
    }

    public function test_missing_mapping_creates_mapping_gap(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        $this->createOutbox($this->branchA, 'failed', '2026-05-12 09:00:00', ['total' => '90.0000'], 'mapping', 'Missing mapping for payment method');

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(1, $summary['summary']['by_category']['mapping_gap']);
        $this->assertSame('mapping_gap', $summary['items'][0]['category']);
    }

    public function test_disconnected_expired_or_error_connection_creates_connection_gap(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        DB::table('quickbooks_connections')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'realm_id' => '12345',
            'company_name' => 'Tenant QB',
            'status' => QuickBooksConnection::STATUS_ERROR,
            'connected_at' => '2026-05-11 12:00:00',
            'last_error' => 'OAuth failure',
            'updated_at' => '2026-05-12 10:00:00',
            'created_at' => '2026-05-11 12:00:00',
        ]);

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(1, $summary['summary']['by_category']['connection_gap']);
        $this->assertSame('connection_gap', $summary['items'][0]['category']);
    }

    public function test_payment_total_mismatch_creates_payment_mismatch(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        $sale = $this->createSale($this->branchA, '100.0000', '2026-05-12 09:00:00');
        $this->createPayment($sale, $this->cash->id, '90.0000', '2026-05-12 09:00:00');

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(1, $summary['summary']['by_category']['payment_mismatch']);
        $this->assertSame('10.0000', $summary['items'][0]['amount']);
    }

    public function test_refund_reversal_mismatch_creates_refund_mismatch(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        $sale = $this->createSale($this->branchA, '100.0000', '2026-05-12 08:00:00');
        $payment = $this->createPayment($sale, $this->cash->id, '100.0000', '2026-05-12 08:00:00');
        $refund = SaleRefund::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'refund_number' => 'REF-001',
            'reason_code' => 'return',
            'reason_notes' => 'Partial return',
            'refund_total' => '10.0000',
            'refunded_by' => $this->accountant->id,
            'refunded_at' => '2026-05-12 10:00:00',
        ]);
        PaymentReversal::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'refund_reversal',
            'amount' => '7.0000',
            'reason_code' => 'return',
            'reason_notes' => 'Partial return',
            'reversed_by' => $this->accountant->id,
            'reversed_at' => '2026-05-12 10:05:00',
        ]);

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(1, $summary['summary']['by_category']['refund_mismatch']);
        $this->assertSame($refund->id, $summary['items'][0]['source_id']);
        $this->assertSame('3.0000', $summary['items'][0]['amount']);
    }

    public function test_void_reversal_mismatch_creates_void_mismatch(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        $sale = $this->createSale($this->branchA, '40.0000', '2026-05-12 08:00:00');
        $payment = $this->createPayment($sale, $this->card->id, '40.0000', '2026-05-12 08:00:00');
        $void = SaleVoid::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'reason_code' => 'mistake',
            'reason_notes' => 'Void requested',
            'voided_by' => $this->accountant->id,
            'voided_at' => '2026-05-12 11:00:00',
        ]);
        $this->createInventoryMovement($sale->id, 'sale', 'sale_deduction', '-1.0000');
        PaymentReversal::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'void_reversal',
            'amount' => '15.0000',
            'reason_code' => 'mistake',
            'reason_notes' => 'Incomplete void reversal',
            'reversed_by' => $this->accountant->id,
            'reversed_at' => '2026-05-12 11:05:00',
        ]);

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(1, $summary['summary']['by_category']['void_mismatch']);
        $this->assertSame($void->id, $summary['items'][0]['source_id']);
        $this->assertSame('25.0000', $summary['items'][0]['amount']);
    }

    public function test_manual_review_category_is_produced_for_uncategorized_anomaly(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        $sale = $this->createSale($this->branchA, '20.0000', '2026-05-12 08:00:00');
        $payment = $this->createPayment($sale, $this->cash->id, '20.0000', '2026-05-12 08:00:00');
        PaymentReversal::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'chargeback_review',
            'amount' => '5.0000',
            'reason_code' => 'chargeback',
            'reason_notes' => 'Requires review',
            'reversed_by' => $this->accountant->id,
            'reversed_at' => '2026-05-12 12:00:00',
        ]);

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(1, $summary['summary']['by_category']['manual_review_required']);
        $this->assertSame('manual_review_required', $summary['items'][0]['category']);
    }

    public function test_branch_scoped_period_includes_only_branch_records(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => $this->branchA->id]);
        $this->seedBranchScopedVarianceData();

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(5, $summary['summary']['total_variance_count']);
        $this->assertSame(1, $summary['summary']['by_category']['timing_gap']);
        $this->assertSame(1, $summary['summary']['by_category']['sync_failure']);
        $this->assertSame(1, $summary['summary']['by_category']['payment_mismatch']);
        $this->assertSame(1, $summary['summary']['by_category']['refund_mismatch']);
        $this->assertSame(1, $summary['summary']['by_category']['void_mismatch']);
    }

    public function test_tenant_wide_period_includes_permitted_branch_records(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => null]);
        $this->seedBranchScopedVarianceData();
        $this->createOutbox($this->branchB, 'processing', '2026-05-12 13:00:00', ['total' => '50.0000']);

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertSame(8, $summary['summary']['total_variance_count']);
        $this->assertSame(2, $summary['summary']['by_category']['timing_gap']);
        $this->assertSame(2, $summary['summary']['by_category']['sync_failure']);
        $this->assertSame(2, $summary['summary']['by_category']['payment_mismatch']);
    }

    public function test_branch_scoped_user_cannot_query_tenant_wide_variance_summary_without_permission(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => null]);
        $viewer = $this->createBranchScopedSettlementManager($this->tenant, $this->branchA);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->expectException(AuthorizationException::class);
        $this->varianceService->summarize($period, $viewer);
    }

    public function test_tenant_a_cannot_query_tenant_b_period_variance_summary(): void
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
        $this->varianceService->summarize($visible, $this->accountant);
    }

    public function test_variance_amounts_are_decimal_strings_and_query_is_read_only(): void
    {
        Http::fake();
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        $sale = $this->createSale($this->branchA, '100.0000', '2026-05-12 09:00:00');
        $this->createPayment($sale, $this->cash->id, '95.0000', '2026-05-12 09:00:00');
        $countsBefore = $this->businessCounts();
        $outboxBefore = AccountingOutbox::count();
        $mappingBefore = AccountingMapping::count();
        $connectionBefore = QuickBooksConnection::count();

        $summary = $this->varianceService->summarize($period, $this->accountant);

        $this->assertIsString($summary['items'][0]['amount']);
        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, AccountingOutbox::count());
        $this->assertSame($mappingBefore, AccountingMapping::count());
        $this->assertSame($connectionBefore, QuickBooksConnection::count());
        $this->assertFalse(Schema::hasTable('settlement_variances'));
        $this->assertDatabaseCount('settlement_snapshots', 0);
        Http::assertNothingSent();
    }

    protected function seedBranchScopedVarianceData(): void
    {
        $sale = $this->createSale($this->branchA, '100.0000', '2026-05-12 08:00:00');
        $payment = $this->createPayment($sale, $this->cash->id, '90.0000', '2026-05-12 08:00:00');
        $refund = SaleRefund::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'refund_number' => 'REF-A',
            'reason_code' => 'return',
            'reason_notes' => 'Return',
            'refund_total' => '10.0000',
            'refunded_by' => $this->accountant->id,
            'refunded_at' => '2026-05-12 09:00:00',
        ]);
        PaymentReversal::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'reversal_type' => 'refund_reversal',
            'amount' => '7.0000',
            'reason_code' => 'return',
            'reason_notes' => 'Return',
            'reversed_by' => $this->accountant->id,
            'reversed_at' => '2026-05-12 09:05:00',
        ]);

        $voidSale = $this->createSale($this->branchA, '40.0000', '2026-05-12 10:00:00');
        $voidPayment = $this->createPayment($voidSale, $this->card->id, '40.0000', '2026-05-12 10:00:00');
        $void = SaleVoid::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $voidSale->id,
            'reason_code' => 'mistake',
            'reason_notes' => 'Void',
            'voided_by' => $this->accountant->id,
            'voided_at' => '2026-05-12 10:30:00',
        ]);
        $this->createInventoryMovement($voidSale->id, 'sale', 'sale_deduction', '-1.0000');
        PaymentReversal::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $voidSale->id,
            'sale_payment_id' => $voidPayment->id,
            'reversal_type' => 'void_reversal',
            'amount' => '15.0000',
            'reason_code' => 'mistake',
            'reason_notes' => 'Incomplete',
            'reversed_by' => $this->accountant->id,
            'reversed_at' => '2026-05-12 10:35:00',
        ]);

        $this->createOutbox($this->branchA, 'pending', '2026-05-12 08:30:00', ['total' => '100.0000']);
        $this->createOutbox($this->branchA, 'failed', '2026-05-12 10:40:00', ['total' => '40.0000'], 'system', 'network timeout');

        $branchBSale = $this->createSale($this->branchB, '60.0000', '2026-05-12 12:00:00');
        $this->createPayment($branchBSale, $this->cash->id, '50.0000', '2026-05-12 12:00:00');
        $this->createOutbox($this->branchB, 'failed', '2026-05-12 12:30:00', ['total' => '60.0000'], 'system', 'network timeout');

        $this->createOutbox($this->branchA, 'pending', '2026-05-13 01:00:00', ['total' => '999.0000']);
        $this->createOutbox($this->branchA, 'failed', '2026-05-11 23:00:00', ['total' => '999.0000'], 'system', 'network timeout');

        $this->assertNotNull($refund);
        $this->assertNotNull($void);
    }

    protected function createSale(Branch $branch, string $total, string $confirmedAt): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $this->accountant->id,
            'client_request_uuid' => (string) Str::uuid(),
            'sale_number' => 'SALE-' . strtoupper(substr((string) Str::uuid(), 0, 8)),
            'status' => 'confirmed',
            'subtotal' => $total,
            'tax_total' => '0.0000',
            'discount_total' => '0.0000',
            'total' => $total,
            'confirmed_at' => $confirmedAt,
        ]);
    }

    protected function createPayment(Sale $sale, string $paymentMethodId, string $amount, string $paidAt): SalePayment
    {
        return SalePayment::create([
            'tenant_id' => $sale->tenant_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'payment_method_id' => $paymentMethodId,
            'payment_type' => 'full',
            'provider' => 'cashier',
            'amount' => $amount,
            'reference_number' => null,
            'status' => 'paid',
            'paid_at' => $paidAt,
            'created_by' => $this->accountant->id,
        ]);
    }

    protected function createOutbox(
        Branch $branch,
        string $status,
        string $createdAt,
        array $payload = ['total' => '1.0000'],
        ?string $syncErrorCategory = null,
        ?string $syncError = null
    ): void {
        DB::table('accounting_outbox')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) Str::uuid(),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'sync_status' => $status,
            'sync_error' => $syncError,
            'sync_error_category' => $syncErrorCategory,
            'attempt_count' => 0,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function createInventoryMovement(string $sourceId, string $sourceType, string $movementType, string $quantityChange): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_inventory_tracked' => true,
            'status' => 'active',
        ]);
        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'product_id' => $product->id,
            'current_stock' => '5.0000',
            'reorder_level' => '1.0000',
            'status' => 'active',
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'product_id' => $product->id,
            'branch_inventory_id' => $inventory->id,
            'original_movement_id' => null,
            'movement_type' => $movementType,
            'quantity_change' => $quantityChange,
            'quantity_before' => '5.0000',
            'quantity_after' => '4.0000',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reference_number' => 'REF-' . strtoupper(substr((string) Str::uuid(), 0, 6)),
            'user_id' => $this->accountant->id,
            'reason_code' => 'test',
            'remarks' => 'Variance test movement',
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
            'name' => 'Branch Settlement Variance Manager',
            'description' => 'Branch-scoped settlement variance access',
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
            'payment_reversals' => PaymentReversal::count(),
            'inventory_movements' => InventoryMovement::count(),
            'sale_refunds' => SaleRefund::count(),
            'sale_voids' => SaleVoid::count(),
        ];
    }
}