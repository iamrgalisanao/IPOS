<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\Role;
use App\Models\SettlementPeriod;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shift\ShiftService;
use App\Services\Settlement\SettlementPeriodService;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithShifts;

class ShiftSettlementLockTest extends TestCase
{
    use RefreshDatabase, InteractsWithShifts;

    protected ShiftService $shiftService;
    protected SettlementPeriodService $settlementService;
    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected User $manager;

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
        // Give manager settlement permissions
        $this->manager->assignRole(Role::where('tenant_id', $this->tenant->id)->where('name', 'Owner/Admin')->first());

        $this->shiftService = app(ShiftService::class);
        $this->settlementService = app(SettlementPeriodService::class);
    }

    protected function createPeriod(string $start, string $end, string $status = SettlementPeriod::STATUS_OPEN): SettlementPeriod
    {
        return SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'period_start_at' => $start,
            'period_end_at' => $end,
            'status' => $status,
            'opened_at' => now(),
            'opened_by' => $this->manager->id,
        ]);
    }

    /** AC 1, 9: Prevent opening shift in locked period */
    public function test_it_prevents_opening_shift_in_locked_period(): void
    {
        $this->createPeriod('2026-05-01 00:00:00', '2026-05-02 00:00:00', SettlementPeriod::STATUS_LOCKED);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation blocked: Settlement period for 2026-05-01 is locked.');

        $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '100.0000',
            $this->manager,
            now()->parse('2026-05-01 08:00:00')
        );
    }

    /** AC 2: Prevent recording drawer event in locked period */
    public function test_it_prevents_drawer_event_in_locked_period(): void
    {
        $shift = $this->openShiftFor($this->cashier, $this->branch, '100.0000', now()->parse('2026-05-01 08:00:00'));
        $this->createPeriod('2026-05-01 00:00:00', '2026-05-02 00:00:00', SettlementPeriod::STATUS_LOCKED);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation blocked: Settlement period for 2026-05-01 is locked.');

        $this->shiftService->recordDrawerEvent(
            $shift,
            $this->cashier,
            'cash_in',
            '50.0000',
            'TEST_REASON',
            'Testing lock',
            now()->parse('2026-05-01 10:00:00')
        );
    }

    /** AC 3: Prevent submitting closing count in locked period */
    public function test_it_prevents_closing_submission_in_locked_period(): void
    {
        $shift = $this->openShiftFor($this->cashier, $this->branch, '100.0000', now()->parse('2026-05-01 08:00:00'));
        $this->createPeriod('2026-05-01 00:00:00', '2026-05-02 00:00:00', SettlementPeriod::STATUS_LOCKED);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation blocked: Settlement period for 2026-05-01 is locked.');

        $this->shiftService->submitClosingCount(
            $shift,
            $this->cashier,
            '150.0000',
            null,
            now()->parse('2026-05-01 17:00:00')
        );
    }

    /** AC 4: Prevent approving shift in locked period */
    public function test_it_prevents_approval_in_locked_period(): void
    {
        $shift = $this->openShiftFor($this->cashier, $this->branch, '100.0000', now()->parse('2026-05-01 08:00:00'));
        $shift->update(['status' => Shift::STATUS_CLOSING_SUBMITTED]);
        
        $this->createPeriod('2026-05-01 00:00:00', '2026-05-02 00:00:00', SettlementPeriod::STATUS_LOCKED);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation blocked: Settlement period for 2026-05-01 is locked.');

        $this->shiftService->approveShift(
            $shift,
            $this->manager,
            'Testing lock',
            now()->parse('2026-05-01 18:00:00')
        );
    }

    /** AC 5, 6, 7: Operations succeed if period is NOT locked */
    public function test_it_allows_operations_in_non_locked_periods(): void
    {
        $statuses = [SettlementPeriod::STATUS_OPEN, SettlementPeriod::STATUS_IN_REVIEW, SettlementPeriod::STATUS_APPROVED];
        
        foreach ($statuses as $status) {
            $period = $this->createPeriod('2026-06-01 00:00:00', '2026-06-02 00:00:00', $status);
            
            $shift = $this->shiftService->openShift(
                $this->cashier,
                $this->branch,
                '100.0000',
                $this->manager,
                now()->parse('2026-06-01 08:00:00')
            );
            $this->assertInstanceOf(Shift::class, $shift);
            
            // Cleanup for next iteration
            $shift->delete();
            $period->delete();
        }
    }

    /** AC 8, 10: Branch isolation for shift operations */
    public function test_it_enforces_branch_isolation_for_shift_guards(): void
    {
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        // Lock other branch
        SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'period_start_at' => '2026-05-01 00:00:00',
            'period_end_at' => '2026-05-02 00:00:00',
            'status' => SettlementPeriod::STATUS_LOCKED,
            'opened_at' => now(),
            'opened_by' => $this->manager->id,
        ]);

        // Operation in current branch should succeed
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '100.0000',
            $this->manager,
            now()->parse('2026-05-01 08:00:00')
        );

        $this->assertInstanceOf(Shift::class, $shift);
    }

    protected function createSnapshot(SettlementPeriod $period): \App\Models\SettlementSnapshot
    {
        return \App\Models\SettlementSnapshot::create([
            'tenant_id' => $period->tenant_id,
            'branch_id' => $period->branch_id,
            'settlement_period_id' => $period->id,
            'snapshot_type' => 'review',
            'summary_payload' => ['total_cash' => '1000.0000'],
            'variance_payload' => ['variance' => '0.0000'],
            'created_by' => $this->manager->id,
            'data' => [],
        ]);
    }

    /** AC 11, 12, 13: Settlement lock blocked by shifts */
    public function test_it_blocks_settlement_lock_if_shifts_are_not_approved(): void
    {
        $period = $this->createPeriod('2026-07-01 00:00:00', '2026-07-02 00:00:00', SettlementPeriod::STATUS_APPROVED);
        $this->createSnapshot($period);
        
        // 1. Blocked by OPEN shift
        $shift = $this->openShiftFor($this->cashier, $this->branch, '100.0000', now()->parse('2026-07-01 08:00:00'));
        
        try {
            $this->settlementService->lock($period, $this->manager);
            $this->fail('Should have blocked lock due to open shift');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('open/pending shifts exist', $e->getMessage());
        }

        // 2. Blocked by CLOSING_SUBMITTED shift
        $shift->update(['status' => Shift::STATUS_CLOSING_SUBMITTED, 'closing_submitted_at' => now()->parse('2026-07-01 17:00:00')]);
        
        try {
            $this->settlementService->lock($period, $this->manager);
            $this->fail('Should have blocked lock due to submitted shift');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('open/pending shifts exist', $e->getMessage());
        }

        // 3. Allowed if APPROVED
        $shift->update(['status' => Shift::STATUS_APPROVED]);
        $lockedPeriod = $this->settlementService->lock($period, $this->manager);
        $this->assertEquals(SettlementPeriod::STATUS_LOCKED, $lockedPeriod->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settlement_period_locked',
            'auditable_type' => SettlementPeriod::class,
            'auditable_id' => $period->id,
        ]);
    }

    /** AC 14, 15: Tenant-wide and branch-scoped lock checks */
    public function test_it_handles_tenant_and_branch_scoped_settlement_checks(): void
    {
        // 1. Branch-scoped check: only checks same branch
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignToBranch($otherBranch);
        
        // Switch context to other branch
        app(BranchContext::class)->setBranch($otherBranch);
        $otherShift = $this->openShiftFor($this->cashier, $otherBranch, '100.0000', now()->parse('2026-08-01 08:00:00'));
        
        // Switch back to test branch
        app(BranchContext::class)->setBranch($this->branch);
        $period = $this->createPeriod('2026-08-01 00:00:00', '2026-08-02 00:00:00', SettlementPeriod::STATUS_APPROVED);
        $this->createSnapshot($period);

        // Current branch has no shifts in this period yet
        $locked = $this->settlementService->lock($period, $this->manager);
        $this->assertEquals(SettlementPeriod::STATUS_LOCKED, $locked->status);

        // 2. Tenant-wide check: checks all branches
        $tenantPeriod = SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => null, // Tenant-wide
            'period_start_at' => '2026-08-01 00:00:00',
            'period_end_at' => '2026-08-02 00:00:00',
            'status' => SettlementPeriod::STATUS_APPROVED,
            'opened_at' => now(),
            'opened_by' => $this->manager->id,
        ]);
        $this->createSnapshot($tenantPeriod);

        try {
            $this->settlementService->lock($tenantPeriod, $this->manager);
            $this->fail('Should have blocked tenant-wide lock due to shift in branch B');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('open/pending shifts exist', $e->getMessage());
        }
    }

    /** AC 16, 17: Tenant and Branch isolation */
    public function test_it_enforces_tenant_isolation_for_locks(): void
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        (new RbacSeeder())->seedForTenant($otherTenant);
        app(TenantContext::class)->setTenant($otherTenant);
        
        $otherManager = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherManager->assignRole(Role::where('tenant_id', $otherTenant->id)->where('name', 'Owner/Admin')->first());

        $otherPeriod = SettlementPeriod::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => null,
            'period_start_at' => '2026-09-01 00:00:00',
            'period_end_at' => '2026-09-02 00:00:00',
            'status' => SettlementPeriod::STATUS_APPROVED,
            'opened_at' => now(),
            'opened_by' => $otherManager->id,
        ]);
        $this->createSnapshot($otherPeriod);

        // Shift in tenant A should NOT block lock in tenant B
        app(TenantContext::class)->setTenant($this->tenant);
        $this->openShiftFor($this->cashier, $this->branch, '100.0000', now()->parse('2026-09-01 08:00:00'));

        app(TenantContext::class)->setTenant($otherTenant);
        $locked = $this->settlementService->lock($otherPeriod, $otherManager);
        $this->assertEquals(SettlementPeriod::STATUS_LOCKED, $locked->status);
    }

    /** AC 18, 19, 20: No side effects on failure */
    public function test_it_has_no_side_effects_on_guard_failure(): void
    {
        $this->createPeriod('2026-10-01 00:00:00', '2026-10-02 00:00:00', SettlementPeriod::STATUS_LOCKED);
        
        $initialAuditCount = \DB::table('audit_logs')->count();
        $initialShiftCount = \DB::table('shifts')->count();

        try {
            $this->shiftService->openShift(
                $this->cashier,
                $this->branch,
                '100.0000',
                $this->manager,
                now()->parse('2026-10-01 08:00:00')
            );
        } catch (\RuntimeException $e) {}

        $this->assertEquals($initialAuditCount, \DB::table('audit_logs')->count());
        $this->assertEquals($initialShiftCount, \DB::table('shifts')->count());
    }
}
