<?php

namespace Tests\Feature\Settlement;

use App\Models\AccountingOutbox;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettlementReopenUiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $accountant;
    protected User $branchViewer;
    protected User $branchScopedManager;
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

        $viewerRole = Role::create([
            'name' => 'Settlement Reviewer',
            'description' => 'Read-only settlement review access',
        ]);
        $viewerRole->permissions()->attach(Permission::where('name', 'view_settlement_periods')->firstOrFail()->id);

        $this->branchViewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->branchViewer->assignRole($viewerRole);
        $this->branchViewer->assignToBranch($this->branchA);

        $managerRole = Role::create([
            'name' => 'Branch Settlement Manager',
            'description' => 'Branch-scoped settlement approval access',
        ]);
        $managerRole->permissions()->attach(Permission::where('name', 'manage_settlement_periods')->firstOrFail()->id);

        $this->branchScopedManager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->branchScopedManager->assignRole($managerRole);
        $this->branchScopedManager->assignToBranch($this->branchA);

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

    public function test_authorized_user_sees_reopen_action_for_locked_period(): void
    {
        [$period] = $this->createLockedFixture($this->branchA);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('actions.can_reopen', true)
                ->where('period.status', SettlementPeriod::STATUS_LOCKED)
            );
    }

    public function test_unauthorized_user_does_not_see_reopen_action(): void
    {
        [$period] = $this->createLockedFixture($this->branchA);

        $this->actingAs($this->branchViewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('actions.can_reopen', false)
            );
    }

    public function test_reopen_action_hidden_for_non_locked_periods(): void
    {
        [$period] = $this->createUnlockedFixture($this->branchA, 'approved');

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('actions.can_reopen', false)
            );
    }

    public function test_reopen_requires_reason(): void
    {
        [$period] = $this->createLockedFixture($this->branchA);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.reopen', $period->id), ['reason' => ''])
            ->assertRedirect()
            ->assertSessionHasErrors('reason');
    }

    public function test_authorized_user_can_reopen_locked_period_from_ui_route(): void
    {
        Http::fake();
        [$period] = $this->createLockedFixture($this->branchA);
        $countsBefore = $this->counts();
        $snapshotCountBefore = SettlementSnapshot::count();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.reopen', $period->id), ['reason' => 'Late sync review'])
            ->assertRedirect(route('settlement.periods.show', $period->id))
            ->assertSessionHas('status', 'Settlement period reopened.');

        $this->assertSame($countsBefore, $this->counts());
        $this->assertSame($snapshotCountBefore, SettlementSnapshot::count());
        Http::assertNothingSent();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('period.status', SettlementPeriod::STATUS_REOPENED)
                ->where('period.reopen_reason', 'Late sync review')
            );
    }

    public function test_reopen_records_actor_timestamp_reason_and_audit(): void
    {
        [$period] = $this->createLockedFixture($this->branchA);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.reopen', $period->id), ['reason' => 'Late sync review'])
            ->assertRedirect();

        $period->refresh();

        $this->assertSame(SettlementPeriod::STATUS_REOPENED, $period->status);
        $this->assertSame($this->accountant->id, $period->reopened_by);
        $this->assertNotNull($period->reopened_at);
        $this->assertSame('Late sync review', $period->reopen_reason);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'settlement_period_reopened',
            'auditable_id' => $period->id,
        ]);
    }

    public function test_tenant_a_cannot_reopen_tenant_b_period(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $tenantBUser = User::factory()->create([
            'tenant_id' => $tenantB->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $tenantBUser->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $tenantBUser->assignToBranch($branchB);
        $foreignPeriod = $this->periodService->create([
            'branch_id' => $branchB->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $tenantBUser);
        $foreignPeriod = $this->periodService->markInReview($foreignPeriod, $tenantBUser);
        $foreignPeriod = $this->periodService->approve($foreignPeriod, $tenantBUser);
        $this->snapshotService->create($foreignPeriod, $tenantBUser);
        $foreignPeriod = $this->periodService->lock($foreignPeriod, $tenantBUser);

        app(TenantContext::class)->setTenant($this->tenant);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.reopen', $foreignPeriod->id), ['reason' => 'Late sync review'])
            ->assertNotFound();
    }

    public function test_branch_a_cannot_reopen_branch_b_period_unless_permission_allows(): void
    {
        [$period] = $this->createLockedFixture($this->branchB);

        $this->actingAs($this->branchScopedManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.reopen', $period->id), ['reason' => 'Late sync review'])
            ->assertNotFound();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.reopen', $period->id), ['reason' => 'Late sync review'])
            ->assertRedirect(route('settlement.periods.show', $period->id));
    }

    public function test_reopen_does_not_create_snapshots_or_mutate_source_records_or_call_providers(): void
    {
        Http::fake();
        [$period] = $this->createLockedFixture($this->branchA);
        $countsBefore = $this->counts();
        $snapshotCountBefore = SettlementSnapshot::count();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.reopen', $period->id), ['reason' => 'Late sync review'])
            ->assertRedirect(route('settlement.periods.show', $period->id));

        $this->assertSame($countsBefore, $this->counts());
        $this->assertSame($snapshotCountBefore, SettlementSnapshot::count());
        $this->assertSame(0, AccountingOutbox::count());
        Http::assertNothingSent();
    }

    protected function createLockedFixture(Branch $branch): array
    {
        return $this->createPeriodWithLifecycle($branch, 'locked', true);
    }

    protected function createUnlockedFixture(Branch $branch, string $status): array
    {
        return $this->createPeriodWithLifecycle($branch, $status, $status === 'approved');
    }

    protected function createPeriodWithLifecycle(Branch $branch, string $status, bool $withSnapshot): array
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $period = $this->periodService->create([
            'branch_id' => $branch->id,
            'period_start_at' => '2026-05-12 00:00:00',
            'period_end_at' => '2026-05-12 23:59:59',
        ], $this->accountant);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
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
            'branch_id' => $branch->id,
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
            'branch_id' => $branch->id,
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
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'reason_code' => 'mistake',
            'reason_notes' => 'Test void',
            'voided_by' => $this->accountant->id,
            'voided_at' => '2026-05-12 11:00:00',
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'product_id' => Product::factory()->create([
                'tenant_id' => $this->tenant->id,
                'status' => 'active',
                'is_inventory_tracked' => true,
            ])->id,
            'branch_inventory_id' => BranchInventory::create([
                'tenant_id' => $this->tenant->id,
                'branch_id' => $branch->id,
                'product_id' => Product::factory()->create([
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
            'remarks' => 'Reopen seed',
        ]);

        $period = $this->periodService->markInReview($period, $this->accountant);
        $period = $this->periodService->approve($period, $this->accountant);

        if ($withSnapshot) {
            $this->snapshotService->create($period, $this->accountant);
        }

        if ($status === 'locked') {
            $period = $this->periodService->lock($period, $this->accountant);
        }

        $period->refresh()->load(['latestSnapshot', 'snapshots.creator']);
        app(TenantContext::class)->clear();

        return [$period];
    }

    protected function counts(): array
    {
        app(TenantContext::class)->setTenant($this->tenant);

        return [
            'sales' => Sale::count(),
            'sale_payments' => SalePayment::count(),
            'inventory_movements' => InventoryMovement::count(),
            'sale_refunds' => SaleRefund::count(),
            'sale_voids' => SaleVoid::count(),
            'accounting_outbox' => AccountingOutbox::count(),
        ];
    }
}
