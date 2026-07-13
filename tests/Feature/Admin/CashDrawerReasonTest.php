<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\CashDrawerReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenants;

class CashDrawerReasonTest extends TestCase
{
    use RefreshDatabase, InteractsWithTenants;

    protected User $admin;
    protected User $cashier;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutVite();
        $this->setupTenantContext();

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'branch_code' => 'MAIN',
            'name' => 'Main Branch',
        ]);

        $this->admin = $this->createTenantUser('admin', ['email' => 'admin@bmad.coffee']);
        $this->givePermissionTo($this->admin, 'manage_cash_drawer_reasons');

        $this->cashier = $this->createTenantUser('cashier', ['email' => 'cashier@bmad.coffee']);
    }

    public function test_unauthorized_user_cannot_access_reasons_index(): void
    {
        $response = $this->actingAs($this->cashier)
            ->get(route('admin.cash-drawer-reasons.index'));

        $response->assertStatus(403);
    }

    public function test_authorized_admin_can_access_reasons_index(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.cash-drawer-reasons.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Admin/CashDrawerReasons/Index'));
    }

    public function test_admin_can_store_cash_drawer_reason(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.cash-drawer-reasons.store'), [
                'event_type' => 'cash_drop',
                'code' => 'CUSTOM_SKIM',
                'name' => 'Custom Skim Description',
                'branch_id' => null,
                'requires_manager_approval' => true,
                'sort_order' => 5,
            ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('cash_drawer_reasons', [
            'tenant_id' => $this->tenant->id,
            'event_type' => 'cash_drop',
            'code' => 'CUSTOM_SKIM',
            'requires_manager_approval' => true,
        ]);
    }

    public function test_admin_cannot_store_duplicate_code_for_same_scope(): void
    {
        CashDrawerReason::create([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'cash_drop',
            'code' => 'TEST_DUP',
            'name' => 'Test Duplicate',
            'branch_id' => null,
            'requires_manager_approval' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.cash-drawer-reasons.store'), [
                'event_type' => 'cash_drop',
                'code' => 'TEST_DUP',
                'name' => 'Another Duplicate',
                'branch_id' => null,
                'requires_manager_approval' => false,
                'sort_order' => 1,
            ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_admin_can_update_reason(): void
    {
        $reason = CashDrawerReason::create([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'cash_drop',
            'code' => 'UPDATABLE',
            'name' => 'Old Name',
            'branch_id' => null,
            'requires_manager_approval' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('admin.cash-drawer-reasons.update', $reason->id), [
                'name' => 'New Name',
                'requires_manager_approval' => true,
                'is_active' => true,
                'sort_order' => 10,
            ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('cash_drawer_reasons', [
            'id' => $reason->id,
            'name' => 'New Name',
            'requires_manager_approval' => true,
            'sort_order' => 10,
        ]);
    }

    public function test_admin_can_deactivate_reason_via_destroy(): void
    {
        $reason = CashDrawerReason::create([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'cash_drop',
            'code' => 'DEACTIVATABLE',
            'name' => 'Deactivatable Reason',
            'branch_id' => null,
            'requires_manager_approval' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.cash-drawer-reasons.destroy', $reason->id));

        $response->assertRedirect();
        
        $this->assertDatabaseHas('cash_drawer_reasons', [
            'id' => $reason->id,
            'is_active' => false,
        ]);
    }
}
