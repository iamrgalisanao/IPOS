<?php

namespace Tests\Feature\Settlement;

use App\Models\AccountingOutbox;
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

class SettlementApprovalLockUiTest extends TestCase
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

    public function test_authorized_user_sees_approve_action_for_in_review_period(): void
    {
        [$period] = $this->createFixture($this->branchA, withSnapshot: false, status: 'in_review');

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('actions.can_approve', true)
                ->where('actions.can_lock', false)
            );
    }

    public function test_unauthorized_user_does_not_see_approve_action(): void
    {
        [$period] = $this->createFixture($this->branchA, withSnapshot: false, status: 'in_review');

        $this->actingAs($this->branchViewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('actions.can_approve', false)
                ->where('actions.can_lock', false)
            );
    }

    public function test_authorized_user_can_approve_period_from_ui_route(): void
    {
        Http::fake();
        [$period] = $this->createFixture($this->branchA, withSnapshot: false, status: 'in_review');
        $countsBefore = $this->counts();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.approve', $period->id))
            ->assertRedirect(route('settlement.periods.show', $period->id))
            ->assertSessionHas('status', 'Settlement period approved.');

        $this->assertSame($countsBefore, $this->counts());
        Http::assertNothingSent();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('period.status', SettlementPeriod::STATUS_APPROVED)
            );
    }

    public function test_authorized_user_sees_lock_action_for_approved_period_with_snapshot(): void
    {
        [$period] = $this->createFixture($this->branchA, withSnapshot: true, status: 'approved');

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('actions.can_lock', true)
                ->where('actions.lock_requires_snapshot', false)
                ->where('lockReadiness.can_lock', true)
            );
    }

    public function test_lock_action_hidden_or_disabled_when_no_snapshot_exists(): void
    {
        [$period] = $this->createFixture($this->branchA, withSnapshot: false, status: 'approved');

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('actions.can_lock', true)
                ->where('actions.lock_requires_snapshot', true)
                ->where('lockReadiness.can_lock', false)
            );
    }

    public function test_lock_action_returns_snapshot_required_validation_when_attempted_without_snapshot(): void
    {
        [$period] = $this->createFixture($this->branchA, withSnapshot: false, status: 'approved');

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.lock', $period->id))
            ->assertRedirect()
            ->assertSessionHasErrors('snapshot');
    }

    public function test_authorized_user_can_lock_approved_period_with_snapshot(): void
    {
        [$period] = $this->createFixture($this->branchA, withSnapshot: true, status: 'approved');

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.lock', $period->id))
            ->assertRedirect(route('settlement.periods.show', $period->id))
            ->assertSessionHas('status', 'Settlement period locked.');

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('settlement.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settlement/Periods/Show')
                ->where('period.status', SettlementPeriod::STATUS_LOCKED)
            );
    }

    public function test_tenant_a_cannot_approve_or_lock_tenant_b_period(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
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

        app(TenantContext::class)->setTenant($this->tenant);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.approve', $foreignPeriod->id))
            ->assertNotFound();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.lock', $foreignPeriod->id))
            ->assertNotFound();
    }

    public function test_branch_a_cannot_approve_or_lock_branch_b_period_unless_permission_allows(): void
    {
        [$period] = $this->createFixture($this->branchB, withSnapshot: false, status: 'in_review');
        $manager = $this->branchScopedManager;

        $this->actingAs($manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.approve', $period->id))
            ->assertNotFound();

        $this->actingAs($manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.lock', $period->id))
            ->assertNotFound();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.approve', $period->id))
            ->assertRedirect(route('settlement.periods.show', $period->id));

        $period->refresh();
        $this->snapshotService->create($period, $this->accountant);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.lock', $period->id))
            ->assertRedirect(route('settlement.periods.show', $period->id));
    }

    public function test_ui_action_does_not_create_snapshots_or_mutate_source_records_or_call_providers(): void
    {
        Http::fake();
        [$period] = $this->createFixture($this->branchA, withSnapshot: true, status: 'approved');

        $countsBefore = $this->counts();
        $snapshotCountBefore = SettlementSnapshot::count();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('settlement.periods.lock', $period->id))
            ->assertRedirect(route('settlement.periods.show', $period->id));

        $this->assertSame($countsBefore, $this->counts());
        $this->assertSame($snapshotCountBefore, SettlementSnapshot::count());
        $this->assertSame(0, AccountingOutbox::count());
        Http::assertNothingSent();
    }

    protected function createFixture(Branch $branch, bool $withSnapshot, string $status = 'approved'): array
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
            'remarks' => 'Approval lock seed',
        ]);

        if ($status === 'in_review') {
            $period = $this->periodService->markInReview($period, $this->accountant);
        } elseif ($status === 'approved') {
            $period = $this->periodService->markInReview($period, $this->accountant);
            $period = $this->periodService->approve($period, $this->accountant);
        }

        if ($withSnapshot) {
            $this->snapshotService->create($period, $this->accountant);
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
