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
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SettlementPeriodLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $accountant;
    protected SettlementPeriodService $service;
    protected SettlementSnapshotService $snapshotService;

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

        $this->service = app(SettlementPeriodService::class);
        $this->snapshotService = app(SettlementSnapshotService::class);

        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_tenant_wide_settlement_period_can_be_created_by_authorized_user(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $period = $this->service->create([
            'branch_id' => null,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $this->accountant);

        $this->assertSame(SettlementPeriod::STATUS_OPEN, $period->status);
        $this->assertNull($period->branch_id);
        $this->assertSame($this->accountant->id, $period->opened_by);
        $this->assertNotNull($period->opened_at);
    }

    public function test_branch_scoped_settlement_period_can_be_created(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $period = $this->service->create([
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $this->accountant);

        $this->assertSame($this->branchA->id, $period->branch_id);
        $this->assertSame(SettlementPeriod::STATUS_OPEN, $period->status);
    }

    public function test_branch_scoped_user_cannot_create_tenant_wide_period_without_permission(): void
    {
        $viewer = $this->createBranchScopedSettlementManager($this->tenant, $this->branchA);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->expectException(AuthorizationException::class);
        $this->service->create([
            'branch_id' => null,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $viewer);
    }

    public function test_period_start_must_be_before_period_end(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $this->expectException(ValidationException::class);
        $this->service->create([
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-12 23:59:59',
            'period_end_at' => '2026-05-12 00:00:00',
        ], $this->accountant);
    }

    public function test_overlapping_tenant_wide_periods_are_rejected(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->createPeriod(['branch_id' => null, 'period_start_at' => '2026-05-12 00:00:00', 'period_end_at' => '2026-05-12 12:00:00']);

        $this->expectException(ValidationException::class);
        $this->service->create([
            'branch_id' => null,
            'period_start_at' => '2026-05-12 11:00:00',
            'period_end_at' => '2026-05-12 13:00:00',
        ], $this->accountant);
    }

    public function test_overlapping_branch_scoped_periods_are_rejected_for_same_branch(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->createPeriod(['branch_id' => $this->branchA->id, 'period_start_at' => '2026-05-12 00:00:00', 'period_end_at' => '2026-05-12 12:00:00']);

        $this->expectException(ValidationException::class);
        $this->service->create([
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-12 11:00:00',
            'period_end_at' => '2026-05-12 13:00:00',
        ], $this->accountant);
    }

    public function test_same_date_range_is_allowed_for_different_branches(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $this->createPeriod(['branch_id' => $this->branchA->id, 'period_start_at' => '2026-05-12 00:00:00', 'period_end_at' => '2026-05-12 23:59:59']);

        $period = $this->service->create([
            'branch_id' => $this->branchB->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $this->accountant);

        $this->assertSame($this->branchB->id, $period->branch_id);
    }

    public function test_valid_status_lifecycle_records_actors_timestamps_and_audits(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => $this->branchA->id]);

        $period = $this->service->markInReview($period, $this->accountant);
        $this->assertSame(SettlementPeriod::STATUS_IN_REVIEW, $period->status);
        $this->assertSame($this->accountant->id, $period->submitted_by);
        $this->assertNotNull($period->submitted_at);

        $period = $this->service->approve($period, $this->accountant);
        $this->assertSame(SettlementPeriod::STATUS_APPROVED, $period->status);
        $this->assertSame($this->accountant->id, $period->approved_by);
        $this->assertNotNull($period->approved_at);

        $snapshot = $this->snapshotService->create($period, $this->accountant);
        $this->assertSame(SettlementSnapshot::TYPE_REVIEW, $snapshot->snapshot_type);

        $period = $this->service->lock($period, $this->accountant, 'Closed cleanly');
        $this->assertSame(SettlementPeriod::STATUS_LOCKED, $period->status);
        $this->assertSame($this->accountant->id, $period->locked_by);
        $this->assertNotNull($period->locked_at);
        $this->assertSame('Closed cleanly', $period->closing_notes);

        $period = $this->service->reopen($period, $this->accountant, 'Late sync review');
        $this->assertSame(SettlementPeriod::STATUS_REOPENED, $period->status);
        $this->assertSame($this->accountant->id, $period->reopened_by);
        $this->assertNotNull($period->reopened_at);
        $this->assertSame('Late sync review', $period->reopen_reason);

        $period = $this->service->returnToReview($period, $this->accountant);
        $this->assertSame(SettlementPeriod::STATUS_IN_REVIEW, $period->status);

        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'settlement_period_opened']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'settlement_period_in_review']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'settlement_period_approved']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'settlement_period_locked']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $this->tenant->id, 'action' => 'settlement_period_reopened']);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => $this->branchA->id]);

        $this->expectException(ValidationException::class);
        $this->service->approve($period, $this->accountant);
    }

    public function test_locked_to_reopened_requires_reason(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod(['branch_id' => $this->branchA->id]);
        $period = $this->service->markInReview($period, $this->accountant);
        $period = $this->service->approve($period, $this->accountant);
        $this->snapshotService->create($period, $this->accountant);
        $period = $this->service->lock($period, $this->accountant);

        $this->expectException(ValidationException::class);
        $this->service->reopen($period, $this->accountant, '');
    }

    public function test_tenant_a_cannot_access_tenant_b_settlement_period(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $roleB = Role::where('name', 'Accountant')->firstOrFail();
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'actor_type' => 'tenant_user', 'status' => 'active']);
        $userB->assignRole($roleB);
        $userB->assignToBranch($branchB);
        $foreignPeriod = $this->service->create([
            'branch_id' => $branchB->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $userB);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->expectException(ModelNotFoundException::class);
        $this->service->findVisible($foreignPeriod->id, $this->accountant);
    }

    public function test_branch_a_cannot_access_branch_b_period_without_permission(): void
    {
        $viewer = $this->createBranchScopedSettlementManager($this->tenant, $this->branchA);
        app(TenantContext::class)->setTenant($this->tenant);
        $periodA = $this->createPeriod(['branch_id' => $this->branchA->id]);
        $periodB = $this->createPeriod(['branch_id' => $this->branchB->id, 'period_start_at' => '2026-05-13 00:00:00', 'period_end_at' => '2026-05-13 23:59:59']);

        $this->assertSame($periodA->id, $this->service->findVisible($periodA->id, $viewer)->id);

        $this->expectException(ModelNotFoundException::class);
        $this->service->findVisible($periodB->id, $viewer);
    }

    public function test_settlement_lifecycle_preserves_source_records_and_creates_no_provider_side_effects(): void
    {
        Http::fake();
        app(TenantContext::class)->setTenant($this->tenant);

        $countsBefore = $this->businessCounts();
        $outboxBefore = $this->outboxCount();

        $period = $this->createPeriod(['branch_id' => $this->branchA->id]);
        $period = $this->service->markInReview($period, $this->accountant);
        $period = $this->service->approve($period, $this->accountant);
        $this->snapshotService->create($period, $this->accountant);
        $snapshotCountBeforeLock = SettlementSnapshot::count();
        $period = $this->service->lock($period, $this->accountant, 'No calculations yet');

        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, $this->outboxCount());
        $this->assertDatabaseCount('settlement_snapshots', $snapshotCountBeforeLock);
        $this->assertFalse(Schema::hasTable('settlement_variances'));
        $this->assertSame(SettlementPeriod::STATUS_LOCKED, $period->status);
        Http::assertNothingSent();
    }

    protected function createPeriod(array $overrides = []): SettlementPeriod
    {
        return $this->service->create(array_merge([
            'branch_id' => $this->branchA->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $overrides), $this->accountant);
    }

    protected function createBranchScopedSettlementManager(Tenant $tenant, Branch $branch): User
    {
        app(TenantContext::class)->setTenant($tenant);

        $role = Role::create([
            'name' => 'Branch Settlement Manager',
            'description' => 'Branch-scoped settlement management',
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