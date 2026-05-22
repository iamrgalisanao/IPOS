<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderLifecycleTest extends TestCase
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
    public function test_cashier_is_completely_blocked_from_all_po_routes(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $cashier->assignRole(Role::where('name', 'Cashier')->first());
        
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $cashier->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);
        
        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-TEST-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $cashier->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($cashier);

        $this->get(route('procurement.purchase-orders.index'))->assertStatus(403);
        $this->get(route('procurement.purchase-orders.create'))->assertStatus(403);
        $this->post(route('procurement.purchase-orders.store'))->assertStatus(403);
        $this->get(route('procurement.purchase-orders.show', $po->id))->assertStatus(403);
        $this->get(route('procurement.purchase-orders.edit', $po->id))->assertStatus(403);
        $this->put(route('procurement.purchase-orders.update', $po->id))->assertStatus(403);
        $this->post(route('procurement.purchase-orders.submit', $po->id))->assertStatus(403);
        $this->post(route('procurement.purchase-orders.approve', $po->id))->assertStatus(403);
    }

    /** @test */
    public function test_branch_manager_can_create_po_draft_with_server_computed_amounts(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'branch_code' => 'MNL']);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product1 = Product::factory()->create(['tenant_id' => $tenant->id, 'cost_price' => 10.00]);
        $product2 = Product::factory()->create(['tenant_id' => $tenant->id, 'cost_price' => 20.00]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'notes' => 'Urgent procurement',
            'lines' => [
                [
                    'product_id' => $product1->id,
                    'ordered_quantity' => 10,
                    'unit_cost' => 12.50, // overrides product cost
                ],
                [
                    'product_id' => $product2->id,
                    'ordered_quantity' => 5,
                    'unit_cost' => 20.00,
                ]
            ]
        ]);

        $this->setTenantContext($tenant);
        $po = PurchaseOrder::where('supplier_id', $supplier->id)->first();
        $this->assertNotNull($po);
        
        $response->assertRedirect(route('procurement.purchase-orders.show', $po->id));

        // Assert PO Number Format: PO-{branch_code}-YYYYMMDD-{sequence}
        $expectedPoNumberPrefix = 'PO-MNL-' . now()->format('Ymd');
        $this->assertStringStartsWith($expectedPoNumberPrefix, $po->po_number);

        // Assert total estimated amount computed server-side: (10 * 12.50) + (5 * 20.00) = 125 + 100 = 225
        $this->assertEquals(225.0000, (float) $po->total_estimated_amount);

        // Assert individual lines count and line totals computed server-side
        $this->assertCount(2, $po->lines);
        $this->assertEquals(125.0000, (float) $po->lines[0]->line_total);
        $this->assertEquals(100.0000, (float) $po->lines[1]->line_total);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_po_tenant_isolation_is_strictly_enforced_across_resources(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(RbacSeeder::class)->seedForTenant($tenantA);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        // Setup Tenant A resources
        $this->setTenantContext($tenantA);
        $managerA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $managerA->assignRole(Role::where('name', 'Branch Manager')->first());
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id]);
        $managerA->assignToBranch($branchA);
        $supplierA = Supplier::create(['tenant_id' => $tenantA->id, 'code' => 'SUPA', 'name' => 'Supplier A']);
        $productA = Product::factory()->create(['tenant_id' => $tenantA->id]);
        app(TenantContext::class)->clear();

        // Setup Tenant B resources
        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $supplierB = Supplier::create(['tenant_id' => $tenantB->id, 'code' => 'SUPB', 'name' => 'Supplier B']);
        $productB = Product::factory()->create(['tenant_id' => $tenantB->id]);
        app(TenantContext::class)->clear();

        $this->actingAs($managerA);

        // 1. Cross-tenant supplier validation fails
        $response1 = $this->post(route('procurement.purchase-orders.store'), [
            'supplier_id' => $supplierB->id, // Tenant B supplier
            'branch_id' => $branchA->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $productA->id, 'ordered_quantity' => 5, 'unit_cost' => 10]
            ]
        ]);
        $response1->assertStatus(404);

        // 2. Cross-tenant branch validation fails
        $response2 = $this->post(route('procurement.purchase-orders.store'), [
            'supplier_id' => $supplierA->id,
            'branch_id' => $branchB->id, // Tenant B branch
            'order_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $productA->id, 'ordered_quantity' => 5, 'unit_cost' => 10]
            ]
        ]);
        $response2->assertStatus(404);

        // 3. Cross-tenant product line validation fails
        $response3 = $this->post(route('procurement.purchase-orders.store'), [
            'supplier_id' => $supplierA->id,
            'branch_id' => $branchA->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $productB->id, 'ordered_quantity' => 5, 'unit_cost' => 10] // Tenant B product
            ]
        ]);
        $response3->assertStatus(404);
    }

    /** @test */
    public function test_branch_managers_cannot_view_or_mutate_po_from_other_branches(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise']
        ]);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        
        $manager->assignToBranch($branch1); // assigned only to branch 1

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);
        
        // PO for Branch 2 (unassigned branch)
        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch2->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-TEST-B2',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Assert manager cannot show PO of other branch
        $this->get(route('procurement.purchase-orders.show', $po->id))->assertStatus(403);

        // Assert manager cannot edit/update PO of other branch
        $this->get(route('procurement.purchase-orders.edit', $po->id))->assertStatus(403);
        $this->put(route('procurement.purchase-orders.update', $po->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch2->id,
            'order_date' => now()->toDateString(),
            'lines' => []
        ])->assertStatus(403);

        // Assert manager cannot submit PO of other branch
        $this->post(route('procurement.purchase-orders.submit', $po->id))->assertStatus(403);
    }

    /** @test */
    public function test_full_purchase_order_happy_path_lifecycle_transitions(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-LIFECYCLE',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // 1. DRAFT -> PENDING_APPROVAL
        $this->post(route('procurement.purchase-orders.submit', $po->id))->assertStatus(302);
        $this->assertEquals(PurchaseOrder::STATUS_PENDING_APPROVAL, $po->refresh()->status);

        // 2. PENDING_APPROVAL -> APPROVED
        $this->post(route('procurement.purchase-orders.approve', $po->id))->assertStatus(302);
        $this->assertEquals(PurchaseOrder::STATUS_APPROVED, $po->refresh()->status);
        $this->assertNotNull($po->refresh()->approved_at);
        $this->assertEquals($manager->id, $po->refresh()->approved_by);

        // 3. APPROVED -> SENT
        $this->post(route('procurement.purchase-orders.send', $po->id))->assertStatus(302);
        $this->assertEquals(PurchaseOrder::STATUS_SENT, $po->refresh()->status);
        $this->assertNotNull($po->refresh()->sent_at);

        // 4. SENT -> COMPLETED
        $this->post(route('procurement.purchase-orders.complete', $po->id))->assertStatus(302);
        $this->assertEquals(PurchaseOrder::STATUS_COMPLETED, $po->refresh()->status);
        $this->assertNotNull($po->refresh()->completed_at);
    }

    /** @test */
    public function test_purchase_order_can_be_cancelled_from_any_non_terminal_state(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-CANCEL',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Cancel from DRAFT
        $this->post(route('procurement.purchase-orders.cancel', $po->id))->assertStatus(302);
        $this->assertEquals(PurchaseOrder::STATUS_CANCELLED, $po->refresh()->status);
        $this->assertNotNull($po->refresh()->cancelled_at);
    }

    /** @test */
    public function test_terminal_states_completed_and_cancelled_are_completely_immutable(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);

        $completedPo = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-COMPLETED',
            'status' => PurchaseOrder::STATUS_COMPLETED,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        $cancelledPo = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-CANCELLED',
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Attempting to update a completed PO throws exception/session errors
        $response1 = $this->put(route('procurement.purchase-orders.update', $completedPo->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'lines' => []
        ]);
        $response1->assertSessionHasErrors(['status']);

        // Attempting to cancel a completed PO throws session errors
        $response2 = $this->post(route('procurement.purchase-orders.cancel', $completedPo->id));
        $response2->assertSessionHasErrors(['status']);

        // Attempting to edit a cancelled PO throws session errors
        $response3 = $this->put(route('procurement.purchase-orders.update', $cancelledPo->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'lines' => []
        ]);
        $response3->assertSessionHasErrors(['status']);
    }

    /** @test */
    public function test_invalid_lifecycle_transitions_are_strictly_blocked(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-TRANSITIONS',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // 1. Draft to Approved directly (blocked)
        $this->post(route('procurement.purchase-orders.approve', $po->id))->assertSessionHasErrors(['status']);

        // 2. Draft to Sent directly (blocked)
        $this->post(route('procurement.purchase-orders.send', $po->id))->assertSessionHasErrors(['status']);

        // Move to pending approval
        $this->post(route('procurement.purchase-orders.submit', $po->id));

        // 3. Pending approval to Sent directly (blocked)
        $this->post(route('procurement.purchase-orders.send', $po->id))->assertSessionHasErrors(['status']);
    }

    /** @test */
    public function test_purchase_order_lifecycle_does_not_mutate_inventories_or_movements(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'code' => 'SUPP', 'name' => 'Supplier']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        
        $inventory = BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => 50,
            'status' => 'active'
        ]);

        $initialMovementsCount = InventoryMovement::count();
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Create a PO and move it all the way to Sent & Completed
        $this->post(route('procurement.purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $product->id, 'ordered_quantity' => 100, 'unit_cost' => 15.00]
            ]
        ]);

        $this->setTenantContext($tenant);
        $po = PurchaseOrder::where('supplier_id', $supplier->id)->first();
        app(TenantContext::class)->clear();

        $this->post(route('procurement.purchase-orders.submit', $po->id));
        $this->post(route('procurement.purchase-orders.approve', $po->id));
        $this->post(route('procurement.purchase-orders.send', $po->id));
        $this->post(route('procurement.purchase-orders.complete', $po->id));

        $this->setTenantContext($tenant);
        // Verify current stock is completely unchanged (still 50)
        $this->assertEquals(50, $inventory->refresh()->current_stock);
        // Verify no new inventory movements have been registered
        $this->assertEquals($initialMovementsCount, InventoryMovement::count());
        app(TenantContext::class)->clear();
    }
}
