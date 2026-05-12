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

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    /** @test */
    public function test_manual_adjustment_full_validation_matrix(): void
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

        // 1. Manual adjustment can increase stock
        // 3. Updates current_stock
        // 4. Creates movement log with manual_adjustment
        // 5. Records correct quantity_before, change, after
        // 10. Stores optional remarks
        $movement = app(InventoryService::class)->adjustStock($inventory, 5, 'ADJ_IN', 'Adding samples');

        $inventory->refresh();
        $this->assertEquals(15, $inventory->current_stock);
        $this->assertEquals('manual_adjustment', $movement->movement_type);
        $this->assertEquals(5, $movement->quantity_change);
        $this->assertEquals(10, $movement->quantity_before);
        $this->assertEquals(15, $movement->quantity_after);
        $this->assertEquals('Adding samples', $movement->remarks);

        // 2. Manual adjustment can decrease stock
        app(InventoryService::class)->adjustStock($inventory, -3, 'ADJ_OUT');
        $this->assertEquals(12, $inventory->refresh()->current_stock);

        // 9. Manual adjustment requires reason code
        try {
            app(InventoryService::class)->adjustStock($inventory, 1, '');
            $this->fail('Adjustment allowed without reason code');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('requires a valid reason code', $e->getMessage());
        }

        // 11. Manual adjustment blocks negative resulting stock
        try {
            app(InventoryService::class)->adjustStock($inventory, -20, 'ERR');
            $this->fail('Negative stock allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('negative inventory', $e->getMessage());
        }

        // 14. Movement log remains immutable after adjustment
        try {
            $movement->update(['quantity_after' => 999]);
            $this->fail('Movement log allowed update');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_adjustment_atomicity(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $product = Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active', 'is_inventory_tracked' => true]);
        $inventory = app(InventoryService::class)->initializeInventory(['branch_id' => $branch->id, 'product_id' => $product->id, 'current_stock' => 10]);

        // We will mock recordMovement to throw an exception and verify the stock update rolls back
        $service = \Mockery::mock(\App\Services\InventoryService::class, [app(\App\Services\AuditLogger::class), app(TenantContext::class), app(BranchContext::class)])->makePartial();
        $service->shouldReceive('recordMovement')->andThrow(new \RuntimeException('Forced failure'));

        try {
            $service->adjustStock($inventory, 5, 'TEST');
            $this->fail('Adjustment should have failed');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Forced failure', $e->getMessage());
        }

        // 15. If movement creation fails, stock update must not persist
        $this->assertEquals(10, $inventory->refresh()->current_stock);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_adjustment_isolation_and_permissions(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $branchA2 = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $productA = Product::create(['product_category_id' => $catA->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active', 'is_inventory_tracked' => true]);
        $inventoryA = app(InventoryService::class)->initializeInventory(['branch_id' => $branchA->id, 'product_id' => $productA->id, 'current_stock' => 10]);

        // 6. Manual adjustment requires active TenantContext
        app(TenantContext::class)->clear();
        try {
            app(InventoryService::class)->adjustStock($inventoryA, 1, 'TEST');
            $this->fail('Allowed without TenantContext');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('without active TenantContext', $e->getMessage());
        }

        // 7. Manual adjustment cannot target inventory from another tenant
        app(TenantContext::class)->setTenant($tenantB);
        try {
            app(InventoryService::class)->adjustStock($inventoryA, 1, 'TEST');
            $this->fail('Allowed cross-tenant adjustment');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('belonging to a different tenant', $e->getMessage());
        }
        app(TenantContext::class)->clear();

        // 8. Manual adjustment cannot target inventory from another branch when BranchContext is active
        app(TenantContext::class)->setTenant($tenantA);
        app(BranchContext::class)->setBranch($branchA2);
        try {
            app(InventoryService::class)->adjustStock($inventoryA, 1, 'TEST');
            $this->fail('Allowed adjustment outside active branch context');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('outside the active branch context', $e->getMessage());
        }
        app(BranchContext::class)->clear();

        // 12, 13. Permission check
        $user = User::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active', 'actor_type' => 'tenant_user']);
        $this->actingAs($user);
        try {
            app(InventoryService::class)->adjustStock($inventoryA, 1, 'TEST');
            $this->fail('Allowed without permission');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not have permission', $e->getMessage());
        }

        app(TenantContext::class)->clear();
    }
}
