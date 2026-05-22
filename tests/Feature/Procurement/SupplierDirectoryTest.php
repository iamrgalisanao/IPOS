<?php

namespace Tests\Feature\Procurement;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\Supplier;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    protected function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    /** @test */
    public function test_cashier_role_is_fully_blocked_from_supplier_management_and_directory(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $cashier->assignRole(Role::where('name', 'Cashier')->first());
        app(TenantContext::class)->clear();

        // Acting as cashier
        $this->actingAs($cashier);

        // 1. Blocked from index
        $this->get(route('procurement.suppliers.index'))->assertStatus(403);

        // 2. Blocked from create
        $this->get(route('procurement.suppliers.create'))->assertStatus(403);

        // 3. Blocked from store
        $this->post(route('procurement.suppliers.store'), [
            'code' => 'COCA',
            'name' => 'Coca Cola Inc',
        ])->assertStatus(403);
    }

    /** @test */
    public function test_branch_manager_can_view_suppliers_but_cannot_manage_them(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $supplier = Supplier::create([
            'code' => 'COCA',
            'name' => 'Coca Cola Inc',
            'is_active' => true,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Can view list
        $this->get(route('procurement.suppliers.index'))->assertStatus(200);

        // Can view single profile details
        $this->get(route('procurement.suppliers.show', $supplier->id))->assertStatus(200);

        // Cannot access create form
        $this->get(route('procurement.suppliers.create'))->assertStatus(403);

        // Cannot store new supplier
        $this->post(route('procurement.suppliers.store'), [
            'code' => 'PEPS',
            'name' => 'Pepsi Co',
        ])->assertStatus(403);

        // Cannot edit
        $this->get(route('procurement.suppliers.edit', $supplier->id))->assertStatus(403);

        // Cannot update
        $this->put(route('procurement.suppliers.update', $supplier->id), [
            'code' => 'COCA',
            'name' => 'Coca Cola Inc Hardened',
        ])->assertStatus(403);

        // Cannot toggle status
        $this->patch(route('procurement.suppliers.toggle-status', $supplier->id))->assertStatus(403);
    }

    /** @test */
    public function test_owner_admin_has_full_supplier_management_permissions(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        $this->actingAs($admin);

        // Access create form
        $this->get(route('procurement.suppliers.create'))->assertStatus(200);

        // Create new supplier
        $response = $this->post(route('procurement.suppliers.store'), [
            'code' => 'COCA',
            'name' => 'Coca-Cola Corp',
            'contact_name' => 'Juan Dela Cruz',
            'email' => 'juan@coca.com',
            'phone' => '+639170001122',
            'address' => 'Manila City',
            'payment_terms' => 'NET_30',
            'is_active' => true,
        ]);

        $response->assertRedirect(route('procurement.suppliers.index'));

        // Verify insertion under tenant context
        $this->setTenantContext($tenant);
        $this->assertDatabaseHas('suppliers', [
            'code' => 'COCA',
            'name' => 'Coca-Cola Corp',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $supplier = Supplier::where('code', 'COCA')->first();
        app(TenantContext::class)->clear();

        // Edit Supplier
        $this->get(route('procurement.suppliers.edit', $supplier->id))->assertStatus(200);

        // Update Supplier
        $updateResponse = $this->put(route('procurement.suppliers.update', $supplier->id), [
            'code' => 'COCA-UPDATED',
            'name' => 'Coca-Cola Corp New',
            'contact_name' => 'Juan Updated',
            'email' => 'juan.new@coca.com',
            'phone' => '+639170001122',
            'address' => 'Manila City New',
            'payment_terms' => 'NET_15',
            'is_active' => false,
        ]);

        $updateResponse->assertRedirect(route('procurement.suppliers.index'));

        $this->setTenantContext($tenant);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'code' => 'COCA-UPDATED',
            'name' => 'Coca-Cola Corp New',
            'is_active' => false,
        ]);
        app(TenantContext::class)->clear();

        // Toggle Status
        $toggleResponse = $this->patch(route('procurement.suppliers.toggle-status', $supplier->id));
        $toggleResponse->assertRedirect();

        $this->setTenantContext($tenant);
        $this->assertTrue($supplier->refresh()->is_active);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_tenant_isolation_is_strictly_enforced_across_all_supplier_routes(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(RbacSeeder::class)->seedForTenant($tenantA);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        // Create Tenant A Supplier
        $this->setTenantContext($tenantA);
        $supplierA = Supplier::create([
            'code' => 'SUPA',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userA->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        // Create Tenant B Supplier
        $this->setTenantContext($tenantB);
        $supplierB = Supplier::create([
            'code' => 'SUPB',
            'name' => 'Supplier B',
            'is_active' => true,
        ]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $userB->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        // 1. Tenant A cannot see Tenant B's suppliers in index
        $this->actingAs($userA);
        $response = $this->get(route('procurement.suppliers.index'));
        $response->assertStatus(200);
        
        $viewSuppliers = $response->original->getData()['page']['props']['suppliers'];
        $supplierIds = collect($viewSuppliers)->pluck('id')->toArray();
        $this->assertContains($supplierA->id, $supplierIds);
        $this->assertNotContains($supplierB->id, $supplierIds);

        // 2. Tenant A cannot view Tenant B's supplier profile details
        $this->get(route('procurement.suppliers.show', $supplierB->id))->assertStatus(404);

        // 3. Tenant A cannot edit Tenant B's supplier
        $this->get(route('procurement.suppliers.edit', $supplierB->id))->assertStatus(404);

        // 4. Tenant A cannot update Tenant B's supplier
        $this->put(route('procurement.suppliers.update', $supplierB->id), [
            'code' => 'HACK',
            'name' => 'Hack Corp',
        ])->assertStatus(404);

        // 5. Tenant A cannot toggle Tenant B's supplier status
        $this->patch(route('procurement.suppliers.toggle-status', $supplierB->id))->assertStatus(404);
    }

    /** @test */
    public function test_supplier_shortcode_uniqueness_is_scoped_to_tenant_id(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(RbacSeeder::class)->seedForTenant($tenantA);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        // Register supplier with shortcode 'COCA' in Tenant A
        $this->setTenantContext($tenantA);
        $supplierA = Supplier::create([
            'code' => 'COCA',
            'name' => 'Coca-Cola Tenant A',
        ]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userA->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        // Authenticate Tenant A user to try to register 'COCA' again (should fail validation)
        $this->actingAs($userA);
        $response = $this->post(route('procurement.suppliers.store'), [
            'code' => 'COCA',
            'name' => 'Coca-Cola Dupe A',
        ]);
        $response->assertSessionHasErrors(['code']);

        // Authenticate Tenant B user to register 'COCA' (should succeed as tenant unique scoped)
        $this->setTenantContext($tenantB);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $userB->assignRole(Role::where('name', 'Owner/Admin')->first());
        app(TenantContext::class)->clear();

        $this->actingAs($userB);
        $successResponse = $this->post(route('procurement.suppliers.store'), [
            'code' => 'COCA',
            'name' => 'Coca-Cola Tenant B',
        ]);
        $successResponse->assertRedirect(route('procurement.suppliers.index'));

        // Verify both database records exist peacefully
        $this->setTenantContext($tenantA);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplierA->id,
            'code' => 'COCA',
            'tenant_id' => $tenantA->id,
        ]);
        app(TenantContext::class)->clear();

        $this->setTenantContext($tenantB);
        $this->assertDatabaseHas('suppliers', [
            'code' => 'COCA',
            'tenant_id' => $tenantB->id,
        ]);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_active_and_inactive_filters_work_as_expected(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole(Role::where('name', 'Owner/Admin')->first());

        $activeSup = Supplier::create(['code' => 'ACT1', 'name' => 'Active Supplier', 'is_active' => true]);
        $inactiveSup = Supplier::create(['code' => 'INA1', 'name' => 'Inactive Supplier', 'is_active' => false]);
        app(TenantContext::class)->clear();

        $this->actingAs($admin);

        // Filter active
        $activeResponse = $this->get(route('procurement.suppliers.index', ['status' => 'active']));
        $activeList = $activeResponse->original->getData()['page']['props']['suppliers'];
        $activeIds = collect($activeList)->pluck('id')->toArray();
        $this->assertContains($activeSup->id, $activeIds);
        $this->assertNotContains($inactiveSup->id, $activeIds);

        // Filter inactive
        $inactiveResponse = $this->get(route('procurement.suppliers.index', ['status' => 'inactive']));
        $inactiveList = $inactiveResponse->original->getData()['page']['props']['suppliers'];
        $inactiveIds = collect($inactiveList)->pluck('id')->toArray();
        $this->assertNotContains($activeSup->id, $inactiveIds);
        $this->assertContains($inactiveSup->id, $inactiveIds);
    }
}
