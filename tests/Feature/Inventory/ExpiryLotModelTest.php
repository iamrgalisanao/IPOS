<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\ExpiryLot;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseReceiving;
use App\Models\PurchaseReceivingLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpiryLotModelTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected Branch $branchA;
    protected Branch $branchB;
    protected Product $productA;
    protected Product $productB;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();

        // Setup Tenant A environment
        $this->tenantA = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenantA);

        $this->branchA = Branch::create([
            'name' => 'Branch A',
            'branch_code' => 'BR-A',
            'status' => 'active',
        ]);

        $catA = ProductCategory::create([
            'name' => 'Category A',
            'code' => 'CAT-A',
        ]);

        $this->productA = Product::create([
            'product_category_id' => $catA->id,
            'name' => 'Product A',
            'sku' => 'SKU-A',
            'selling_price' => 100.00,
            'cost_price' => 50.00,
            'status' => 'active',
            'expiry_tracking_enabled' => true,
        ]);

        app(TenantContext::class)->clear();

        // Setup Tenant B environment
        $this->tenantB = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenantB);

        $this->branchB = Branch::create([
            'name' => 'Branch B',
            'branch_code' => 'BR-B',
            'status' => 'active',
        ]);

        $catB = ProductCategory::create([
            'name' => 'Category B',
            'code' => 'CAT-B',
        ]);

        $this->productB = Product::create([
            'product_category_id' => $catB->id,
            'name' => 'Product B',
            'sku' => 'SKU-B',
            'selling_price' => 200.00,
            'cost_price' => 100.00,
            'status' => 'active',
            'expiry_tracking_enabled' => false,
        ]);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_product_expiry_flag_default_is_false(): void
    {
        app(TenantContext::class)->setTenant($this->tenantA);
        
        $cat = ProductCategory::create(['name' => 'Cat Temp', 'code' => 'CAT-TEMP']);
        $product = Product::create([
            'product_category_id' => $cat->id,
            'name' => 'Default Product',
            'sku' => 'SKU-DEFAULT',
            'selling_price' => 10.00,
            'status' => 'active',
        ]);

        $product->refresh();
        $this->assertFalse($product->expiry_tracking_enabled);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_expiry_lot_can_be_created_under_tenant_context(): void
    {
        app(TenantContext::class)->setTenant($this->tenantA);

        $lot = ExpiryLot::create([
            'branch_id' => $this->branchA->id,
            'product_id' => $this->productA->id,
            'batch_code' => 'LOT-001',
            'quantity_received' => 100.0000,
            'quantity_remaining' => 100.0000,
            'expiry_date' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        $this->assertNotNull($lot->id);
        $this->assertEquals($this->tenantA->id, $lot->tenant_id);
        $this->assertEquals(100.0000, (float) $lot->quantity_received);
        $this->assertEquals(100.0000, (float) $lot->quantity_remaining);
        $this->assertEquals('active', $lot->status);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_expiry_lot_scoping_isolation(): void
    {
        // 1. Create a lot for Tenant A
        app(TenantContext::class)->setTenant($this->tenantA);
        $lotA = ExpiryLot::create([
            'branch_id' => $this->branchA->id,
            'product_id' => $this->productA->id,
            'batch_code' => 'LOT-001',
            'quantity_received' => 50.0000,
            'quantity_remaining' => 50.0000,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
        app(TenantContext::class)->clear();

        // 2. Create a lot for Tenant B
        app(TenantContext::class)->setTenant($this->tenantB);
        $lotB = ExpiryLot::create([
            'branch_id' => $this->branchB->id,
            'product_id' => $this->productB->id,
            'batch_code' => 'LOT-001',
            'quantity_received' => 80.0000,
            'quantity_remaining' => 80.0000,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
        app(TenantContext::class)->clear();

        // 3. Query as Tenant A: should only see lot A
        app(TenantContext::class)->setTenant($this->tenantA);
        $lots = ExpiryLot::all();
        $this->assertCount(1, $lots);
        $this->assertEquals($lotA->id, $lots->first()->id);
        $this->assertNull(ExpiryLot::find($lotB->id));
        app(TenantContext::class)->clear();

        // 4. Query as Tenant B: should only see lot B
        app(TenantContext::class)->setTenant($this->tenantB);
        $lots = ExpiryLot::all();
        $this->assertCount(1, $lots);
        $this->assertEquals($lotB->id, $lots->first()->id);
        $this->assertNull(ExpiryLot::find($lotA->id));
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_expiry_lot_composite_unique_constraint(): void
    {
        app(TenantContext::class)->setTenant($this->tenantA);

        ExpiryLot::create([
            'branch_id' => $this->branchA->id,
            'product_id' => $this->productA->id,
            'batch_code' => 'LOT-DUP',
            'quantity_received' => 50.0000,
            'quantity_remaining' => 50.0000,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->expectException(QueryException::class);

        // Attempting duplicate batch registration within same tenant, branch, product environment
        ExpiryLot::create([
            'branch_id' => $this->branchA->id,
            'product_id' => $this->productA->id,
            'batch_code' => 'LOT-DUP',
            'quantity_received' => 20.0000,
            'quantity_remaining' => 20.0000,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_expiry_lot_database_non_negative_checks(): void
    {
        app(TenantContext::class)->setTenant($this->tenantA);

        $this->expectException(QueryException::class);

        ExpiryLot::create([
            'branch_id' => $this->branchA->id,
            'product_id' => $this->productA->id,
            'batch_code' => 'LOT-NEG',
            'quantity_received' => -10.0000,
            'quantity_remaining' => 50.0000,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_expiry_lot_cascades_delete_on_product_removal(): void
    {
        app(TenantContext::class)->setTenant($this->tenantA);

        $lot = ExpiryLot::create([
            'branch_id' => $this->branchA->id,
            'product_id' => $this->productA->id,
            'batch_code' => 'LOT-005',
            'quantity_received' => 50.0000,
            'quantity_remaining' => 50.0000,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->assertDatabaseHas('expiry_lots', ['id' => $lot->id]);

        // When a product is deleted, all related expiry lots must be automatically cascade deleted
        $this->productA->delete();

        $this->assertDatabaseMissing('expiry_lots', ['id' => $lot->id]);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_expiry_lot_nullify_on_purchase_receiving_line_delete(): void
    {
        app(TenantContext::class)->setTenant($this->tenantA);

        $user = User::factory()->create(['tenant_id' => $this->tenantA->id]);

        $receiving = PurchaseReceiving::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA->id,
            'receiving_number' => 'RCV-001',
            'status' => 'draft',
            'received_by' => $user->id,
        ]);

        $receivingLine = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $receiving->id,
            'product_id' => $this->productA->id,
            'received_quantity' => 10.0000,
            'unit_cost' => 10.0000,
            'line_total' => 100.0000,
        ]);

        $lot = ExpiryLot::create([
            'branch_id' => $this->branchA->id,
            'product_id' => $this->productA->id,
            'purchase_receiving_line_id' => $receivingLine->id,
            'batch_code' => 'LOT-RCV',
            'quantity_received' => 10.0000,
            'quantity_remaining' => 10.0000,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->assertDatabaseHas('expiry_lots', [
            'id' => $lot->id,
            'purchase_receiving_line_id' => $receivingLine->id,
        ]);

        // When the receiving line is deleted, the lot remains intact but the foreign reference is set to null
        $receivingLine->delete();

        $this->assertDatabaseHas('expiry_lots', [
            'id' => $lot->id,
            'purchase_receiving_line_id' => null,
        ]);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_model_relationship_mappings(): void
    {
        app(TenantContext::class)->setTenant($this->tenantA);

        $lot = ExpiryLot::create([
            'branch_id' => $this->branchA->id,
            'product_id' => $this->productA->id,
            'batch_code' => 'LOT-RELATIONS',
            'quantity_received' => 12.0000,
            'quantity_remaining' => 12.0000,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        // Assert relationships on ExpiryLot instance
        $this->assertEquals($this->tenantA->id, $lot->tenant->id);
        $this->assertEquals($this->branchA->id, $lot->branch->id);
        $this->assertEquals($this->productA->id, $lot->product->id);

        // Assert reciprocal relationships
        $this->assertTrue($this->productA->expiryLots->contains('id', $lot->id));
        $this->assertTrue($this->branchA->expiryLots->contains('id', $lot->id));
        $this->assertTrue($this->tenantA->expiryLots->contains('id', $lot->id));

        app(TenantContext::class)->clear();
    }
}
