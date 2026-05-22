<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\User;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\CashDrawerEvent;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithShifts;
use Tests\Traits\InteractsWithTenants;
use Inertia\Testing\AssertableInertia as Assert;

class CashierAccountabilityHardeningTest extends TestCase
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
        $this->givePermissionTo($this->admin, 'reports.shift-summary.export');
        $this->givePermissionTo($this->admin, 'reports.shift-summary.view');

        $this->manager = $this->createTenantUser('manager');
        $this->manager->branches()->attach($this->branchA);
        $this->givePermissionTo($this->manager, 'reports.cashier-accountability.view');
        $this->givePermissionTo($this->manager, 'reports.shift-summary.view');
        $this->givePermissionTo($this->manager, 'reports.cashier-accountability.export');

        $this->cashierA = $this->createTenantUser('cashier_a');
        $this->cashierA->branches()->attach($this->branchA);
        $this->givePermissionTo($this->cashierA, 'reports.cashier-accountability.view');

        $this->cashierB = $this->createTenantUser('cashier_b');
        $this->cashierB->branches()->attach($this->branchA);
    }

    /** @test */
    public function test_view_permission_does_not_allow_export()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        // Cashier A has view permission but no export permission
        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertForbidden();
    }

    /** @test */
    public function test_cashier_cannot_access_another_cashiers_shift()
    {
        $shiftB = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierB->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $shiftB->id))
            ->assertForbidden();
    }

    /** @test */
    public function test_branch_manager_cannot_access_another_branch_shift()
    {
        $shiftInB = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchB->id,
            'cashier_id' => $this->cashierB->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $shiftInB->id))
            ->assertForbidden();
    }

    /** @test */
    public function test_cross_tenant_report_access_blocked()
    {
        $otherTenant = \App\Models\Tenant::factory()->create(['status' => 'active']);
        
        // Temporarily switch tenant context to otherTenant to satisfy the security observer
        app(\App\Services\TenantContext::class)->setTenant($otherTenant);
        $otherBranch = \App\Models\Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherCashier = \App\Models\User::factory()->create(['tenant_id' => $otherTenant->id]);
        
        $shift = Shift::factory()->create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'cashier_id' => $otherCashier->id,
            'status' => Shift::STATUS_OPEN,
        ]);
        
        // Restore context to main tenant
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $shift->id));
            
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    /** @test */
    public function test_cross_tenant_export_access_blocked()
    {
        $otherTenant = \App\Models\Tenant::factory()->create(['status' => 'active']);
        
        // Temporarily switch tenant context to otherTenant to satisfy the security observer
        app(\App\Services\TenantContext::class)->setTenant($otherTenant);
        $otherBranch = \App\Models\Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherCashier = \App\Models\User::factory()->create(['tenant_id' => $otherTenant->id]);
        
        $shift = Shift::factory()->create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'cashier_id' => $otherCashier->id,
            'status' => Shift::STATUS_OPEN,
        ]);
        
        // Restore context to main tenant
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        $response = $this->actingAs($this->admin)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id));
            
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    /** @test */
    public function test_report_view_creates_audit_event()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        // Clear existing log entries for sanity
        AuditLog::where('tenant_id', $this->tenant->id)->delete();

        // Perform viewing report as cashierA (owns this shift)
        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $shift->id))
            ->assertOk();

        // Verify audit log entry
        $log = AuditLog::where('tenant_id', $this->tenant->id)
            ->where('action', 'cashier_accountability_report_viewed')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($shift->id, $log->auditable_id);
        $this->assertEquals($this->cashierA->id, $log->actor_user_id);
        
        // Assert metadata fields match
        $metadata = $log->metadata;
        $this->assertEquals($shift->id, $metadata['shift_id']);
        $this->assertEquals($this->cashierA->id, $metadata['viewer_id']);
    }

    /** @test */
    public function test_report_export_creates_audit_event()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_OPEN,
        ]);

        // Clear existing log entries for sanity
        AuditLog::where('tenant_id', $this->tenant->id)->delete();

        // Perform exporting report as manager (has export permissions)
        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertOk();

        // Verify audit log entry
        $log = AuditLog::where('tenant_id', $this->tenant->id)
            ->where('action', 'cashier_accountability_report_exported')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($shift->id, $log->auditable_id);
        $this->assertEquals($this->manager->id, $log->actor_user_id);
        
        // Assert metadata fields match
        $metadata = $log->metadata;
        $this->assertEquals($shift->id, $metadata['shift_id']);
        $this->assertEquals($this->manager->id, $metadata['viewer_id']);
        $this->assertEquals('CSV', $metadata['export_format']);
    }

    /** @test */
    public function test_report_view_does_not_mutate_shift_or_related_records()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_CLOSED,
            'opening_cash_amount' => '500.0000',
        ]);

        $originalUpdatedAt = $shift->fresh()->updated_at;
        $shiftsBefore = Shift::count();
        $salesBefore = Sale::count();
        $drawerBefore = CashDrawerEvent::count();

        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $shift->id))
            ->assertOk();

        $this->assertEquals($originalUpdatedAt->toDateTimeString(), $shift->fresh()->updated_at->toDateTimeString());
        $this->assertEquals($shiftsBefore, Shift::count());
        $this->assertEquals($salesBefore, Sale::count());
        $this->assertEquals($drawerBefore, CashDrawerEvent::count());
    }

    /** @test */
    public function test_report_export_does_not_mutate_shift_or_related_records()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_CLOSED,
            'opening_cash_amount' => '500.0000',
        ]);

        $originalUpdatedAt = $shift->fresh()->updated_at;
        $shiftsBefore = Shift::count();
        $salesBefore = Sale::count();
        $drawerBefore = CashDrawerEvent::count();

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertOk();

        $this->assertEquals($originalUpdatedAt->toDateTimeString(), $shift->fresh()->updated_at->toDateTimeString());
        $this->assertEquals($shiftsBefore, Shift::count());
        $this->assertEquals($salesBefore, Sale::count());
        $this->assertEquals($drawerBefore, CashDrawerEvent::count());
    }

    /** @test */
    public function test_closed_shift_remains_immutable_after_view_and_export()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_CLOSED,
        ]);

        // View
        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $shift->id))
            ->assertOk();

        $this->assertEquals(Shift::STATUS_CLOSED, $shift->fresh()->status);

        // Export
        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertOk();

        $this->assertEquals(Shift::STATUS_CLOSED, $shift->fresh()->status);
    }

    /** @test */
    public function test_approved_shift_remains_immutable_after_view_and_export()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'cashier_id' => $this->cashierA->id,
            'status' => Shift::STATUS_APPROVED,
        ]);

        // View
        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.show', $shift->id))
            ->assertOk();

        $this->assertEquals(Shift::STATUS_APPROVED, $shift->fresh()->status);

        // Export
        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('reports.cashier-accountability.export', $shift->id))
            ->assertOk();

        $this->assertEquals(Shift::STATUS_APPROVED, $shift->fresh()->status);
    }
}
