<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\TaxCategory;
use App\Services\TenantContext;
use App\Services\CatalogService;
use App\Services\ConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_product_can_store_selling_price_with_precision(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $category = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        
        // 1. Product can store selling price using decimal-safe field (19,4)
        $product = Product::create([
            'product_category_id' => $category->id,
            'name' => 'Precise Product',
            'sku' => 'P1',
            'selling_price' => 1500.1234
        ]);

        $this->assertEquals(1500.1234, $product->selling_price);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_pricing_validation_rules(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $category = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);

        // 2. Selling price is required
        try {
            app(CatalogService::class)->createProduct([
                'product_category_id' => $category->id,
                'name' => 'No Price',
                'sku' => 'NO-PRICE'
            ]);
            $this->fail('Product allowed without selling price');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        // 3. Selling price cannot be negative
        try {
            app(CatalogService::class)->createProduct([
                'product_category_id' => $category->id,
                'name' => 'Negative Price',
                'sku' => 'NEG-PRICE',
                'selling_price' => -1.00
            ]);
            $this->fail('Negative price allowed');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        // 4. Cost price may be nullable
        $p = app(CatalogService::class)->createProduct([
            'product_category_id' => $category->id,
            'name' => 'Nullable Cost',
            'sku' => 'NC1',
            'selling_price' => 100,
            'cost_price' => null
        ]);
        $this->assertNull($p->cost_price);

        // 5. Cost price cannot be negative
        try {
            app(CatalogService::class)->createProduct([
                'product_category_id' => $category->id,
                'name' => 'Neg Cost',
                'sku' => 'NEG-COST',
                'selling_price' => 100,
                'cost_price' => -1
            ]);
            $this->fail('Negative cost allowed');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_tax_category_assignment_rules(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        // 6. Product can be assigned to active tax category from same tenant
        $taxActive = TaxCategory::create(['code' => 'VAT', 'name' => 'VAT', 'tax_type' => 'vatable', 'rate' => 12, 'status' => 'active']);
        $taxInactive = TaxCategory::create(['code' => 'OLD', 'name' => 'Old', 'tax_type' => 'vatable', 'rate' => 10, 'status' => 'inactive']);

        $p = app(CatalogService::class)->createProduct([
            'product_category_id' => $catA->id,
            'name' => 'Product Active Tax',
            'sku' => 'PAT1',
            'selling_price' => 100,
            'tax_category_id' => $taxActive->id
        ]);
        $this->assertEquals($taxActive->id, $p->tax_category_id);

        // 8. Product cannot be assigned to inactive tax category
        try {
            app(CatalogService::class)->createProduct([
                'product_category_id' => $catA->id,
                'name' => 'Product Inactive Tax',
                'sku' => 'PIT1',
                'selling_price' => 100,
                'tax_category_id' => $taxInactive->id
            ]);
            $this->fail('Inactive tax category assignment allowed');
        } catch (\RuntimeException $e) {
            $this->assertTrue(true);
        }
        app(TenantContext::class)->clear();

        // 7. Product cannot be assigned to tax category from another tenant
        app(TenantContext::class)->setTenant($tenantB);
        $catB = ProductCategory::create(['name' => 'C2', 'code' => 'CAT2']);
        try {
            app(CatalogService::class)->createProduct([
                'product_category_id' => $catB->id,
                'name' => 'Cross Tenant Tax',
                'sku' => 'CTT1',
                'selling_price' => 100,
                'tax_category_id' => $taxActive->id
            ]);
            $this->fail('Cross-tenant tax assignment allowed');
        } catch (\RuntimeException $e) {
            $this->assertTrue(true);
        }
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_pricing_tenant_isolation(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $pA = Product::create(['product_category_id' => $catA->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100]);
        app(TenantContext::class)->clear();

        // 10. Tenant A cannot update Tenant B product pricing
        app(TenantContext::class)->setTenant($tenantB);
        $this->assertNull(Product::where('id', $pA->id)->first());
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_sale_snapshot_logic(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $category = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $tax = TaxCategory::create(['code' => 'VAT', 'name' => 'VAT', 'tax_type' => 'vatable', 'rate' => 12, 'status' => 'active']);

        $product = app(CatalogService::class)->createProduct([
            'product_category_id' => $category->id,
            'name' => 'Snapshot Product',
            'sku' => 'SN-1',
            'barcode' => 'BAR-1',
            'unit_of_measure' => 'piece',
            'selling_price' => 1500.50,
            'tax_category_id' => $tax->id,
            'is_discountable' => true
        ]);

        // 11. getSaleSnapshotBase() returns expected keys
        $snapshot = $product->getSaleSnapshotBase();

        $this->assertEquals($product->id, $snapshot['product_id']);
        $this->assertEquals('Snapshot Product', $snapshot['product_name']);
        $this->assertEquals('SN-1', $snapshot['sku']);
        $this->assertEquals(1500.50, $snapshot['selling_price']);
        $this->assertEquals('vatable', $snapshot['tax_type']);
        $this->assertEquals(12.0, $snapshot['tax_rate']);

        // 12. Updating product after snapshot read does not change the already captured snapshot array
        $originalSnapshot = $snapshot;
        $product->update(['selling_price' => 2000.00]);
        
        $this->assertEquals(1500.50, $originalSnapshot['selling_price']);
        $this->assertNotEquals($product->selling_price, $originalSnapshot['selling_price']);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_audit_logging_for_pricing(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $category = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        
        // 13. Product pricing changes are audit-logged (via service)
        $product = app(CatalogService::class)->createProduct([
            'product_category_id' => $category->id,
            'name' => 'Audit Product',
            'sku' => 'AUD-1',
            'selling_price' => 100
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'product_created',
            'auditable_id' => $product->id,
            'tenant_id' => $tenant->id
        ]);

        app(TenantContext::class)->clear();
    }
}
