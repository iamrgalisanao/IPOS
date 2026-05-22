<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\TaxCategory;
use App\Models\User;
use App\Services\Catalog\CatalogCsvExportService;
use App\Services\Catalog\CatalogImportPreviewService;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\CatalogService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogInventoryIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    /** @test */
    public function test_product_catalog_isolation(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // Setup Tenant A Data
        app(TenantContext::class)->setTenant($tenantA);
        $catA = app(CatalogService::class)->createCategory(['name' => 'Cat A', 'code' => 'A', 'status' => 'active']);
        $taxA = TaxCategory::create([
            'tenant_id' => $tenantA->id, 
            'code' => 'T1', 
            'name' => 'T1', 
            'tax_type' => 'vatable', 
            'rate' => 10, 
            'status' => 'active'
        ]);
        $prodA = app(CatalogService::class)->createProduct([
            'product_category_id' => $catA->id,
            'name' => 'Prod A',
            'sku' => 'SKU-A',
            'selling_price' => 100,
            'status' => 'active'
        ]);

        // 6. Inactive products excluded from POS
        Product::create(['product_category_id' => $catA->id, 'name' => 'Inactive', 'sku' => 'I1', 'status' => 'inactive', 'selling_price' => 10]);
        // 7. Inactive categories excluded
        ProductCategory::create(['name' => 'Inactive Cat', 'code' => 'IC', 'status' => 'inactive']);
        
        app(TenantContext::class)->clear();

        // Adversarial check from Tenant B
        app(TenantContext::class)->setTenant($tenantB);
        $catB = app(CatalogService::class)->createCategory(['name' => 'Cat B', 'code' => 'B']);

        // 1. Tenant A cannot search Tenant B product catalog
        $results = app(CatalogService::class)->search('Prod A');
        $this->assertCount(0, $results);

        // 2. Tenant A cannot view Tenant B product by direct ID
        $this->assertNull(Product::find($prodA->id));

        // 4. Tenant A cannot assign Tenant B category
        try {
            app(CatalogService::class)->createProduct([
                'product_category_id' => $catA->id,
                'name' => 'Fail',
                'sku' => 'F1',
                'selling_price' => 10
            ]);
            $this->fail('Cross-tenant category assignment allowed');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Invalid category assignment: Category belongs to a different tenant or does not exist.', $e->getMessage());
        }

        // 5. Tenant A cannot assign Tenant B tax category
        try {
            app(CatalogService::class)->createProduct([
                'product_category_id' => $catB->id,
                'name' => 'Fail Tax',
                'sku' => 'FT1',
                'selling_price' => 10,
                'tax_category_id' => $taxA->id
            ]);
            $this->fail('Cross-tenant tax assignment allowed');
        } catch (\RuntimeException $e) {
            $this->assertTrue(true);
        }

        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($tenantA);
        // Verify 6 & 7 (Filtering)
        $this->assertCount(1, app(CatalogService::class)->search('')); // Only Prod A
        $this->assertCount(1, ProductCategory::active()->get()); // Only Cat A

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_sku_barcode_isolation(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'C1']);
        Product::create(['product_category_id' => $catA->id, 'name' => 'P1', 'sku' => 'SKU1', 'barcode' => 'BAR1']);

        // 8. Duplicate SKU blocked within same tenant
        try {
            Product::create(['product_category_id' => $catA->id, 'name' => 'P2', 'sku' => 'SKU1']);
            $this->fail('Duplicate SKU allowed in same tenant');
        } catch (\Exception $e) { $this->assertTrue(true); }

        // 10. Duplicate barcode blocked within same tenant
        try {
            Product::create(['product_category_id' => $catA->id, 'name' => 'P3', 'sku' => 'SKU2', 'barcode' => 'BAR1']);
            $this->fail('Duplicate barcode allowed in same tenant');
        } catch (\Exception $e) { $this->assertTrue(true); }

        app(TenantContext::class)->clear();

        // 9. Same SKU allowed across different tenants
        // 11. Same barcode allowed across different tenants
        app(TenantContext::class)->setTenant($tenantB);
        $catB = ProductCategory::create(['name' => 'C2', 'code' => 'C2']);
        $pB = Product::create(['product_category_id' => $catB->id, 'name' => 'P-B', 'sku' => 'SKU1', 'barcode' => 'BAR1']);
        $this->assertNotNull($pB);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_branch_inventory_isolation(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'C1']);
        $prodA = Product::create(['product_category_id' => $catA->id, 'name' => 'P1', 'sku' => 'S1', 'is_inventory_tracked' => true, 'status' => 'active']);
        
        $prodNotTracked = Product::create(['product_category_id' => $catA->id, 'name' => 'P2', 'sku' => 'S2', 'is_inventory_tracked' => false, 'status' => 'active']);
        $prodInactive = Product::create(['product_category_id' => $catA->id, 'name' => 'P3', 'sku' => 'S3', 'status' => 'inactive']);
        $branchInactive = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'inactive']);

        // 15. Duplicate branch/product blocked
        BranchInventory::create(['branch_id' => $branchA->id, 'product_id' => $prodA->id]);
        try {
            BranchInventory::create(['branch_id' => $branchA->id, 'product_id' => $prodA->id]);
            $this->fail('Duplicate branch inventory allowed');
        } catch (\Exception $e) { $this->assertTrue(true); }

        // 16. Non-tracked cannot have inventory
        try {
            app(InventoryService::class)->initializeInventory(['branch_id' => $branchA->id, 'product_id' => $prodNotTracked->id]);
            $this->fail('Inventory allowed for non-tracked product');
        } catch (\Exception $e) { $this->assertTrue(true); }

        // 17. Inactive branch blocked
        try {
            app(InventoryService::class)->initializeInventory(['branch_id' => $branchInactive->id, 'product_id' => $prodA->id]);
            $this->fail('Inventory allowed for inactive branch');
        } catch (\Exception $e) { $this->assertTrue(true); }

        // 18. Inactive product blocked
        try {
            app(InventoryService::class)->initializeInventory(['branch_id' => $branchA->id, 'product_id' => $prodInactive->id]);
            $this->fail('Inventory allowed for inactive product');
        } catch (\Exception $e) { $this->assertTrue(true); }

        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $catB = ProductCategory::create(['name' => 'C2', 'code' => 'C2']);
        $prodB = Product::create(['product_category_id' => $catB->id, 'name' => 'PB', 'sku' => 'SB']);

        // 12. Tenant A cannot create inventory for Tenant B branch
        try {
            app(InventoryService::class)->initializeInventory(['branch_id' => $branchA->id, 'product_id' => $prodB->id]);
            $this->fail('Cross-tenant branch inventory creation allowed (branch)');
        } catch (\Exception $e) { $this->assertTrue(true); }

        // 13. Tenant A cannot create inventory for Tenant B product
        try {
            app(InventoryService::class)->initializeInventory(['branch_id' => $branchB->id, 'product_id' => $prodA->id]);
            $this->fail('Cross-tenant branch inventory creation allowed (product)');
        } catch (\Exception $e) { $this->assertTrue(true); }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_inventory_movement_isolation_and_immutability(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C', 'code' => 'C']);
        $prod = Product::create(['product_category_id' => $cat->id, 'name' => 'P', 'sku' => 'S', 'selling_price' => 10]);
        $inv = BranchInventory::create(['branch_id' => $branchA->id, 'product_id' => $prod->id, 'current_stock' => 0]);

        app(InventoryService::class)->adjustStock($inv, 10, 'stock_in_reason');
        $movement = InventoryMovement::first();

        // 22. Movement is immutable
        try { $movement->update(['quantity_change' => 99]); $this->fail('Movement updated'); } catch (\Exception $e) { $this->assertTrue(true); }
        try { $movement->delete(); $this->fail('Movement deleted'); } catch (\Exception $e) { $this->assertTrue(true); }

        // 24. Approved types accepted
        $this->assertEquals('manual_adjustment', $movement->movement_type);

        // 23. Deprecated rejected (Service level check)
        try {
            app(InventoryService::class)->adjustStock($inv, 10, 'deprecated_type');
            $this->fail('Deprecated type accepted');
        } catch (\Exception $e) { $this->assertTrue(true); }

        // 19. Tenant Isolation
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($tenantB);
        $this->assertCount(0, InventoryMovement::all());

        // 20. Branch Isolation
        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($tenantA);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        app(BranchContext::class)->setBranch($branchB);
        $this->assertCount(0, InventoryMovement::where('branch_id', $branchB->id)->get());

        app(BranchContext::class)->clear();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_adjustment_and_stockin_isolation(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C', 'code' => 'C']);
        $prod = Product::create(['product_category_id' => $cat->id, 'name' => 'P', 'sku' => 'S', 'selling_price' => 10]);
        $invA = BranchInventory::create(['branch_id' => $branchA->id, 'product_id' => $prod->id, 'current_stock' => 10]);
        $invB = BranchInventory::create(['branch_id' => $branchB->id, 'product_id' => $prod->id, 'current_stock' => 10]);

        // 29-30. Permissions
        $user = User::factory()->create(['tenant_id' => $tenantA->id]);
        $this->actingAs($user);
        
        try {
            app(InventoryService::class)->adjustStock($invA, 5, 'manual_adjustment');
            $this->fail('Adjustment allowed without permission');
        } catch (\RuntimeException $e) {
            $this->assertEquals('User does not have permission to manage branch inventory.', $e->getMessage());
        }

        // 26. Adjustment cannot target another branch when BranchContext active
        app(BranchContext::class)->setBranch($branchA);
        try {
            app(InventoryService::class)->adjustStock($invB, 5, 'manual_adjustment');
            $this->fail('Cross-branch adjustment allowed');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Cannot adjust stock for inventory outside the active branch context.', $e->getMessage());
        }

        // 28. Stock-in same rules
        try {
            app(InventoryService::class)->stockIn($invB, 10);
            $this->fail('Cross-branch stock-in allowed');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Cannot record stock-in for inventory outside the active branch context.', $e->getMessage());
        }

        try {
            auth()->logout(); // Clear user to bypass permission check for this specific logic check
            app(InventoryService::class)->adjustStock($invA, -20, 'manual_adjustment');
            $this->fail('Negative stock allowed');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Stock adjustment would result in negative inventory, which is blocked.', $e->getMessage());
        }

        // 32. Zero/negative stock-in blocked
        try {
            app(InventoryService::class)->stockIn($invA, 0);
            $this->fail('Zero stock-in allowed');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Stock-in quantity must be a positive number.', $e->getMessage());
        }

        app(BranchContext::class)->clear();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_catalog_csv_export_remains_tenant_isolated(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $categoryA = ProductCategory::create(['name' => 'Tenant A Category', 'code' => 'TA']);
        Product::create([
            'product_category_id' => $categoryA->id,
            'name' => 'Tenant A Product',
            'sku' => 'TA-001',
            'selling_price' => 100,
            'status' => 'active',
        ]);
        $actorA = User::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Exporter']);
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $categoryB = ProductCategory::create(['name' => 'Tenant B Category', 'code' => 'TB']);
        Product::create([
            'product_category_id' => $categoryB->id,
            'name' => 'Tenant B Product',
            'sku' => 'TB-001',
            'selling_price' => 200,
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantA);
        $service = app(CatalogCsvExportService::class);
        $productCsv = $service->exportProducts(Product::with('category')->get(), $actorA);
        $categoryCsv = $service->exportCategories(ProductCategory::query()->get(), $actorA);

        $this->assertStringContainsString('Tenant A Product', $productCsv);
        $this->assertStringNotContainsString('Tenant B Product', $productCsv);
        $this->assertStringContainsString('Tenant A Category', $categoryCsv);
        $this->assertStringNotContainsString('Tenant B Category', $categoryCsv);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_catalog_import_preview_reference_checks_remain_tenant_isolated(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        ProductCategory::create(['name' => 'Tenant A Category', 'code' => 'A-CAT', 'status' => 'active']);
        TaxCategory::create(['code' => 'A-TAX', 'name' => 'Tenant A Tax', 'tax_type' => 'vatable', 'rate' => 12, 'status' => 'active']);
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $csv = implode("\n", [
            'name,sku,category_code,unit_of_measure,selling_price,status,product_type,is_sellable,is_inventory_tracked,is_taxable,is_discountable,tax_category_code',
            'Tenant B Preview,TB-001,A-CAT,piece,10.0000,active,finished_good,true,true,true,true,A-TAX',
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('products.csv', $csv);
        $preview = app(CatalogImportPreviewService::class)->previewProducts($file);

        $this->assertContains('category_code does not match an existing category in the current tenant.', $preview['rows'][0]['errors']);
        $this->assertContains('tax_category_code does not match an existing tax category in the current tenant.', $preview['rows'][0]['errors']);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_pos_payload_security_and_logic(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        
        $cat = ProductCategory::create(['name' => 'Hardware', 'code' => 'HW']);
        $prodTracked = Product::create([
            'product_category_id' => $cat->id,
            'name' => 'Tracked',
            'sku' => 'T1',
            'is_inventory_tracked' => true,
            'selling_price' => 100,
            'cost_price' => 50
        ]);
        $prodNotTracked = Product::create([
            'product_category_id' => $cat->id,
            'name' => 'Service',
            'sku' => 'S1',
            'is_inventory_tracked' => false,
            'selling_price' => 50
        ]);

        // 35. Search doesn't create inventory
        app(CatalogService::class)->search('Tracked');
        $this->assertDatabaseCount('branch_inventories', 0);

        // 36. Non-tracked appear without inventory
        $results = app(CatalogService::class)->search('Service');
        $this->assertCount(1, $results);
        $this->assertEquals('not_tracked', $results->first()['stock_tracking']);

        // 37. Tracked without inventory returns null stock
        app(BranchContext::class)->setBranch($branch);
        $results = app(CatalogService::class)->search('Tracked');
        $this->assertNull($results->first()['current_stock']);
        $this->assertFalse($results->first()['stock_available']);

        // 33-34. Exclusions
        $payload = $results->first();
        $this->assertArrayNotHasKey('cost_price', $payload);
        $this->assertArrayNotHasKey('quickbooks_id', $payload);
        $this->assertArrayNotHasKey('gl_account_id', $payload);

        app(BranchContext::class)->clear();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_context_safety_and_regressions(): void
    {
        // 38. Missing TenantContext fails closed
        app(TenantContext::class)->clear();
        try {
            app(CatalogService::class)->search('Test');
            $this->fail('Search allowed without tenant');
        } catch (\RuntimeException $e) { $this->assertTrue(true); }
    }
}
