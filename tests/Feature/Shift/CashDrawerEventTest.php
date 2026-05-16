<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\CashDrawerEvent;
use App\Models\Permission;
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

class CashDrawerEventTest extends TestCase
{
    use RefreshDatabase, InteractsWithShifts;

    protected ShiftService $shiftService;
    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
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
        
        $role = Role::where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->first();
        $this->cashier->assignRole($role);

        $this->shiftService = app(ShiftService::class);
        $this->shift = $this->openShiftFor($this->cashier, $this->branch);
    }

    /** AC 1, 2, 3, 4, 11: Successful recording of operational events */
    public function test_it_records_valid_operational_events(): void
    {
        $eventTypes = [
            CashDrawerEvent::TYPE_CASH_DROP,
            CashDrawerEvent::TYPE_CASH_TOP_UP,
            CashDrawerEvent::TYPE_CASH_IN,
            CashDrawerEvent::TYPE_CASH_OUT,
        ];

        foreach ($eventTypes as $type) {
            $event = $this->shiftService->recordDrawerEvent(
                $this->shift,
                $this->cashier,
                $type,
                '500.00',
                'TEST_REASON',
                'Optional notes'
            );

            $this->assertEquals($type, $event->event_type);
            $this->assertEquals('500.0000', $event->amount);
            $this->assertEquals('TEST_REASON', $event->reason_code);
            $this->assertEquals('Optional notes', $event->reason_notes);
            $this->assertEquals($this->shift->id, $event->shift_id);
            $this->assertEquals($this->cashier->id, $event->cashier_id);
            $this->assertEquals($this->cashier->id, $event->created_by);
            $this->assertNotNull($event->occurred_at);

            $this->assertDatabaseHas('cash_drawer_events', [
                'id' => $event->id,
                'event_type' => $type,
                'amount' => '500.0000'
            ]);
        }
    }

    /** AC 5, 6: Reject events for non-open shifts */
    public function test_it_rejects_events_for_closed_shifts(): void
    {
        $this->shift->update(['status' => Shift::STATUS_CLOSED]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot record event for a closed shift');

        $this->shiftService->recordDrawerEvent(
            $this->shift,
            $this->cashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '100.00',
            'TEST'
        );
    }

    /** AC 8, 9: Reject zero/negative amounts */
    public function test_it_rejects_invalid_amounts(): void
    {
        $invalidAmounts = ['0.00', '-10.00', 'abc'];

        foreach ($invalidAmounts as $amount) {
            try {
                $this->shiftService->recordDrawerEvent(
                    $this->shift,
                    $this->cashier,
                    CashDrawerEvent::TYPE_CASH_DROP,
                    $amount,
                    'TEST'
                );
                $this->fail("Should have thrown exception for amount: {$amount}");
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                // Expected
                $this->assertStringContainsString('amount must be positive', $e->getMessage());
            }
        }
    }

    /** AC 7: Reject invalid event types */
    public function test_it_rejects_invalid_event_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid drawer event type');

        $this->shiftService->recordDrawerEvent(
            $this->shift,
            $this->cashier,
            'invalid_type',
            '100.00',
            'TEST'
        );
    }

    /** AC 10: Reject empty reason_code */
    public function test_it_requires_reason_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reason code is required');

        $this->shiftService->recordDrawerEvent(
            $this->shift,
            $this->cashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '100.00',
            ' '
        );
    }

    /** AC 14: User without manage_cash_drawer is rejected */
    public function test_it_requires_permission(): void
    {
        $plainUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $plainUser->assignToBranch($this->branch);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: missing manage_cash_drawer permission');

        $this->shiftService->recordDrawerEvent(
            $this->shift,
            $plainUser,
            CashDrawerEvent::TYPE_CASH_DROP,
            '100.00',
            'TEST'
        );
    }

    /** AC 15: Actor cannot record event on another cashier's shift */
    public function test_it_rejects_cross_cashier_events(): void
    {
        $otherCashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherCashier->assignToBranch($this->branch);
        $role = Role::where('name', 'Cashier')->first();
        $otherCashier->assignRole($role);

        // $otherCashier has permission, but tries to operate on $this->cashier's shift
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: shift belongs to another cashier');

        $this->shiftService->recordDrawerEvent(
            $this->shift, // Belongs to $this->cashier
            $otherCashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '100.00',
            'TEST'
        );
    }

    /** AC 16: Actor cannot record event for another branch */
    public function test_it_rejects_branch_mismatch(): void
    {
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        // Set context to other branch
        app(BranchContext::class)->setBranch($otherBranch);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shift branch mismatch');

        $this->shiftService->recordDrawerEvent(
            $this->shift, // Belongs to $this->branch
            $this->cashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '100.00',
            'TEST'
        );
    }

    /** AC 17: Actor cannot record event for another tenant */
    public function test_it_rejects_tenant_mismatch(): void
    {
        $otherTenant = Tenant::factory()->create();
        
        // Set context to other tenant
        app(TenantContext::class)->setTenant($otherTenant);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cross-tenant shift access blocked');

        $this->shiftService->recordDrawerEvent(
            $this->shift, // Belongs to $this->tenant
            $this->cashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '100.00',
            'TEST'
        );
    }

    /** AC 12: Audit log creation */
    public function test_it_creates_audit_log_for_drawer_events(): void
    {
        $this->shiftService->recordDrawerEvent(
            $this->shift,
            $this->cashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '100.00',
            'CASH_DROP_TEST'
        );

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'action' => 'cash_drawer_event_recorded',
            'auditable_type' => CashDrawerEvent::class,
        ]);
    }

    /** AC 13: CashDrawerEvent remains immutable */
    public function test_it_enforces_immutability(): void
    {
        $event = $this->shiftService->recordDrawerEvent(
            $this->shift,
            $this->cashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '100.00',
            'TEST'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $event->update(['amount' => '200.00']);
    }

    /** AC 18, 19, 20: No side effects */
    public function test_it_has_no_financial_side_effects(): void
    {
        $initialOutboxCount = \DB::table('accounting_outbox')->count();
        $initialSaleCount = \DB::table('sales')->count();

        $this->shiftService->recordDrawerEvent(
            $this->shift,
            $this->cashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '100.00',
            'TEST'
        );

        $this->assertEquals($initialOutboxCount, \DB::table('accounting_outbox')->count());
        $this->assertEquals($initialSaleCount, \DB::table('sales')->count());
        if (\Schema::hasTable('inventory_movements')) {
            $this->assertEquals(0, \DB::table('inventory_movements')->count());
        }
    }

    /** Threshold Guard Tests */
    public function test_it_allows_high_value_drop_for_authorized_manager(): void
    {
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $manager->assignToBranch($this->branch);
        $role = Role::where('name', 'Branch Manager')->first();
        $manager->assignRole($role); // Has approve_shift permission

        $event = $this->shiftService->recordDrawerEvent(
            $this->shift,
            $manager,
            CashDrawerEvent::TYPE_CASH_DROP,
            '6000.00',
            'MANAGER_DROP'
        );

        $this->assertEquals('6000.0000', $event->amount);
        $this->assertEquals($manager->id, $event->created_by);
    }

    public function test_it_rejects_high_value_drop_for_unauthorized_cashier(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized: high-value cash drop requires manager approval.');

        $this->shiftService->recordDrawerEvent(
            $this->shift,
            $this->cashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '6000.00',
            'CASHIER_DROP'
        );
    }

    public function test_it_blocks_self_approval_for_high_value_drop(): void
    {
        // Give cashier manager permissions
        $role = Role::where('name', 'Branch Manager')->first();
        $this->cashier->assignRole($role);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Security Block: Cashiers cannot approve their own high-value cash drop.');

        $this->shiftService->recordDrawerEvent(
            $this->shift,
            $this->cashier,
            CashDrawerEvent::TYPE_CASH_DROP,
            '6000.00',
            'SELF_DROP'
        );
    }
}
