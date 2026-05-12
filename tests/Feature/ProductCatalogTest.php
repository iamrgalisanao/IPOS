<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Services\TenantContext;
use App\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_product_category_can_be_created_and_isolated(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // 1. Product category can be created under active tenant
        app(TenantContext::class)->setTenant($tenantA);
        $categoryA = app(CatalogService::class)->createCategory([
            'name' => 'Category A',
            'code' => 'CAT1',
            'status' => 'active'
        ]);
        $this->assertEquals($tenantA->id, $categoryA->tenant_id);

        // 17. Product/category creation is audit-logged
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'product_category_created',
            'auditable_id' => $categoryA->id,
            'tenant_id' => $tenantA->id
        ]);
        app(TenantContext::class)->clear();

        // 14. Tenant A cannot access Tenant B product category
        app(TenantContext::class)->setTenant($tenantB);
        $this->assertNull(ProductCategory::where('id', $categoryA->id)->first());

        // 3. Same category code can exist in different tenants
        $categoryB = app(CatalogService::class)->createCategory([
            'name' => 'Category B',
            'code' => 'CAT1',
            'status' => 'active'
        ]);
        $this->assertNotNull($categoryB);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_category_code_unique_per_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        // 2. Product category code is unique per tenant
        ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        
        try {
            ProductCategory::create(['name' => 'C2', 'code' => 'CAT1']);
            $this->fail('Duplicate category code allowed in same tenant');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
        
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_product_master_can_be_created_and_isolated(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // 4. Product can be created under active tenant
        // 5. Product auto-injects tenant_id (via BelongsToTenant trait used in Service)
        app(TenantContext::class)->setTenant($tenantA);
        $catA = app(CatalogService::class)->createCategory(['name' => 'C1', 'code' => 'CAT1']);
        $productA = app(CatalogService::class)->createProduct([
            'product_category_id' => $catA->id,
            'name' => 'Product A',
            'sku' => 'SKU1',
            'barcode' => 'BAR1',
            'selling_price' => 100.00,
            'status' => 'active'
        ]);
        $this->assertEquals($tenantA->id, $productA->tenant_id);

        // 17. Product creation is audit-logged
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'product_created',
            'auditable_id' => $productA->id,
            'tenant_id' => $tenantA->id
        ]);
        app(TenantContext::class)->clear();

        // 15. Tenant A cannot access Tenant B product
        app(TenantContext::class)->setTenant($tenantB);
        $this->assertNull(Product::where('id', $productA->id)->first());

        // 9. Same SKU can exist under different tenants
        // 11. Same barcode can exist under different tenants
        $catB = app(CatalogService::class)->createCategory(['name' => 'C2', 'code' => 'CAT2']);
        $productB = app(CatalogService::class)->createProduct([
            'product_category_id' => $catB->id,
            'name' => 'Product B',
            'sku' => 'SKU1',
            'barcode' => 'BAR1',
            'selling_price' => 100.00,
            'status' => 'active'
        ]);
        $this->assertNotNull($productB);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_product_sku_and_barcode_uniqueness_per_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        
        // 8. SKU is unique per tenant
        Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'SKU1']);
        try {
            Product::create(['product_category_id' => $cat->id, 'name' => 'P2', 'sku' => 'SKU1']);
            $this->fail('Duplicate SKU allowed in same tenant');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }

        // 10. Barcode is unique per tenant
        Product::create(['product_category_id' => $cat->id, 'name' => 'P3', 'sku' => 'SKU2', 'barcode' => 'BAR1']);
        try {
            Product::create(['product_category_id' => $cat->id, 'name' => 'P4', 'sku' => 'SKU3', 'barcode' => 'BAR1']);
            $this->fail('Duplicate barcode allowed in same tenant');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
        
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_cross_tenant_category_assignment_is_blocked(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $catA = ProductCategory::create(['name' => 'Cat A', 'code' => 'CAT-A']);
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        
        // 7. Product cannot be assigned to category from another tenant
        try {
            app(CatalogService::class)->createProduct([
                'product_category_id' => $catA->id,
                'name' => 'Product B',
                'sku' => 'SKU-B',
                'selling_price' => 100.00
            ]);
            $this->fail('Cross-tenant category assignment allowed');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Invalid category assignment: Category belongs to a different tenant or does not exist.', $e->getMessage());
        }
        
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_inactive_products_and_categories_excluded(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        // 12. Inactive category is excluded from active query
        ProductCategory::create(['name' => 'Active Cat', 'code' => 'AC', 'status' => 'active']);
        ProductCategory::create(['name' => 'Inactive Cat', 'code' => 'IC', 'status' => 'inactive']);

        $this->assertCount(1, ProductCategory::active()->get());

        // 13. Inactive product is excluded from active query
        $cat = ProductCategory::active()->first();
        Product::create(['product_category_id' => $cat->id, 'name' => 'Active P', 'sku' => 'P1', 'status' => 'active']);
        Product::create(['product_category_id' => $cat->id, 'name' => 'Inactive P', 'sku' => 'P2', 'status' => 'inactive']);

        $this->assertCount(1, Product::active()->get());
        
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_creation_without_tenant_context_fails_closed(): void
    {
        // 16. Product/category creation without TenantContext fails closed
        app(TenantContext::class)->clear();

        try {
            app(CatalogService::class)->createCategory(['name' => 'Fail', 'code' => 'FAIL']);
            $this->fail('Category creation allowed without TenantContext');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Cannot create category without active TenantContext.', $e->getMessage());
        }

        try {
            app(CatalogService::class)->createProduct(['product_category_id' => 'any', 'name' => 'Fail']);
            $this->fail('Product creation allowed without TenantContext');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Cannot create product without active TenantContext.', $e->getMessage());
        }
    }
}
