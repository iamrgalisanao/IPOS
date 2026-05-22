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

class CashierAccountabilityExportTest extends TestCase
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

        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => '=Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch B']);

        // Set up users
        $this->admin = $this->createTenantUser('admin');
        $this->givePermissionTo($this->admin, 'view_multi_branch_dashboard');
        $this->givePermissionTo($this->admin, 'reports.shift-summary.export');

        $this->manager = $this->createTenantUser('manager');
        $this->manager->branches()->attach($this->branchA);
        $this->givePermissionTo($this->manager, 'reports.cashier-accountability.export');
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
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertRedirect(route('login'));
    }

    /** @test */
    public function test_cashier_without_export_permission_is_forbidden()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        // Cashier A has reports.cashier-accountability.view but not export
        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertForbidden();
    }

    /** @test */
    public function test_branch_manager_can_export_assigned_branch_shift()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => '1000.0000',
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        
        $content = $response->streamedContent();
        
        // Assert headers present
        $this->assertStringContainsString('shift_id', $content);
        $this->assertStringContainsString('opening_cash', $content);
        $this->assertStringContainsString('1000.0000', $content);
        
        // Assert injection protection was applied to branch name starting with =
        $this->assertStringContainsString('\'=Branch A', $content);
    }

    /** @test */
    public function test_branch_manager_cannot_export_different_branch_shift()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchB->id, // manager has no branch B assignment
            'cashier_id' => $this->cashierB->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertForbidden();
    }

    /** @test */
    public function test_cross_tenant_export_is_blocked()
    {
        $otherTenant = $this->createTenantUser('other_admin')->tenant; // creates a new tenant
        
        $shift = Shift::factory()->create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        $this->actingAs($this->admin)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertForbidden();
    }

    /** @test */
    public function test_export_action_does_not_mutate_any_records()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        $shiftsBefore = Shift::count();
        $salesBefore = Sale::count();
        $drawerBefore = CashDrawerEvent::count();

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertOk();

        $this->assertEquals($shiftsBefore, Shift::count());
        $this->assertEquals($salesBefore, Sale::count());
        $this->assertEquals($drawerBefore, CashDrawerEvent::count());
    }
}
