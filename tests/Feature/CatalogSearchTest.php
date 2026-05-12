<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\BranchInventory;
use App\Models\TaxCategory;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    /** @test */
    public function test_pos_search_scopes_and_filters(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $cat = ProductCategory::create(['name' => 'Hardware', 'code' => 'HW']);
        
        // 1. Active products
        // 3-5. Name, SKU, Barcode
        $p1 = Product::create([
            'product_category_id' => $cat->id,
            'name' => 'Hammer',
            'sku' => 'SKU-HAM',
            'barcode' => 'BAR-HAM',
            'status' => 'active',
            'selling_price' => 10
        ]);

        // 2. Exclude inactive products
        Product::create([
            'product_category_id' => $cat->id,
            'name' => 'Old Saw',
            'sku' => 'SKU-SAW',
            'status' => 'inactive',
            'selling_price' => 5
        ]);

        $service = app(CatalogService::class);

        // Search by name
        $results = $service->search('Hammer');
        $this->assertCount(1, $results);
        $this->assertEquals($p1->id, $results->first()['product_id']);

        // Search by SKU
        $results = $service->search('SKU-HAM');
        $this->assertCount(1, $results);

        // Search by Barcode
        $results = $service->search('BAR-HAM');
        $this->assertCount(1, $results);

        // 6. Support Category
        $results = $service->search('', $cat->id);
        $this->assertCount(1, $results);
        $this->assertEquals($p1->id, $results->first()['product_id']);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_pos_search_isolation(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $catA = ProductCategory::create(['name' => 'Cat A', 'code' => 'A']);
        Product::create(['product_category_id' => $catA->id, 'name' => 'Prod A', 'sku' => 'S1', 'selling_price' => 10]);
        app(TenantContext::class)->clear();

        // 7. Tenant A cannot see Tenant B products
        app(TenantContext::class)->setTenant($tenantB);
        $results = app(CatalogService::class)->search('Prod A');
        $this->assertCount(0, $results);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_pos_search_branch_stock_awareness(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'C1']);
        
        // Tracked product
        $pTracked = Product::create([
            'product_category_id' => $cat->id,
            'name' => 'Tracked',
            'sku' => 'T1',
            'is_inventory_tracked' => true,
            'selling_price' => 10,
            'status' => 'active'
        ]);

        // Branch A inventory
        BranchInventory::create([
            'branch_id' => $branchA->id,
            'product_id' => $pTracked->id,
            'current_stock' => 50,
            'status' => 'active'
        ]);

        // 8. Branch A sees branch stock
        app(BranchContext::class)->setBranch($branchA);
        $results = app(CatalogService::class)->search('Tracked');
        $this->assertCount(1, $results);
        $this->assertEquals(50, $results->first()['current_stock']);
        $this->assertTrue($results->first()['stock_available']);

        // 9. Branch A does not see Branch B stock
        // If we switch to Branch B, it should have null stock (as no record exists)
        app(BranchContext::class)->setBranch($branchB);
        $results = app(CatalogService::class)->search('Tracked');
        $this->assertNull($results->first()['current_stock']);
        $this->assertFalse($results->first()['stock_available']);

        // 11. Tracked product without branch inventory does not create inventory automatically
        $this->assertDatabaseCount('branch_inventories', 1);

        app(BranchContext::class)->clear();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_pos_search_non_tracked_products(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $cat = ProductCategory::create(['name' => 'Services', 'code' => 'SRV']);
        
        // 10. Non-inventory-tracked products appear without requiring branch inventory
        Product::create([
            'product_category_id' => $cat->id,
            'name' => 'Delivery Service',
            'sku' => 'SRV-DEL',
            'is_inventory_tracked' => false,
            'selling_price' => 5,
            'status' => 'active'
        ]);

        $results = app(CatalogService::class)->search('Delivery Service');
        $this->assertCount(1, $results);
        $payload = $results->first();

        // Behavior confirmation for non-tracked
        $this->assertNull($payload['current_stock']);
        $this->assertEquals('not_tracked', $payload['stock_tracking']);
        $this->assertTrue($payload['stock_available']);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_pos_payload_data_integrity(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'C1']);
        $tax = TaxCategory::create([
            'code' => 'VAT12',
            'name' => 'VAT 12%',
            'tax_type' => 'vatable',
            'rate' => 12,
            'status' => 'active'
        ]);

        $product = Product::create([
            'product_category_id' => $cat->id,
            'tax_category_id' => $tax->id,
            'name' => 'Full Payload',
            'sku' => 'SKU-FULL',
            'barcode' => 'BAR-FULL',
            'unit_of_measure' => 'piece',
            'selling_price' => 1500.50,
            'cost_price' => 800.00, // 13. Excluded
            'is_inventory_tracked' => true,
            'is_discountable' => true,
            'status' => 'active'
        ]);

        $payload = app(CatalogService::class)->search('Full Payload')->first();

        // 12. Required fields
        $this->assertEquals($product->id, $payload['product_id']);
        $this->assertEquals('Full Payload', $payload['display_name']);
        $this->assertEquals('SKU-FULL', $payload['sku']);
        $this->assertEquals('BAR-FULL', $payload['barcode']);
        $this->assertEquals('piece', $payload['unit_of_measure']);
        $this->assertEquals(1500.50, $payload['selling_price']);
        $this->assertEquals($tax->id, $payload['tax_category_id']);
        $this->assertEquals('vatable', $payload['tax_type']);
        $this->assertEquals(12.0, $payload['tax_rate']);
        $this->assertTrue($payload['is_inventory_tracked']);
        $this->assertTrue($payload['is_discountable']);
        $this->assertEquals('active', $payload['status']);

        // 13-14. Exclusions
        $this->assertArrayNotHasKey('cost_price', $payload);
        $this->assertArrayNotHasKey('quickbooks_id', $payload);
        $this->assertArrayNotHasKey('sync_status', $payload);
        $this->assertArrayNotHasKey('audit_metadata', $payload);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_search_requires_context(): void
    {
        // 15. Search requires active TenantContext
        app(TenantContext::class)->clear();
        
        try {
            app(CatalogService::class)->search('Test');
            $this->fail('Search allowed without TenantContext');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Cannot search catalog without active TenantContext.', $e->getMessage());
        }
    }
}
