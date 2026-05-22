<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\User;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\CashDrawerEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithShifts;
use Tests\Traits\InteractsWithTenants;
use Inertia\Testing\AssertableInertia as Assert;

class CashierAccountabilityUiTest extends TestCase
{
    use RefreshDatabase, InteractsWithTenants, InteractsWithShifts;

    protected User $admin;
    protected User $manager;
    protected User $cashierA;
    protected User $cashierB;
    protected Branch $branchA;
    protected Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setupTenantContext();

        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch B']);

        // Set up users
        $this->admin = $this->createTenantUser('admin');
        $this->givePermissionTo($this->admin, 'view_multi_branch_dashboard');
        $this->givePermissionTo($this->admin, 'reports.shift-summary.view');

        $this->manager = $this->createTenantUser('manager');
        $this->manager->branches()->attach($this->branchA);
        $this->givePermissionTo($this->manager, 'reports.shift-summary.view');

        $this->cashierA = $this->createTenantUser('cashier_a');
        $this->cashierA->branches()->attach($this->branchA);
        $this->givePermissionTo($this->cashierA, 'reports.cashier-accountability.view');

        $this->cashierB = $this->createTenantUser('cashier_b');
        $this->cashierB->branches()->attach($this->branchA);
    }

    /** @test */
    public function test_unauthenticated_users_are_redirected_to_login()
    {
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.index'))
            ->assertRedirect(route('login'));
    }

    /** @test */
    public function test_unauthorized_user_cannot_access_index_page()
    {
        // cashierB has no permissions
        $this->actingAs($this->cashierB)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.index'))
            ->assertForbidden();
    }

    /** @test */
    public function test_authorized_cashier_can_access_index_view_with_own_shifts()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/CashierAccountability/Index')
                ->has('shifts.data', 1)
                ->where('shifts.data.0.id', $shift->id)
            );
    }

    /** @test */
    public function test_manager_can_access_index_and_view_branch_shifts()
    {
        // Shift in Manager's Branch A
        $shiftA = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        // Shift in another Branch B (which manager has no assignment to)
        $shiftB = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchB->id,
            'cashier_id' => $this->cashierB->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/CashierAccountability/Index')
                ->has('shifts.data', 1)
                ->where('shifts.data.0.id', $shiftA->id)
            );
    }

    /** @test */
    public function test_cashier_can_view_own_shift_report_payload_calculations()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_CLOSED,
            'opening_cash_amount' => '100.0000',
            'expected_cash_amount' => '250.0000',
            'counted_cash_amount' => '245.0000',
            'variance_amount' => '-5.0000',
            'closed_at' => now(),
        ]);

        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $shift->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/CashierAccountability/Show')
                ->has('report.shift')
                ->where('report.shift.id', $shift->id)
                ->where('report.cash_variance.expected_cash', '250.0000')
                ->where('report.cash_variance.declared_cash', '245.0000')
                ->where('report.cash_variance.variance', '-5.0000')
            );
    }

    /** @test */
    public function test_cashier_cannot_view_another_cashiers_shift_report()
    {
        $otherShift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierB->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $otherShift->id))
            ->assertForbidden();
    }

    /** @test */
    public function test_cashier_report_access_causes_no_mutating_side_effects()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '100.0000',
        ]);

        $shiftsBefore = Shift::count();
        $salesBefore = Sale::count();
        $drawerBefore = CashDrawerEvent::count();

        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $shift->id))
            ->assertOk();

        $this->assertEquals($shiftsBefore, Shift::count());
        $this->assertEquals($salesBefore, Sale::count());
        $this->assertEquals($drawerBefore, CashDrawerEvent::count());
    }
}
