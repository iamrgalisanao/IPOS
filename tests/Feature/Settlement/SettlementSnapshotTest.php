<?php

namespace Tests\Feature\Settlement;

use App\Models\AccountingOutbox;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\PaymentReversal;
use App\Models\Permission;
use App\Models\QuickBooksConnection;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\SettlementPeriod;
use App\Models\SettlementSnapshot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Settlement\SettlementPeriodService;
use App\Services\Settlement\SettlementSnapshotService;
use App\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettlementSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $accountant;
    protected SettlementPeriodService $periodService;
    protected SettlementSnapshotService $snapshotService;
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
        $this->snapshotService = app(SettlementSnapshotService::class);

        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_authorized_user_can_create_snapshot_for_branch_scoped_period(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->actingAs($this->accountant);
        $period = $this->createPeriod(['branch_id' => $this->branchA->id]);
        $this->seedSnapshotData();

        $snapshot = $this->snapshotService->create($period, $this->accountant);

        $this->assertSame($period->id, $snapshot->settlement_period_id);
        $this->assertSame($this->tenant->id, $snapshot->tenant_id);
        $this->assertSame($this->branchA->id, $snapshot->branch_id);
        $this->assertSame(SettlementSnapshot::TYPE_REVIEW, $snapshot->snapshot_type);
        $this->assertSame('90.0000', $snapshot->summary_payload['payments']['total']);
        $this->assertSame(2, $snapshot->variance_payload['summary']['total_variance_count']);
    }

    public function test_authorized_user_can_create_snapshot_for_tenant_wide_period(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->actingAs($this->accountant);
        $period = $this->createPeriod(['branch_id' => null]);
        $this->seedSnapshotData();

        $snapshot = $this->snapshotService->create($period, $this->accountant);

        $this->assertNull($snapshot->branch_id);
        $this->assertSame($period->id, $snapshot->settlement_period_id);
        $this->assertArrayHasKey('summary', $snapshot->variance_payload);
    }

    public function test_branch_scoped_user_cannot_snapshot_tenant_wide_period_without_permission(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->actingAs($this->accountant);
        $period = $this->createPeriod(['branch_id' => null]);
        $viewer = $this->createBranchScopedSettlementManager($this->tenant, $this->branchA);
        $this->actingAs($viewer);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->expectException(AuthorizationException::class);
        $this->snapshotService->create($period, $viewer);
    }

    public function test_tenant_a_cannot_snapshot_tenant_b_period(): void
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
        $this->snapshotService->create($visible, $this->accountant);
    }

    public function test_snapshot_preserves_decimal_strings_and_is_append_only(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->actingAs($this->accountant);
        $period = $this->createPeriod();
        $this->seedSnapshotData();
        $snapshot = $this->snapshotService->create($period, $this->accountant);

        $this->assertIsString($snapshot->summary_payload['sales']['gross_sales_total']);
        $this->assertIsString($snapshot->variance_payload['items'][0]['amount']);

        $this->expectException(\RuntimeException::class);
        $snapshot->update(['snapshot_type' => SettlementSnapshot::TYPE_PRE_LOCK]);
    }

    public function test_multiple_snapshots_can_be_created_for_same_period(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->actingAs($this->accountant);
        $period = $this->createPeriod();
        $this->seedSnapshotData();

        $first = $this->snapshotService->create($period, $this->accountant);
        $second = $this->snapshotService->create($period, $this->accountant, SettlementSnapshot::TYPE_PRE_LOCK);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, SettlementSnapshot::count());
        $this->assertSame(SettlementSnapshot::TYPE_PRE_LOCK, $second->snapshot_type);
    }

    public function test_snapshot_creation_does_not_change_period_or_source_records_and_logs_audit(): void
    {
        Http::fake();
        app(TenantContext::class)->setTenant($this->tenant);
        $this->actingAs($this->accountant);
        $period = $this->createPeriod();
        $this->seedSnapshotData();
        $periodStatusBefore = $period->status;
        $countsBefore = $this->businessCounts();
        $outboxBefore = AccountingOutbox::count();

        $snapshot = $this->snapshotService->create($period, $this->accountant);

        $this->assertSame($periodStatusBefore, $period->fresh()->status);
        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, AccountingOutbox::count());
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'settlement_snapshot_created',
            'auditable_id' => $snapshot->id,
        ]);
        $log = AuditLog::where('action', 'settlement_snapshot_created')->firstOrFail();
        $this->assertSame($period->id, $log->after_values['settlement_period_id']);
        Http::assertNothingSent();
    }

    public function test_snapshot_cannot_be_deleted(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->actingAs($this->accountant);
        $period = $this->createPeriod();
        $this->seedSnapshotData();
        $snapshot = $this->snapshotService->create($period, $this->accountant);

        $this->expectException(\RuntimeException::class);
        $snapshot->delete();
    }

    protected function seedSnapshotData(): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'user_id' => $this->accountant->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sale_number' => 'SALE-' . strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 8)),
            'status' => 'confirmed',
            'subtotal' => '100.0000',
            'tax_total' => '0.0000',
            'discount_total' => '0.0000',
            'total' => '100.0000',
            'confirmed_at' => '2026-05-12 09:00:00',
        ]);

        SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $this->cash->id,
            'payment_type' => 'full',
            'provider' => 'cashier',
            'amount' => '90.0000',
            'reference_number' => null,
            'status' => 'paid',
            'paid_at' => '2026-05-12 09:00:00',
            'created_by' => $this->accountant->id,
        ]);

        DB::table('accounting_outbox')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'payload' => json_encode(['total' => '100.0000'], JSON_THROW_ON_ERROR),
            'sync_status' => 'pending',
            'attempt_count' => 0,
            'available_at' => '2026-05-12 09:15:00',
            'created_at' => '2026-05-12 09:15:00',
            'updated_at' => '2026-05-12 09:15:00',
        ]);
    }

    protected function createPeriod(array $overrides = []): SettlementPeriod
    {
        app(TenantContext::class)->setTenant($this->tenant);

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
            'name' => 'Branch Settlement Snapshot Manager',
            'description' => 'Branch-scoped settlement snapshot access',
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
            'quickbooks_connections' => QuickBooksConnection::count(),
        ];
    }
}