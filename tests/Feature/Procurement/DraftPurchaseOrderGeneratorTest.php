<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Procurement\DraftPurchaseOrderGenerator;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftPurchaseOrderGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected ProductCategory $category;
    protected DraftPurchaseOrderGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->category = ProductCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pastry',
            'code' => 'PAS'
        ]);

        $this->generator = app(DraftPurchaseOrderGenerator::class);
    }

    /** AC 1: Create a single draft PO for recommended product */
    public function test_generates_draft_po_for_reorder_recommendation(): void
    {
        $supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-A',
            'name' => 'Supplier A',
            'is_active' => true
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supplier->id,
            'cost_price' => 10.0000
        ]);

        // Stock = 5, ROP = 20, PAR = 100
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);

        $pos = $this->generator->generateForBranch($this->branch, $this->user);

        $this->assertCount(1, $pos);
        $po = $pos[0];

        $this->assertEquals(PurchaseOrder::STATUS_DRAFT, $po->status);
        $this->assertEquals($this->branch->id, $po->branch_id);
        $this->assertEquals($supplier->id, $po->supplier_id);
        $this->assertEquals(950.0000, (float) $po->total_estimated_amount); // 95 ordered * 10 unit_cost

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'draft'
        ]);

        $this->assertDatabaseHas('purchase_order_lines', [
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => 95.0000,
            'unit_cost' => 10.0000,
            'line_total' => 950.0000
        ]);
    }

    /** AC 2: Grouping draft POs by supplier */
    public function test_groups_draft_pos_by_supplier(): void
    {
        $supplier1 = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-B1',
            'name' => 'Supplier B1',
            'is_active' => true
        ]);

        $supplier2 = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-B2',
            'name' => 'Supplier B2',
            'is_active' => true
        ]);

        $product1 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supplier1->id,
            'cost_price' => 10.0000
        ]);

        $product2 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supplier2->id,
            'cost_price' => 20.0000
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product1->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'current_stock' => 10,
            'reorder_level' => 30,
            'par_level' => 50,
            'status' => 'active'
        ]);

        $pos = $this->generator->generateForBranch($this->branch, $this->user);

        // 2 distinct draft POs should be generated
        $this->assertCount(2, $pos);

        $suppliers = collect($pos)->pluck('supplier_id')->toArray();
        $this->assertContains($supplier1->id, $suppliers);
        $this->assertContains($supplier2->id, $suppliers);

        $po1 = collect($pos)->firstWhere('supplier_id', $supplier1->id);
        $this->assertEquals(950.0000, (float) $po1->total_estimated_amount);

        $po2 = collect($pos)->firstWhere('supplier_id', $supplier2->id);
        $this->assertEquals(800.0000, (float) $po2->total_estimated_amount); // 40 ordered * 20 unit_cost
    }

    /** AC 3: Repeated run updates/deduplicates instead of creating new POs */
    public function test_prevents_duplicate_draft_pos_and_updates_on_repeated_runs(): void
    {
        $supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-C',
            'name' => 'Supplier C',
            'is_active' => true
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supplier->id,
            'cost_price' => 10.0000
        ]);

        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);

        // First generator run
        $pos1 = $this->generator->generateForBranch($this->branch, $this->user);
        $this->assertCount(1, $pos1);
        $this->assertEquals(1, PurchaseOrder::count());

        // Update current stock to 10
        // New gap should be 100 - 10 = 90
        $inventory->update(['current_stock' => 10]);

        // Second generator run
        $pos2 = $this->generator->generateForBranch($this->branch, $this->user);
        $this->assertCount(1, $pos2);
        
        // Count should still be exactly 1 (no duplicates!)
        $this->assertEquals(1, PurchaseOrder::count());

        $po = PurchaseOrder::first();
        $this->assertEquals(900.0000, (float) $po->total_estimated_amount); // Recalculated!
        $this->assertEquals(1, $po->lines()->count());
        $this->assertEquals(90.0000, (float) $po->lines()->first()->ordered_quantity);
    }

    /** AC 4: Sync removes items that are no longer recommended */
    public function test_removes_lines_no_longer_requiring_replenishment(): void
    {
        $supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-D',
            'name' => 'Supplier D',
            'is_active' => true
        ]);

        $product1 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supplier->id,
            'cost_price' => 10.0000
        ]);

        $product2 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'preferred_supplier_id' => $supplier->id,
            'cost_price' => 20.0000
        ]);

        $inventory1 = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product1->id,
            'current_stock' => 5,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);

        $inventory2 = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'current_stock' => 10,
            'reorder_level' => 30,
            'par_level' => 50,
            'status' => 'active'
        ]);

        // First run generates PO with 2 lines
        $this->generator->generateForBranch($this->branch, $this->user);
        $this->assertEquals(2, PurchaseOrderLine::count());

        // Now replenish Product 2 so it is above reorder_level
        $inventory2->update(['current_stock' => 40]); // 40 >= ROP (30)

        // Second run
        $this->generator->generateForBranch($this->branch, $this->user);

        // Product 2 should be deleted from the PO lines
        $this->assertEquals(1, PurchaseOrderLine::count());
        $this->assertDatabaseMissing('purchase_order_lines', [
            'product_id' => $product2->id
        ]);
        $this->assertDatabaseHas('purchase_order_lines', [
            'product_id' => $product1->id
        ]);
    }
}
