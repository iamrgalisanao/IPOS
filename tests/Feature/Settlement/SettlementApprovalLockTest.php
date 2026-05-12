<?php

namespace Tests\Feature\Settlement;

use App\Models\AccountingOutbox;
use App\Models\AuditLog;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SettlementApprovalLockTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $accountant;
    protected SettlementPeriodService $periodService;
    protected SettlementSnapshotService $snapshotService;
    protected PaymentMethod $cash;

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

        $this->periodService = app(SettlementPeriodService::class);
        $this->snapshotService = app(SettlementSnapshotService::class);

        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_authorized_user_can_approve_an_in_review_period(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->inReviewPeriod();

        $approved = $this->periodService->approve($period, $this->accountant);

        $this->assertSame(SettlementPeriod::STATUS_APPROVED, $approved->status);
        $this->assertSame($this->accountant->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'settlement_period_approved',
            'auditable_id' => $approved->id,
        ]);
    }

    public function test_unauthorized_user_cannot_approve(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->inReviewPeriod();
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);

        $this->expectException(AuthorizationException::class);
        $this->periodService->approve($period, $user);
    }

    public function test_open_period_cannot_be_locked_directly(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        $this->expectException(ValidationException::class);
        $this->periodService->lock($period, $this->accountant);
    }

    public function test_approved_period_cannot_be_locked_without_snapshot(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->approvedPeriod();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Settlement period must have a review snapshot before locking.');
        $this->periodService->lock($period, $this->accountant);
    }

    public function test_approved_period_can_be_locked_with_snapshot(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->approvedPeriod();
        $snapshot = $this->snapshotService->create($period, $this->accountant);

        $locked = $this->periodService->lock($period, $this->accountant, 'Reviewed and locked');

        $this->assertSame(SettlementPeriod::STATUS_LOCKED, $locked->status);
        $this->assertSame($this->accountant->id, $locked->locked_by);
        $this->assertNotNull($locked->locked_at);
        $audit = AuditLog::where('action', 'settlement_period_locked')->latest('created_at')->firstOrFail();
        $this->assertSame($snapshot->id, $audit->metadata['lock_snapshot_id']);
    }

    public function test_locked_period_cannot_be_approved_again(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->approvedPeriod();
        $this->snapshotService->create($period, $this->accountant);
        $period = $this->periodService->lock($period, $this->accountant);

        $this->expectException(ValidationException::class);
        $this->periodService->approve($period, $this->accountant);
    }

    public function test_tenant_a_cannot_approve_or_lock_tenant_b_period(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $userB->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $userB->assignToBranch($branchB);
        $foreignPeriod = $this->periodService->create([
            'branch_id' => $branchB->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $userB);
        $foreignPeriod = $this->periodService->markInReview($foreignPeriod, $userB);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->expectException(ModelNotFoundException::class);
        $this->periodService->findVisible($foreignPeriod->id, $this->accountant);
    }

    public function test_branch_a_cannot_approve_or_lock_branch_b_period_without_permission(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $viewer = $this->createBranchScopedSettlementManager($this->tenant, $this->branchA);
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod([
            'branch_id' => $this->branchB->id,
            'period_start_at' => '2026-05-13 00:00:00',
            'period_end_at' => '2026-05-13 23:59:59',
        ]);
        $period = $this->periodService->markInReview($period, $this->accountant);

        $this->expectException(AuthorizationException::class);
        $this->periodService->approve($period, $viewer);
    }

    public function test_approval_and_lock_do_not_mutate_source_records_or_call_providers(): void
    {
        Http::fake();
        app(TenantContext::class)->setTenant($this->tenant);
        $this->seedSourceRecords();
        $period = $this->inReviewPeriod();
        $countsBeforeApproval = $this->businessCounts();
        $outboxBeforeApproval = AccountingOutbox::count();

        $period = $this->periodService->approve($period, $this->accountant);

        $this->assertSame($countsBeforeApproval, $this->businessCounts());
        $this->assertSame($outboxBeforeApproval, AccountingOutbox::count());

        $this->snapshotService->create($period, $this->accountant);
        $countsBeforeLock = $this->businessCounts();
        $outboxBeforeLock = AccountingOutbox::count();

        $period = $this->periodService->lock($period, $this->accountant);

        $this->assertSame(SettlementPeriod::STATUS_LOCKED, $period->status);
        $this->assertSame($countsBeforeLock, $this->businessCounts());
        $this->assertSame($outboxBeforeLock, AccountingOutbox::count());
        Http::assertNothingSent();
    }

    protected function createPeriod(array $overrides = []): SettlementPeriod
    {
        return $this->periodService->create(array_merge([
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $overrides), $this->accountant);
    }

    protected function inReviewPeriod(): SettlementPeriod
    {
        $period = $this->createPeriod();

        return $this->periodService->markInReview($period, $this->accountant);
    }

    protected function approvedPeriod(): SettlementPeriod
    {
        $period = $this->inReviewPeriod();

        return $this->periodService->approve($period, $this->accountant);
    }

    protected function createBranchScopedSettlementManager(Tenant $tenant, Branch $branch): User
    {
        app(TenantContext::class)->setTenant($tenant);

        $role = Role::create([
            'name' => 'Branch Settlement Approval Manager',
            'description' => 'Branch-scoped settlement approval access',
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

    protected function seedSourceRecords(): void
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
            'amount' => '100.0000',
            'reference_number' => null,
            'status' => 'paid',
            'paid_at' => '2026-05-12 09:00:00',
            'created_by' => $this->accountant->id,
        ]);

        SaleRefund::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'refund_number' => 'REF-001',
            'reason_code' => 'return',
            'reason_notes' => 'Test refund',
            'refund_total' => '10.0000',
            'refunded_by' => $this->accountant->id,
            'refunded_at' => '2026-05-12 10:00:00',
        ]);

        SaleVoid::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'sale_id' => $sale->id,
            'reason_code' => 'mistake',
            'reason_notes' => 'Test void',
            'voided_by' => $this->accountant->id,
            'voided_at' => '2026-05-12 11:00:00',
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'product_id' => \App\Models\Product::factory()->create([
                'tenant_id' => $this->tenant->id,
                'status' => 'active',
                'is_inventory_tracked' => true,
            ])->id,
            'branch_inventory_id' => \App\Models\BranchInventory::create([
                'tenant_id' => $this->tenant->id,
                'branch_id' => $this->branchA->id,
                'product_id' => \App\Models\Product::factory()->create([
                    'tenant_id' => $this->tenant->id,
                    'status' => 'active',
                    'is_inventory_tracked' => true,
                ])->id,
                'current_stock' => '5.0000',
                'reorder_level' => '1.0000',
                'status' => 'active',
            ])->id,
            'original_movement_id' => null,
            'movement_type' => 'sale_deduction',
            'quantity_change' => '-1.0000',
            'quantity_before' => '5.0000',
            'quantity_after' => '4.0000',
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'reference_number' => 'REF-LOCK-1',
            'user_id' => $this->accountant->id,
            'reason_code' => 'test',
            'remarks' => 'Approval lock seed',
        ]);
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
}