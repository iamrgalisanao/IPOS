<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\ExpiryLot;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Procurement\ReplenishmentService;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplenishmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected ProductCategory $category;
    protected ReplenishmentService $replenishmentService;

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
            'name' => 'Coffee',
            'code' => 'COF'
        ]);

        $this->replenishmentService = app(ReplenishmentService::class);
    }

    /** AC 1: Basic replenishment recommendation based on dynamic calculation */
    public function test_basic_dynamic_replenishment_trigger(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true
        ]);

        // Daily consumption = 60 sold in 30 days = 2.0 per day
        $thirtyDaysAgo = Carbon::now()->subDays(15);
        for ($i = 0; $i < 6; $i++) {
            $sale = Sale::factory()->create([
                'tenant_id' => $this->tenant->id,
                'branch_id' => $this->branch->id,
                'status' => 'created'
            ]);
            SaleItem::create([
                'tenant_id' => $this->tenant->id,
                'branch_id' => $this->branch->id,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'quantity' => 10,
                'unit_price' => 5,
                'subtotal' => 50,
                'line_total' => 50,
                'created_at' => $thirtyDaysAgo,
                'is_inventory_tracked' => true
            ]);
        }

        // Branch inventory: ROP = (2.0 daily * 5 lead_time_days) + 10 safety = 20
        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 15, // Below calculated ROP (20)
            'reorder_level' => 0,  // Forces dynamic calculation
            'par_level' => 100,    // Target Stock
            'lead_time_days' => 5,
            'safety_stock_buffer' => 10,
            'status' => 'active'
        ]);

        $recs = $this->replenishmentService->getRecommendationsForBranch($this->branch);

        $this->assertCount(1, $recs);
        $this->assertEquals($product->id, $recs[0]['product_id']);
        $this->assertEquals(2.0, $recs[0]['daily_consumption_rate']);
        $this->assertEquals(20.0, $recs[0]['reorder_point']);
        // Order up to PAR: 100 - 15 = 85
        $this->assertEquals(85.0, $recs[0]['reorder_qty']);
        $this->assertEquals('Unassigned Supplier', $recs[0]['supplier_name']);
    }

    /** AC 2: Manual ROP override takes precedence over dynamic ROP */
    public function test_manual_rop_override_precedence(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true
        ]);

        // Branch inventory has reorder_level = 50 (higher than calculated ROP)
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 45, // Below manual reorder_level (50), but above calculated dynamic ROP (0)
            'reorder_level' => 50, // Manual override
            'par_level' => 100,
            'lead_time_days' => 5,
            'safety_stock_buffer' => 10,
            'status' => 'active'
        ]);

        $recs = $this->replenishmentService->getRecommendationsForBranch($this->branch);

        $this->assertCount(1, $recs);
        $this->assertEquals(50.0, $recs[0]['reorder_point']);
        // Order up to PAR: 100 - 45 = 55
        $this->assertEquals(55.0, $recs[0]['reorder_qty']);
    }

    /** AC 3: Outstanding PO quantities are included in stock basis */
    public function test_outstanding_po_quantities_avoid_double_ordering(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true
        ]);

        $supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-01',
            'name' => 'Supplier One',
            'is_active' => true
        ]);

        // Create an outstanding PO (draft state) for 30 units
        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-TEST-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => Carbon::now(),
            'created_by' => $this->user->id
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => 30,
            'unit_cost' => 3.5,
            'line_total' => 105
        ]);

        // Branch inventory: ROP = 50, Target (PAR) = 100
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 15,
            'reorder_level' => 50,
            'par_level' => 100,
            'status' => 'active'
        ]);

        // Effective Stock = 15 (current) + 30 (outstanding) = 45.
        // 45 < ROP (50) -> Still triggers replenishment suggestion.
        // Reorder qty = 100 (PAR) - 45 (effective) = 55.
        $recs = $this->replenishmentService->getRecommendationsForBranch($this->branch);

        $this->assertCount(1, $recs);
        $this->assertEquals(30.0, $recs[0]['outstanding_po_qty']);
        $this->assertEquals(55.0, $recs[0]['reorder_qty']);

        // Now update the outstanding PO line to 40 units
        // Effective Stock = 15 + 40 = 55.
        // 55 >= ROP (50) -> Replenishment should NOT trigger.
        PurchaseOrderLine::first()->update(['ordered_quantity' => 40]);

        $recs2 = $this->replenishmentService->getRecommendationsForBranch($this->branch);
        $this->assertCount(0, $recs2);
    }

    /** AC 4: Expired stock is excluded from stock basis (FEFO Alignment) */
    public function test_expired_lots_excluded_from_stock_basis(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'expiry_tracking_enabled' => true
        ]);

        // Canonical current_stock in BranchInventory is 25
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 25,
            'reorder_level' => 20,
            'par_level' => 100,
            'status' => 'active'
        ]);

        // 1. Unexpired Lot of 15 remaining
        ExpiryLot::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'batch_code' => 'BATCH-HEALTHY',
            'quantity_received' => 15,
            'quantity_remaining' => 15,
            'expiry_date' => Carbon::now()->addDays(30),
            'status' => 'active'
        ]);

        // 2. Expired Lot of 10 remaining
        ExpiryLot::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'batch_code' => 'BATCH-EXPIRED',
            'quantity_received' => 10,
            'quantity_remaining' => 10,
            'expiry_date' => Carbon::now()->subDays(2),
            'status' => 'active'
        ]);

        // Current Stock = 25.
        // Expired Stock = 10.
        // Clean Stock Basis = 25 - 10 = 15.
        // 15 < ROP (20) -> Triggers.
        // Reorder qty = 100 (PAR) - 15 (clean stock basis) = 85.
        $recs = $this->replenishmentService->getRecommendationsForBranch($this->branch);

        $this->assertCount(1, $recs);
        $this->assertEquals(10.0, $recs[0]['expired_stock']);
        $this->assertEquals(15.0, $recs[0]['clean_stock_basis']);
        $this->assertEquals(85.0, $recs[0]['reorder_qty']);
    }

    /** AC 5: Preferred supplier resolution hierarchy */
    public function test_preferred_supplier_resolution_hierarchy(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true
        ]);

        // Target BranchInventory
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 5,
            'reorder_level' => 10,
            'par_level' => 50,
            'status' => 'active'
        ]);

        // Level 3 Fallback: No preferred supplier and no historical POs
        $recs1 = $this->replenishmentService->getRecommendationsForBranch($this->branch);
        $this->assertEquals('Unassigned Supplier', $recs1[0]['supplier_name']);
        $this->assertNull($recs1[0]['supplier_id']);

        // Level 2 Fallback: Supplier from most recently completed Purchase Order
        $supplierPast = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-PAST',
            'name' => 'Past Supplier',
            'is_active' => true
        ]);

        $poCompleted = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplierPast->id,
            'po_number' => 'PO-COMP-001',
            'status' => PurchaseOrder::STATUS_COMPLETED,
            'order_date' => Carbon::now()->subDays(5),
            'completed_at' => Carbon::now()->subDays(4),
            'created_by' => $this->user->id
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $poCompleted->id,
            'product_id' => $product->id,
            'ordered_quantity' => 10,
            'unit_cost' => 4.0,
            'line_total' => 40
        ]);

        $recs2 = $this->replenishmentService->getRecommendationsForBranch($this->branch);
        $this->assertEquals($supplierPast->id, $recs2[0]['supplier_id']);
        $this->assertEquals('Past Supplier', $recs2[0]['supplier_name']);

        // Level 1: Direct product preferred supplier (takes precedence)
        $supplierPref = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUP-PREF',
            'name' => 'Preferred Supplier',
            'is_active' => true
        ]);

        $product->update(['preferred_supplier_id' => $supplierPref->id]);

        $recs3 = $this->replenishmentService->getRecommendationsForBranch($this->branch);
        $this->assertEquals($supplierPref->id, $recs3[0]['supplier_id']);
        $this->assertEquals('Preferred Supplier', $recs3[0]['supplier_name']);
    }
}
