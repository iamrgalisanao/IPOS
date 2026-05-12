<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\CashDrawerEvent;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
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

class ShiftClosingTest extends TestCase
{
    use RefreshDatabase, InteractsWithShifts;

    protected ShiftService $shiftService;
    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected Shift $shift;
    protected PaymentMethod $cashMethod;

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
        
        $role = Role::where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->first();
        $this->cashier->assignRole($role);

        $this->cashMethod = PaymentMethod::where('tenant_id', $this->tenant->id)
            ->where('code', 'CASH')
            ->first() ?? PaymentMethod::factory()->create([
                'tenant_id' => $this->tenant->id,
                'code' => 'CASH',
                'name' => 'Cash',
                'status' => 'active'
            ]);

        $this->shiftService = app(ShiftService::class);
        $this->shift = $this->openShiftFor($this->cashier, $this->branch, '1000.00');
    }

    /** AC 1-18: Successful blind close with various movements and calculations */
    public function test_it_calculates_expected_cash_and_variance_correctly(): void
    {
        // 1. Cash Payment (Add 500.50)
        SalePayment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => '500.50',
            'status' => 'paid'
        ]);

        // 2. Non-Cash Payment (Should be ignored)
        $cardMethod = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'CARD']);
        SalePayment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $cardMethod->id,
            'amount' => '200.00',
            'status' => 'paid'
        ]);

        // 3. Cash In (Add 200)
        $this->shiftService->recordDrawerEvent($this->shift, $this->cashier, CashDrawerEvent::TYPE_CASH_IN, '200.00', 'IN');

        // 4. Cash Top-Up (Add 100)
        $this->shiftService->recordDrawerEvent($this->shift, $this->cashier, CashDrawerEvent::TYPE_CASH_TOP_UP, '100.00', 'TOPUP');

        // 5. Cash Drop (Deduct 300)
        $this->shiftService->recordDrawerEvent($this->shift, $this->cashier, CashDrawerEvent::TYPE_CASH_DROP, '300.00', 'DROP');

        // 6. Cash Out (Deduct 50)
        $this->shiftService->recordDrawerEvent($this->shift, $this->cashier, CashDrawerEvent::TYPE_CASH_OUT, '50.00', 'OUT');

        // Expected Formula:
        // 1000 (opening) 
        // + 500.50 (payment) 
        // + 200 (in) 
        // + 100 (topup) 
        // - 300 (drop) 
        // - 50 (out) 
        // = 1450.50
        $expected = '1450.5000';

        // Submit 1500.00 (Overage of 49.50)
        $updatedShift = $this->shiftService->submitClosingCount(
            $this->shift,
            $this->cashier,
            '1500.00',
            'Submission notes'
        );

        $this->assertEquals(Shift::STATUS_CLOSING_SUBMITTED, $updatedShift->status);
        $this->assertEquals('1500.0000', (string) $updatedShift->counted_cash_amount);
        $this->assertEquals($expected, (string) $updatedShift->expected_cash_amount);
        $this->assertEquals('49.5000', (string) $updatedShift->variance_amount);
        $this->assertNotNull($updatedShift->closing_submitted_at);
        $this->assertEquals('Submission notes', $updatedShift->closing_notes);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shift_closing_submitted',
            'auditable_id' => $this->shift->id,
        ]);
    }

    /** AC 10: Negative variance (shortage) */
    public function test_it_handles_shortage_variance(): void
    {
        // Expected: 1000 (opening)
        // Submit 900 (Shortage of 100)
        $updatedShift = $this->shiftService->submitClosingCount($this->shift, $this->cashier, '900.00');

        $this->assertEquals('-100.0000', (string) $updatedShift->variance_amount);
    }

    /** AC 11: Zero variance */
    public function test_it_handles_perfect_variance(): void
    {
        $updatedShift = $this->shiftService->submitClosingCount($this->shift, $this->cashier, '1000.00');

        $this->assertEquals('0.0000', (string) $updatedShift->variance_amount);
    }

    /** AC 19, 20: Reject invalid counts */
    public function test_it_rejects_invalid_counted_cash(): void
    {
        $invalid = ['-1.00', 'abc'];

        foreach ($invalid as $amount) {
            try {
                $this->shiftService->submitClosingCount($this->shift, $this->cashier, $amount);
                $this->fail("Should have rejected: {$amount}");
            } catch (\InvalidArgumentException $e) {
                // Expected
            }
        }
    }

    /** AC 21, 22: Rejects closing non-open shifts */
    public function test_it_rejects_closing_non_open_shifts(): void
    {
        $statuses = [Shift::STATUS_CLOSING_SUBMITTED, Shift::STATUS_APPROVED, Shift::STATUS_CLOSED];

        foreach ($statuses as $status) {
            $this->shift->update(['status' => $status]);
            try {
                $this->shiftService->submitClosingCount($this->shift, $this->cashier, '1000.00');
                $this->fail("Should have rejected status: {$status}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Current status: ' . $status, $e->getMessage());
            }
        }
    }

    /** AC 23: Rejects cross-cashier closing */
    public function test_it_rejects_cross_cashier_closing(): void
    {
        $otherCashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherCashier->assignToBranch($this->branch);
        $role = Role::where('name', 'Cashier')->first();
        $otherCashier->assignRole($role);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('belongs to another cashier');

        $this->shiftService->submitClosingCount($this->shift, $otherCashier, '1000.00');
    }

    /** AC 24: Rejects cross-tenant/branch closing */
    public function test_it_rejects_scope_mismatch(): void
    {
        // 1. Tenant mismatch
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        
        try {
            $this->shiftService->submitClosingCount($this->shift, $this->cashier, '1000.00');
            $this->fail('Should have rejected tenant mismatch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cross-tenant', $e->getMessage());
        }

        // 2. Branch mismatch
        app(TenantContext::class)->setTenant($this->tenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        app(BranchContext::class)->setBranch($otherBranch);

        try {
            $this->shiftService->submitClosingCount($this->shift, $this->cashier, '1000.00');
            $this->fail('Should have rejected branch mismatch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('branch mismatch', $e->getMessage());
        }
    }

    /** AC 25: Blind close rule preserved */
    public function test_blind_close_rule_preserved(): void
    {
        // Shift is open, expected_cash_amount should be null or 0.0000 initially
        $this->assertNull($this->shift->expected_cash_amount);
        
        $this->shiftService->submitClosingCount($this->shift, $this->cashier, '1000.00');
        
        $this->shift->refresh();
        $this->assertEquals('1000.0000', (string) $this->shift->expected_cash_amount);
    }

    /** AC 26, 27, 28: No side effects */
    public function test_it_has_no_financial_side_effects(): void
    {
        $initialOutboxCount = \DB::table('accounting_outbox')->count();
        $initialSaleCount = \DB::table('sales')->count();

        $this->shiftService->submitClosingCount($this->shift, $this->cashier, '1000.00');

        $this->assertEquals($initialOutboxCount, \DB::table('accounting_outbox')->count());
        $this->assertEquals($initialSaleCount, \DB::table('sales')->count());
    }
}
