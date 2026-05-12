<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\CashDrawerEvent;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShiftDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure we are in a clean state
        $this->artisan('migrate');
    }

    protected function setContext(Tenant $tenant, ?Branch $branch = null): void
    {
        app(TenantContext::class)->setTenant($tenant);
        if ($branch) {
            app(BranchContext::class)->setBranch($branch);
        }
    }

    public function test_shifts_table_exists_with_required_fields(): void
    {
        $this->assertTrue(Schema::hasTable('shifts'));
        $this->assertTrue(Schema::hasColumns('shifts', [
            'id', 'tenant_id', 'branch_id', 'cashier_id', 'opened_by', 'approved_by', 'closed_by',
            'status', 'opening_cash_amount', 'counted_cash_amount', 'expected_cash_amount', 'variance_amount',
            'opened_at', 'closing_submitted_at', 'approved_at', 'closed_at', 'closing_notes', 'manager_notes',
            'created_at', 'updated_at'
        ]));
    }

    public function test_cash_drawer_events_table_exists_with_required_fields(): void
    {
        $this->assertTrue(Schema::hasTable('cash_drawer_events'));
        $this->assertTrue(Schema::hasColumns('cash_drawer_events', [
            'id', 'tenant_id', 'branch_id', 'shift_id', 'cashier_id',
            'event_type', 'amount', 'reason_code', 'reason_notes',
            'created_by', 'occurred_at', 'created_at', 'updated_at'
        ]));
    }

    public function test_sale_payments_has_nullable_shift_id(): void
    {
        $this->assertTrue(Schema::hasColumn('sale_payments', 'shift_id'));
    }

    public function test_shift_model_relationships(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $manager->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => 1000.00,
            'opened_at' => now(),
        ]);

        $this->assertInstanceOf(Tenant::class, $shift->tenant);
        $this->assertInstanceOf(Branch::class, $shift->branch);
        $this->assertInstanceOf(User::class, $shift->cashier);
        $this->assertInstanceOf(User::class, $shift->openedByUser);
        $this->assertEquals($tenant->id, $shift->tenant_id);
        $this->assertEquals($branch->id, $shift->branch_id);
        $this->assertEquals($cashier->id, $shift->cashier_id);
    }

    public function test_cash_drawer_event_model_relationships(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        
        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => 1000.00,
            'opened_at' => now(),
        ]);

        $event = CashDrawerEvent::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'event_type' => CashDrawerEvent::TYPE_CASH_DROP,
            'amount' => 500.00,
            'reason_code' => 'MID_DAY_DROP',
            'created_by' => $cashier->id,
            'occurred_at' => now(),
        ]);

        $this->assertInstanceOf(Shift::class, $event->shift);
        $this->assertInstanceOf(User::class, $event->cashier);
        $this->assertInstanceOf(User::class, $event->createdBy);
        $this->assertEquals($shift->id, $event->shift_id);
    }

    public function test_decimal_fields_use_safe_precision(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        $shift = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => 1234567.8901,
            'opened_at' => now(),
        ]);

        $this->assertEquals('1234567.8901', (string) $shift->fresh()->opening_cash_amount);
    }

    public function test_branch_isolation_enforced(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);
        
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);
        
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        $shiftA = Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchA->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => 100.00,
            'opened_at' => now(),
        ]);

        // When context is Branch A, only Shift A should be visible
        $this->setContext($tenant, $branchA);
        $this->assertCount(1, Shift::all());
        
        // When context is Branch B, no shifts should be visible
        $this->setContext($tenant, $branchB);
        $this->assertCount(0, Shift::all(), 'Branch B should not see shifts from Branch A.');
    }

    public function test_foundation_creation_does_not_mutate_existing_data(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        // Snapshot counts
        $saleCount = \DB::table('sales')->count();
        $paymentCount = \DB::table('sale_payments')->count();
        $inventoryCount = \DB::table('branch_inventories')->count();

        Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => 100.00,
            'opened_at' => now(),
        ]);

        CashDrawerEvent::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'shift_id' => \App\Models\Shift::first()->id,
            'cashier_id' => $cashier->id,
            'event_type' => CashDrawerEvent::TYPE_OPENING_CASH,
            'amount' => 100.00,
            'reason_code' => 'INITIAL',
            'created_by' => $cashier->id,
            'occurred_at' => now(),
        ]);

        $this->assertEquals($saleCount, \DB::table('sales')->count());
        $this->assertEquals($paymentCount, \DB::table('sale_payments')->count());
        $this->assertEquals($inventoryCount, \DB::table('branch_inventories')->count());
    }

    public function test_foundation_creation_does_not_trigger_side_effects(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        \DB::table('accounting_outbox')->truncate();

        Shift::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => 100.00,
            'opened_at' => now(),
        ]);

        $this->assertEquals(0, \DB::table('accounting_outbox')->count(), 'Shift creation should not trigger accounting outbox events.');
    }
}
