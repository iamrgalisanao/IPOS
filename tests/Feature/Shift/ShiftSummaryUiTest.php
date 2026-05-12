<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\User;
use App\Models\CashDrawerEvent;
use App\Models\PaymentMethod;
use App\Models\SalePayment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithShifts;
use Tests\Traits\InteractsWithTenants;
use Inertia\Testing\AssertableInertia as Assert;

class ShiftSummaryUiTest extends TestCase
{
    use RefreshDatabase, InteractsWithTenants, InteractsWithShifts;

    protected User $admin;
    protected User $manager;
    protected User $cashier;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setupTenantContext();
        
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $this->admin = $this->createTenantUser('admin');
        $this->givePermissionTo($this->admin, 'view_all_shifts');
        $this->givePermissionTo($this->admin, 'view_shift');

        $this->manager = $this->createTenantUser('manager');
        $this->manager->branches()->attach($this->branch);
        $this->givePermissionTo($this->manager, 'view_branch_shifts');
        $this->givePermissionTo($this->manager, 'view_shift');

        $this->cashier = $this->createTenantUser('cashier');
        $this->cashier->branches()->attach($this->branch);
        $this->givePermissionTo($this->cashier, 'view_shift');
    }

    public function test_authorized_cashier_can_view_own_shift_summary()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN
        ]);

        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('shifts.show', $shift->id))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shift/Show')
                ->has('shift', fn (Assert $p) => $p
                    ->where('id', $shift->id)
                    ->where('cashier_id', $this->cashier->id)
                    ->etc()
                )
            );
    }

    public function test_cashier_cannot_view_another_cashiers_shift()
    {
        $otherCashier = $this->createTenantUser('other_cashier');
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $otherCashier->id
        ]);

        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('shifts.show', $shift->id))
            ->assertStatus(403);
    }

    public function test_branch_manager_can_view_assigned_branch_shift_summaries()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id
        ]);

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->get(route('shifts.show', $shift->id))
            ->assertStatus(200);
    }

    public function test_branch_manager_cannot_view_other_branch_shift_summaries()
    {
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'cashier_id' => $this->cashier->id
        ]);

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id) // Current context
            ->get(route('shifts.show', $shift->id))
            ->assertStatus(403);
    }

    public function test_shift_summary_displays_correct_financial_data()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'status' => Shift::STATUS_APPROVED,
            'opening_cash_amount' => 1000,
            'expected_cash_amount' => 1500,
            'counted_cash_amount' => 1490,
            'variance_amount' => -10,
            'approved_at' => now(),
            'approved_by' => $this->admin->id
        ]);

        // Add an event
        CashDrawerEvent::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $shift->id,
            'event_type' => 'cash_drop',
            'amount' => 500,
            'occurred_at' => now()
        ]);

        $this->actingAs($this->admin)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('shifts.show', $shift->id))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shift/Show')
                ->has('shift', fn (Assert $p) => $p
                    ->where('opening_cash_amount', '1000.0000')
                    ->where('expected_cash_amount', '1500.0000')
                    ->where('counted_cash_amount', '1490.0000')
                    ->where('variance_amount', '-10.0000')
                    ->has('cash_drawer_events', 1)
                    ->etc()
                )
            );
    }
}
