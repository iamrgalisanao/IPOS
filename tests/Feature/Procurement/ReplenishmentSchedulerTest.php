<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReplenishmentSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure clean memory states before each test run
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    /**
     * Test validation target: Only active tenants with procurement.advanced feature are processed.
     */
    public function test_scheduler_runs_automatically_for_entitled_tenants(): void
    {
        // 1. Entitled, Active Tenant A
        $tenantA = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'enterprise', // has procurement.advanced in config
            ]
        ]);

        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $catA = ProductCategory::create(['tenant_id' => $tenantA->id, 'name' => 'Pastry', 'code' => 'PAS']);
        $supA = Supplier::create(['tenant_id' => $tenantA->id, 'code' => 'SUP-A', 'name' => 'Supplier A', 'is_active' => true]);
        $prodA = Product::factory()->create([
            'tenant_id' => $tenantA->id,
            'product_category_id' => $catA->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supA->id,
            'cost_price' => 10.0000
        ]);
        BranchInventory::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'product_id' => $prodA->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);
        app(TenantContext::class)->clear();

        // 2. Non-Entitled, Active Tenant B (basic plan has no procurement.advanced)
        $tenantB = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'basic',
            ]
        ]);

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $catB = ProductCategory::create(['tenant_id' => $tenantB->id, 'name' => 'Coffee', 'code' => 'COF']);
        $supB = Supplier::create(['tenant_id' => $tenantB->id, 'code' => 'SUP-B', 'name' => 'Supplier B', 'is_active' => true]);
        $prodB = Product::factory()->create([
            'tenant_id' => $tenantB->id,
            'product_category_id' => $catB->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supB->id,
            'cost_price' => 10.0000
        ]);
        BranchInventory::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'product_id' => $prodB->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);
        app(TenantContext::class)->clear();

        // 3. Inactive, Entitled Tenant C
        $tenantC = Tenant::factory()->create([
            'status' => 'inactive',
            'subscription_metadata' => [
                'plan' => 'enterprise',
            ]
        ]);

        app(TenantContext::class)->setTenant($tenantC);
        $branchC = Branch::factory()->create(['tenant_id' => $tenantC->id, 'status' => 'active']);
        $userC = User::factory()->create(['tenant_id' => $tenantC->id, 'status' => 'active']);
        $catC = ProductCategory::create(['tenant_id' => $tenantC->id, 'name' => 'Soda', 'code' => 'SOD']);
        $supC = Supplier::create(['tenant_id' => $tenantC->id, 'code' => 'SUP-C', 'name' => 'Supplier C', 'is_active' => true]);
        $prodC = Product::factory()->create([
            'tenant_id' => $tenantC->id,
            'product_category_id' => $catC->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supC->id,
            'cost_price' => 10.0000
        ]);
        BranchInventory::create([
            'tenant_id' => $tenantC->id,
            'branch_id' => $branchC->id,
            'product_id' => $prodC->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);
        app(TenantContext::class)->clear();

        // Run scheduler artisan command
        $exitCode = Artisan::call('ipos:generate-replenishment-drafts');
        $this->assertEquals(0, $exitCode);

        // Verify draft PO was generated for Tenant A ONLY using withoutGlobalScope to bypass check outside context
        $this->assertEquals(1, PurchaseOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenantA->id)->count());
        $this->assertEquals(0, PurchaseOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenantB->id)->count());
        $this->assertEquals(0, PurchaseOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenantC->id)->count());
    }

    /**
     * Test validation target: Database queries run within context scopes, no leakage occurs across tenants.
     */
    public function test_scheduler_isolates_tenant_contexts_strictly(): void
    {
        // Tenant 1
        $tenant1 = Tenant::factory()->create(['status' => 'active', 'subscription_metadata' => ['plan' => 'enterprise']]);
        app(TenantContext::class)->setTenant($tenant1);
        $branch1 = Branch::factory()->create(['tenant_id' => $tenant1->id, 'status' => 'active']);
        $user1 = User::factory()->create(['tenant_id' => $tenant1->id, 'status' => 'active']);
        $cat1 = ProductCategory::create(['tenant_id' => $tenant1->id, 'name' => 'Category 1', 'code' => 'C1']);
        $sup1 = Supplier::create(['tenant_id' => $tenant1->id, 'code' => 'SUP-1', 'name' => 'Supplier 1', 'is_active' => true]);
        $prod1 = Product::factory()->create([
            'tenant_id' => $tenant1->id,
            'product_category_id' => $cat1->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $sup1->id,
            'cost_price' => 10.0000
        ]);
        BranchInventory::create([
            'tenant_id' => $tenant1->id,
            'branch_id' => $branch1->id,
            'product_id' => $prod1->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);
        app(TenantContext::class)->clear();

        // Tenant 2
        $tenant2 = Tenant::factory()->create(['status' => 'active', 'subscription_metadata' => ['plan' => 'enterprise']]);
        app(TenantContext::class)->setTenant($tenant2);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant2->id, 'status' => 'active']);
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id, 'status' => 'active']);
        $cat2 = ProductCategory::create(['tenant_id' => $tenant2->id, 'name' => 'Category 2', 'code' => 'C2']);
        $sup2 = Supplier::create(['tenant_id' => $tenant2->id, 'code' => 'SUP-2', 'name' => 'Supplier 2', 'is_active' => true]);
        $prod2 = Product::factory()->create([
            'tenant_id' => $tenant2->id,
            'product_category_id' => $cat2->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $sup2->id,
            'cost_price' => 20.0000
        ]);
        BranchInventory::create([
            'tenant_id' => $tenant2->id,
            'branch_id' => $branch2->id,
            'product_id' => $prod2->id,
            'current_stock' => 10,
            'reorder_level' => 30,
            'par_level' => 50,
            'status' => 'active'
        ]);
        app(TenantContext::class)->clear();

        // Run the command
        Artisan::call('ipos:generate-replenishment-drafts');

        // Confirm isolation using withoutGlobalScope to bypass context restriction
        $po1 = PurchaseOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenant1->id)->first();
        $po2 = PurchaseOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenant2->id)->first();

        $this->assertNotNull($po1);
        $this->assertNotNull($po2);

        $this->assertEquals($branch1->id, $po1->branch_id);
        $this->assertEquals($sup1->id, $po1->supplier_id);
        $this->assertEquals(950.0000, (float) $po1->total_estimated_amount);
        $this->assertEquals($prod1->id, $po1->lines()->first()->product_id);

        $this->assertEquals($branch2->id, $po2->branch_id);
        $this->assertEquals($sup2->id, $po2->supplier_id);
        $this->assertEquals(800.0000, (float) $po2->total_estimated_amount);
        $this->assertEquals($prod2->id, $po2->lines()->first()->product_id);

        // Verify context is cleared in memory
        $this->assertFalse(app(TenantContext::class)->hasTenant());
    }

    /**
     * Test validation target: If one tenant run fails, it rolls back its transaction,
     * clears the context, and continues processing remaining tenants successfully.
     */
    public function test_scheduler_recovers_gracefully_from_tenant_errors(): void
    {
        // 1. Tenant A (will fail because it has no creator user)
        $tenantA = Tenant::factory()->create(['status' => 'active', 'subscription_metadata' => ['plan' => 'enterprise']]);
        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        // Crucial: No active user created for Tenant A! This forces processTenant to fail
        $catA = ProductCategory::create(['tenant_id' => $tenantA->id, 'name' => 'Category A', 'code' => 'CA']);
        $supA = Supplier::create(['tenant_id' => $tenantA->id, 'code' => 'SUP-A', 'name' => 'Supplier A', 'is_active' => true]);
        $prodA = Product::factory()->create([
            'tenant_id' => $tenantA->id,
            'product_category_id' => $catA->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supA->id,
            'cost_price' => 10.0000
        ]);
        BranchInventory::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'product_id' => $prodA->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);
        app(TenantContext::class)->clear();

        // 2. Tenant B (will succeed)
        $tenantB = Tenant::factory()->create(['status' => 'active', 'subscription_metadata' => ['plan' => 'enterprise']]);
        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $catB = ProductCategory::create(['tenant_id' => $tenantB->id, 'name' => 'Category B', 'code' => 'CB']);
        $supB = Supplier::create(['tenant_id' => $tenantB->id, 'code' => 'SUP-B', 'name' => 'Supplier B', 'is_active' => true]);
        $prodB = Product::factory()->create([
            'tenant_id' => $tenantB->id,
            'product_category_id' => $catB->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supB->id,
            'cost_price' => 20.0000
        ]);
        BranchInventory::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'product_id' => $prodB->id,
            'current_stock' => 10,
            'reorder_level' => 30,
            'par_level' => 50,
            'status' => 'active'
        ]);
        app(TenantContext::class)->clear();

        // Run Artisan command
        $exitCode = Artisan::call('ipos:generate-replenishment-drafts');
        $this->assertEquals(0, $exitCode);

        // Assert: Tenant A rolled back and has no POs
        $this->assertEquals(0, PurchaseOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenantA->id)->count());

        // Assert: Tenant B completed and has its PO draft
        $this->assertEquals(1, PurchaseOrder::withoutGlobalScope('tenant')->where('tenant_id', $tenantB->id)->count());

        // Assert: Tenant context is properly cleared in memory after recovery
        $this->assertFalse(app(TenantContext::class)->hasTenant());
    }

    /**
     * Test validation target: Executing the scheduler multiple times updates existing drafts without duplication.
     */
    public function test_scheduler_prevents_duplicate_run_deadlocks(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active', 'subscription_metadata' => ['plan' => 'enterprise']]);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['tenant_id' => $tenant->id, 'name' => 'Category', 'code' => 'CAT']);
        $sup = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUP-C', 'name' => 'Supplier C', 'is_active' => true]);
        $prod = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'product_category_id' => $cat->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $sup->id,
            'cost_price' => 10.0000
        ]);
        $inventory = BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $prod->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);
        app(TenantContext::class)->clear();

        // First Scheduler Run
        Artisan::call('ipos:generate-replenishment-drafts');
        $this->assertEquals(1, PurchaseOrder::withoutGlobalScope('tenant')->count());

        // Update the inventory stock level
        app(TenantContext::class)->setTenant($tenant);
        $inventory->update(['current_stock' => 15]); // Gap is now 100 - 15 = 85
        app(TenantContext::class)->clear();

        // Second Scheduler Run
        Artisan::call('ipos:generate-replenishment-drafts');

        // Document count should remain exactly 1 (no duplicate generated!)
        $this->assertEquals(1, PurchaseOrder::withoutGlobalScope('tenant')->count());

        $po = PurchaseOrder::withoutGlobalScope('tenant')->first();
        $this->assertEquals(850.0000, (float) $po->total_estimated_amount);
        $this->assertEquals(85.0000, (float) $po->lines()->first()->ordered_quantity);
    }
}
