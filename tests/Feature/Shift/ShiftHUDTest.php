<?php

namespace Tests\Feature\Shift;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftHUDTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $branch;
    protected $cashier;
    protected $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);
        
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        
        (new \App\Services\RbacSeeder())->seedForTenant($this->tenant);
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);
        
        $cashierRole = \App\Models\Role::where('name', 'Cashier')->first();
        $managerRole = \App\Models\Role::where('name', 'Branch Manager')->first();
        
        $this->cashier->assignRole($cashierRole);
        $this->manager->assignRole($managerRole);
        
        $this->cashier->branches()->attach($this->branch);
        $this->manager->branches()->attach($this->branch);
    }

    public function test_cashier_can_retrieve_own_active_shift_hud_data_without_sensitive_fields()
    {
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash_amount' => 1000.00,
            'opening_denominations' => ['1000' => 1],
        ]);

        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->getJson(route('pos.active-shift'));

        $response->assertStatus(200)
            ->assertJson([
                'id' => $shift->id,
                'cashier_name' => $this->cashier->name,
                'opening_cash_amount' => 1000.00,
            ])
            ->assertJsonMissing(['expected_cash_amount'])
            ->assertJsonMissing(['is_manager_view']);
    }

    public function test_manager_can_see_expected_cash_amount_in_hud_data()
    {
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->manager->id,
            'opened_by' => $this->manager->id,
            'status' => Shift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash_amount' => 1000.00,
            'opening_denominations' => ['1000' => 1],
        ]);

        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->getJson(route('pos.active-shift'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'expected_cash_amount',
                'is_manager_view'
            ])
            ->assertJson([
                'expected_cash_amount' => 1000.00,
                'is_manager_view' => true,
            ]);
    }

    public function test_hud_endpoint_respects_tenant_isolation()
    {
        app(\App\Services\TenantContext::class)->clear();
        $tenantB = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        
        $shiftB = Shift::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash_amount' => 500.00,
        ]);

        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        // Attempt to access Tenant B shift while scoped to Tenant A
        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->getJson(route('pos.active-shift'));

        // Should return null or no shift because the global scope filters it out
        $response->assertStatus(200)
            ->assertExactJson([]); // json(null) returns empty body in getJson
    }

    public function test_manager_dashboard_only_includes_authorized_branch_shifts()
    {
        $branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        // Shift in Manager's branch
        $shiftA = Shift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash_amount' => 1000.00,
        ]);

        // Shift in another branch
        $cashierB = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $shiftB = Shift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchB->id,
            'cashier_id' => $cashierB->id,
            'opened_by' => $cashierB->id,
            'status' => Shift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash_amount' => 2000.00,
        ]);

        // Manager is only authorized for Branch A in this test setup
        $response = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('shifts.index'));

        $response->assertStatus(200);
        
        $activeShifts = $response->viewData('page')['props']['activeShifts'];
        
        $this->assertCount(1, $activeShifts);
        $this->assertEquals($shiftA->id, $activeShifts[0]['id']);
        
        // Ensure shiftB is NOT in the active monitor
        $this->assertFalse(collect($activeShifts)->contains('id', $shiftB->id));
    }

    public function test_cashier_can_unlock_terminal_with_correct_password()
    {
        $this->cashier->update(['password' => bcrypt('correct-password')]);

        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson(route('pos.unlock'), [
                'password' => 'correct-password',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Terminal unlocked.',
            ]);
    }

    public function test_cashier_cannot_unlock_terminal_with_incorrect_password()
    {
        $this->cashier->update(['password' => bcrypt('correct-password')]);

        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson(route('pos.unlock'), [
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid password.',
            ]);
    }

    public function test_manager_can_bypass_unlock_cashier_terminal()
    {
        $this->manager->update(['password' => bcrypt('manager-password')]);

        // Shift belongs to cashier
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash_amount' => 1000.00,
        ]);

        // Cashier is logged in, but manager bypass password is submitted
        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson(route('pos.unlock'), [
                'password' => 'manager-password',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'manager_bypass' => true,
                'manager_name' => $this->manager->name,
            ]);
    }

    public function test_non_manager_cannot_bypass_unlock_cashier_terminal()
    {
        $otherCashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cashierRole = \App\Models\Role::where('name', 'Cashier')->first();
        $otherCashier->assignRole($cashierRole);
        $otherCashier->update(['password' => bcrypt('other-password')]);

        // Cashier A is logged in, but other cashier's password is submitted
        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson(route('pos.unlock'), [
                'password' => 'other-password',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid password.',
            ]);
    }
}

