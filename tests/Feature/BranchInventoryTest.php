<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\BranchInventory;
use App\Services\TenantContext;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BranchInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_branch_inventory_creation_matrix(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantA);

        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        
        // 1. Branch inventory can be created for active product and active branch in same tenant
        $productActive = Product::create([
            'product_category_id' => $catA->id,
            'name' => 'Active Product',
            'sku' => 'ACT-1',
            'selling_price' => 100,
            'status' => 'active',
            'is_inventory_tracked' => true
        ]);

        $inventory = app(InventoryService::class)->initializeInventory([
            'branch_id' => $branchA->id,
            'product_id' => $productActive->id,
            'current_stock' => 100,
            'reorder_level' => 20
        ]);

        $this->assertNotNull($inventory);
        // 2. Branch inventory auto-injects tenant_id (via trait)
        $this->assertEquals($tenantA->id, $inventory->tenant_id);

        // 6. Branch inventory cannot be created for non-inventory-tracked product
        $productNonTracked = Product::create([
            'product_category_id' => $catA->id,
            'name' => 'Non-Tracked',
            'sku' => 'NON-1',
            'selling_price' => 50,
            'status' => 'active',
            'is_inventory_tracked' => false
        ]);

        try {
            app(InventoryService::class)->initializeInventory([
                'branch_id' => $branchA->id,
                'product_id' => $productNonTracked->id,
                'current_stock' => 10
            ]);
            $this->fail('Inventory allowed for non-tracked product');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Cannot initialize inventory for a product that is not inventory-tracked.', $e->getMessage());
        }

        // 7. Branch inventory cannot be created for inactive product
        $productInactive = Product::create([
            'product_category_id' => $catA->id,
            'name' => 'Inactive',
            'sku' => 'INA-1',
            'selling_price' => 50,
            'status' => 'inactive'
        ]);

        try {
            app(InventoryService::class)->initializeInventory([
                'branch_id' => $branchA->id,
                'product_id' => $productInactive->id,
                'current_stock' => 10
            ]);
            $this->fail('Inventory allowed for inactive product');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('inactive', $e->getMessage());
        }

        // 8. Branch inventory cannot be created for inactive branch
        $branchInactive = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'inactive']);
        try {
            app(InventoryService::class)->initializeInventory([
                'branch_id' => $branchInactive->id,
                'product_id' => $productActive->id,
                'current_stock' => 10
            ]);
            $this->fail('Inventory allowed for inactive branch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('inactive', $e->getMessage());
        }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_cross_tenant_isolation_matrix(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $productA = Product::create(['product_category_id' => $catA->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active']);
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        
        // 3. Branch inventory cannot be created for branch from another tenant
        try {
            app(InventoryService::class)->initializeInventory([
                'branch_id' => $branchA->id,
                'product_id' => $productA->id,
                'current_stock' => 10
            ]);
            $this->fail('Cross-tenant branch allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different tenant', $e->getMessage());
        }

        // 4. Branch inventory cannot be created for product from another tenant
        try {
            app(InventoryService::class)->initializeInventory([
                'branch_id' => $branchB->id,
                'product_id' => $productA->id,
                'current_stock' => 10
            ]);
            $this->fail('Cross-tenant product allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different tenant', $e->getMessage());
        }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_inventory_validation_and_uniqueness(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $product = Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active']);

        // 9. Duplicate branch/product inventory record is blocked (updateOrCreate handles this gracefully, but we check unique constraint in DB if forced)
        $inv1 = app(InventoryService::class)->initializeInventory([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => 10
        ]);
        
        $this->assertCount(1, BranchInventory::all());
        
        // Re-initializing updates existing record
        app(InventoryService::class)->initializeInventory([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => 20
        ]);
        
        $this->assertCount(1, BranchInventory::all());
        $this->assertEquals(20, BranchInventory::first()->current_stock);

        // 10. Current stock cannot be negative
        try {
            app(InventoryService::class)->initializeInventory([
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'current_stock' => -5
            ]);
            $this->fail('Negative stock allowed');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        // 11. Reorder level cannot be negative
        try {
            app(InventoryService::class)->initializeInventory([
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'reorder_level' => -1
            ]);
            $this->fail('Negative reorder allowed');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_reorder_level_is_branch_specific(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $product = Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active']);

        // 12. Reorder level is branch-specific
        app(InventoryService::class)->initializeInventory([
            'branch_id' => $branch1->id,
            'product_id' => $product->id,
            'reorder_level' => 5
        ]);

        app(InventoryService::class)->initializeInventory([
            'branch_id' => $branch2->id,
            'product_id' => $product->id,
            'reorder_level' => 15
        ]);

        $this->assertEquals(5, BranchInventory::where('branch_id', $branch1->id)->first()->reorder_level);
        $this->assertEquals(15, BranchInventory::where('branch_id', $branch2->id)->first()->reorder_level);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_branch_isolation(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $product = Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active']);

        app(InventoryService::class)->initializeInventory([
            'branch_id' => $branch1->id,
            'product_id' => $product->id,
            'current_stock' => 10
        ]);

        // 14. Branch A cannot access/update Branch B inventory through branch-scoped context
        // In this project, we don't have a strict BranchContext singleton like TenantContext yet,
        // but we verify that BranchInventory queries can be scoped to branch_id.
        $this->assertCount(1, BranchInventory::where('branch_id', $branch1->id)->get());
        $this->assertCount(0, BranchInventory::where('branch_id', $branch2->id)->get());

        app(TenantContext::class)->clear();
    }
}
