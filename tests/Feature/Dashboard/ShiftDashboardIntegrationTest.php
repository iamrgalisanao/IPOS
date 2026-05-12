<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenants;
use Inertia\Testing\AssertableInertia as Assert;

class ShiftDashboardIntegrationTest extends TestCase
{
    use RefreshDatabase, InteractsWithTenants;

    protected User $manager;
    protected User $cashier;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setupTenantContext();
        
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $this->manager = $this->createTenantUser('manager');
        $this->manager->branches()->attach($this->branch);
        $this->givePermissionTo($this->manager, 'view_reports');
        $this->givePermissionTo($this->manager, 'view_all_shifts');

        $this->cashier = $this->createTenantUser('cashier');
        $this->cashier->branches()->attach($this->branch);
        $this->givePermissionTo($this->cashier, 'view_reports');
        $this->givePermissionTo($this->cashier, 'create_sale'); // POS user
    }

    public function test_dashboard_shows_active_shift_status_for_cashier()
    {
        $shift = Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN
        ]);

        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('dashboard', ['branch_id' => $this->branch->id]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('pulse.shift', fn (Assert $p) => $p
                    ->where('active_shift_id', $shift->id)
                    ->where('active_shift_status', Shift::STATUS_OPEN)
                    ->etc()
                )
            );
    }

    public function test_dashboard_shows_warning_when_no_active_shift_for_pos_user()
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('dashboard', ['branch_id' => $this->branch->id]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('pulse.shift', fn (Assert $p) => $p
                    ->where('active_shift_id', null)
                    ->where('is_pos_user', true)
                    ->etc()
                )
            );
    }

    public function test_dashboard_shows_pending_review_count_for_manager()
    {
        Shift::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'status' => Shift::STATUS_CLOSING_SUBMITTED
        ]);

        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('dashboard', ['branch_id' => $this->branch->id]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('pulse.shift', fn (Assert $p) => $p
                    ->where('pending_review_count', 3)
                    ->etc()
                )
            );
    }

    public function test_dashboard_shift_card_respects_branch_isolation()
    {
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        // Shift in another branch
        Shift::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'status' => Shift::STATUS_CLOSING_SUBMITTED
        ]);

        // Manager viewing my branch
        $this->actingAs($this->manager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('dashboard', ['branch_id' => $this->branch->id]))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('pulse.shift', fn (Assert $p) => $p
                    ->where('pending_review_count', 0)
                    ->etc()
                )
            );
    }
}
