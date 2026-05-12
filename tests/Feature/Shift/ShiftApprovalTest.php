<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shift\ShiftService;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithShifts;

class ShiftApprovalTest extends TestCase
{
    use RefreshDatabase, InteractsWithShifts;

    protected ShiftService $shiftService;
    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected User $manager;
    protected Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($this->tenant);
        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        app(BranchContext::class)->setBranch($this->branch);
        
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignToBranch($this->branch);
        $this->cashier->assignRole(Role::where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->first());

        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->assignToBranch($this->branch);
        $this->manager->assignRole(Role::where('tenant_id', $this->tenant->id)->where('name', 'Branch Manager')->first());

        $this->shiftService = app(ShiftService::class);
        $this->shift = $this->openShiftFor($this->cashier, $this->branch);
        
        // Mock shift submission data
        $this->shift->update([
            'status' => Shift::STATUS_CLOSING_SUBMITTED,
            'counted_cash_amount' => '1000.0000',
            'expected_cash_amount' => '1000.0000',
            'variance_amount' => '0.0000',
            'closing_submitted_at' => now(),
        ]);
    }

    /** AC 1, 6-13: Successful approval by authorized manager */
    public function test_authorized_manager_can_approve_submitted_shift(): void
    {
        $updatedShift = $this->shiftService->approveShift(
            $this->shift,
            $this->manager,
            'Looks good.'
        );

        $this->assertEquals(Shift::STATUS_APPROVED, $updatedShift->status);
        $this->assertEquals($this->manager->id, $updatedShift->approved_by);
        $this->assertEquals('Looks good.', $updatedShift->manager_notes);
        $this->assertNotNull($updatedShift->approved_at);
        
        // Financials preserved
        $this->assertEquals('1000.0000', (string) $updatedShift->counted_cash_amount);
        $this->assertEquals('1000.0000', (string) $updatedShift->expected_cash_amount);
        $this->assertEquals('0.0000', (string) $updatedShift->variance_amount);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shift_approved',
            'auditable_id' => $this->shift->id,
        ]);
    }

    /** AC 2: Approval requires approve_shift permission */
    public function test_approval_requires_permission(): void
    {
        $unauthorized = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // No roles assigned

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing permission: approve_shift');

        $this->shiftService->approveShift($this->shift, $unauthorized);
    }

    /** AC 3, 4, 5: Rejects shifts in invalid status */
    public function test_it_rejects_approval_for_invalid_status(): void
    {
        $invalid = [Shift::STATUS_OPEN, Shift::STATUS_APPROVED, Shift::STATUS_CLOSED];

        foreach ($invalid as $status) {
            $this->shift->update(['status' => $status]);
            try {
                $this->shiftService->approveShift($this->shift, $this->manager);
                $this->fail("Should have rejected status: {$status}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Current status: ' . $status, $e->getMessage());
            }
        }
    }

    /** AC 14: Cashier cannot approve own shift */
    public function test_cashier_cannot_approve_own_shift(): void
    {
        // Give cashier the manager role temporarily to test the ownership check
        $this->cashier->assignRole(Role::where('tenant_id', $this->tenant->id)->where('name', 'Branch Manager')->first());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cashiers cannot approve their own shift');

        $this->shiftService->approveShift($this->shift, $this->cashier);
    }

    /** AC 15, 16: Rejects cross-tenant/branch approval */
    public function test_it_rejects_scope_mismatch(): void
    {
        // 1. Tenant mismatch
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        (new RbacSeeder())->seedForTenant($otherTenant);
        app(TenantContext::class)->setTenant($otherTenant);
        
        $otherManager = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherManager->assignRole(Role::where('tenant_id', $otherTenant->id)->where('name', 'Branch Manager')->first());

        try {
            $this->shiftService->approveShift($this->shift, $otherManager);
            $this->fail('Should have rejected tenant mismatch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cross-tenant', $e->getMessage());
        }

        // 2. Branch mismatch
        app(TenantContext::class)->setTenant($this->tenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $otherBranchManager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherBranchManager->assignToBranch($otherBranch);
        $otherBranchManager->assignRole(Role::where('tenant_id', $this->tenant->id)->where('name', 'Branch Manager')->first());

        app(BranchContext::class)->setBranch($otherBranch);

        try {
            $this->shiftService->approveShift($this->shift, $otherBranchManager);
            $this->fail('Should have rejected branch mismatch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('branch mismatch', $e->getMessage());
        }
    }

    /** AC 17, 18, 19: No side effects */
    public function test_it_has_no_financial_side_effects(): void
    {
        $initialOutboxCount = \DB::table('accounting_outbox')->count();
        $initialSaleCount = \DB::table('sales')->count();

        $this->shiftService->approveShift($this->shift, $this->manager);

        $this->assertEquals($initialOutboxCount, \DB::table('accounting_outbox')->count());
        $this->assertEquals($initialSaleCount, \DB::table('sales')->count());
    }
}
