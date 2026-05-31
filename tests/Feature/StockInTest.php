<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    /** @test */
    public function test_stock_in_full_validation_matrix(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $product = Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active', 'is_inventory_tracked' => true]);
        
        $inventory = app(InventoryService::class)->initializeInventory([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => 10
        ]);

        // 1. Stock-in increases current stock
        // 2. Creates inventory movement log with stock_in
        // 3. Records correct quantity_before, change, after
        // 10. Stores optional supplier reference
        // 11. Stores optional invoice reference
        // 12. Stores optional remarks
        $movement = app(InventoryService::class)->stockIn($inventory, 25, 'SUPPLIER-A', 'INV-001', 'First delivery');

        $inventory->refresh();
        $this->assertEquals(35, $inventory->current_stock);
        $this->assertEquals('stock_in', $movement->movement_type);
        $this->assertEquals('stock_in', $movement->source_type); // Approved terminology
        $this->assertEquals('delivery_received', $movement->reason_code); // Approved terminology
        $this->assertEquals(25, $movement->quantity_change);
        $this->assertEquals(10, $movement->quantity_before);
        $this->assertEquals(35, $movement->quantity_after);
        $this->assertEquals('SUPPLIER-A', $movement->source_id);
        $this->assertEquals('INV-001', $movement->reference_number);
        $this->assertEquals('First delivery', $movement->remarks);

        // 7, 8, 9. Quantity validation
        try {
            app(InventoryService::class)->stockIn($inventory, 0);
            $this->fail('Zero quantity allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('positive number', $e->getMessage());
        }

        try {
            app(InventoryService::class)->stockIn($inventory, -5);
            $this->fail('Negative quantity allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('positive number', $e->getMessage());
        }

        // 15. Movement log remains immutable after stock-in
        try {
            $movement->update(['quantity_after' => 999]);
            $this->fail('Movement log allowed update');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_stock_in_isolation_and_permissions(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $branchA2 = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $productA = Product::create(['product_category_id' => $catA->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active', 'is_inventory_tracked' => true]);
        $inventoryA = app(InventoryService::class)->initializeInventory(['branch_id' => $branchA->id, 'product_id' => $productA->id, 'current_stock' => 10]);

        // 4. Stock-in requires active TenantContext
        app(TenantContext::class)->clear();
        try {
            app(InventoryService::class)->stockIn($inventoryA, 10);
            $this->fail('Allowed without TenantContext');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('without active TenantContext', $e->getMessage());
        }

        // 5. Stock-in cannot target inventory from another tenant
        app(TenantContext::class)->setTenant($tenantB);
        try {
            app(InventoryService::class)->stockIn($inventoryA, 10);
            $this->fail('Cross-tenant stock-in allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different tenant', $e->getMessage());
        }
        app(TenantContext::class)->clear();

        // 6. Stock-in cannot target inventory from another branch when BranchContext is active
        app(TenantContext::class)->setTenant($tenantA);
        app(BranchContext::class)->setBranch($branchA2);
        try {
            app(InventoryService::class)->stockIn($inventoryA, 10);
            $this->fail('Allowed stock-in outside active branch context');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('outside the active branch context', $e->getMessage());
        }
        app(BranchContext::class)->clear();

        // 13, 14. Permission check
        $user = User::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active', 'actor_type' => 'tenant_user']);
        $this->actingAs($user);
        try {
            app(InventoryService::class)->stockIn($inventoryA, 10);
            $this->fail('Allowed without permission');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not have permission', $e->getMessage());
        }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_stock_in_atomicity(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $product = Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active', 'is_inventory_tracked' => true]);
        $inventory = app(InventoryService::class)->initializeInventory(['branch_id' => $branch->id, 'product_id' => $product->id, 'current_stock' => 10]);

        // 16. If movement creation fails, stock update must not persist
        $service = \Mockery::mock(\App\Services\InventoryService::class, [
            app(\App\Services\AuditLogger::class),
            app(TenantContext::class),
            app(BranchContext::class),
            app(\App\Services\Inventory\UnitConversionResolver::class),
        ])->makePartial();
        $service->shouldReceive('recordMovement')->andThrow(new \RuntimeException('Forced failure'));

        try {
            $service->stockIn($inventory, 50);
            $this->fail('Stock-in should have failed');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Forced failure', $e->getMessage());
        }

        // Verify stock rollback
        $this->assertEquals(10, $inventory->refresh()->current_stock);

        app(TenantContext::class)->clear();
    }
}
