<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Services\TenantContext;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_inventory_movement_full_validation_matrix(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenantA);

        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $productA = Product::create([
            'product_category_id' => $catA->id,
            'name' => 'P1',
            'sku' => 'S1',
            'selling_price' => 100,
            'status' => 'active',
            'is_inventory_tracked' => true
        ]);

        $inventoryA = app(InventoryService::class)->initializeInventory([
            'branch_id' => $branchA->id,
            'product_id' => $productA->id,
            'current_stock' => 50
        ]);

        // 1. Inventory movement can be recorded for valid branch inventory
        $movement = app(InventoryService::class)->recordMovement($inventoryA, [
            'movement_type' => 'manual_adjustment',
            'quantity_change' => 10,
            'quantity_before' => 50,
            'quantity_after' => 60,
            'source_type' => 'StockAdjustment',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'reason_code' => 'RECV',
            'remarks' => 'Manual add'
        ]);

        // 2, 3, 4. Auto-captures tenant, branch, product
        $this->assertEquals($tenantA->id, $movement->tenant_id);
        $this->assertEquals($branchA->id, $movement->branch_id);
        $this->assertEquals($productA->id, $movement->product_id);

        // 7, 8. Source, reason, remarks
        $this->assertEquals('StockAdjustment', $movement->source_type);
        $this->assertEquals('RECV', $movement->reason_code);
        $this->assertEquals('Manual add', $movement->remarks);

        // 5. Invalid/Deprecated movement types are rejected
        $deprecatedTypes = ['adjustment', 'sale', 'void', 'return', 'stock_out'];
        foreach ($deprecatedTypes as $type) {
            try {
                app(InventoryService::class)->recordMovement($inventoryA, [
                    'movement_type' => $type,
                    'quantity_change' => 1,
                    'quantity_before' => 60,
                    'quantity_after' => 61
                ]);
                $this->fail("Deprecated movement type '{$type}' allowed");
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertTrue(true);
            }
        }

        // Verify all approved types are accepted
        $approvedTypes = ['stock_in', 'manual_adjustment', 'sale_deduction', 'void_reversal', 'refund_return', 'stock_correction'];
        foreach ($approvedTypes as $type) {
            $m = app(InventoryService::class)->recordMovement($inventoryA, [
                'movement_type' => $type,
                'quantity_change' => 0,
                'quantity_before' => 60,
                'quantity_after' => 60
            ]);
            $this->assertEquals($type, $m->movement_type);
        }

        // 6. quantity_after calculation mismatch is rejected
        try {
            app(InventoryService::class)->recordMovement($inventoryA, [
                'movement_type' => 'manual_adjustment',
                'quantity_change' => 10,
                'quantity_before' => 60,
                'quantity_after' => 75 // Should be 70
            ]);
            $this->fail('Inconsistent quantity_after allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('consistency error', $e->getMessage());
        }

        // 13. Movement cannot be recorded without TenantContext
        app(TenantContext::class)->clear();
        try {
            app(InventoryService::class)->recordMovement($inventoryA, [
                'movement_type' => 'manual_adjustment',
                'quantity_change' => 1,
                'quantity_before' => 60,
                'quantity_after' => 61
            ]);
            $this->fail('Movement allowed without TenantContext');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('active TenantContext', $e->getMessage());
        }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_cross_tenant_movement_security(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $catA = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $productA = Product::create(['product_category_id' => $catA->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active']);
        $inventoryA = app(InventoryService::class)->initializeInventory(['branch_id' => $branchA->id, 'product_id' => $productA->id, 'current_stock' => 10]);
        app(TenantContext::class)->clear();

        // 14. Movement cannot be recorded for inventory from another tenant
        app(TenantContext::class)->setTenant($tenantB);
        try {
            app(InventoryService::class)->recordMovement($inventoryA, [
                'movement_type' => 'manual_adjustment',
                'quantity_change' => 1,
                'quantity_before' => 10,
                'quantity_after' => 11
            ]);
            $this->fail('Cross-tenant movement recording allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different tenant', $e->getMessage());
        }

        // 11. Tenant A cannot access Tenant B movement records
        // First record a movement in Tenant A
        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($tenantA);
        app(InventoryService::class)->recordMovement($inventoryA, ['movement_type' => 'manual_adjustment', 'quantity_change' => 1, 'quantity_before' => 10, 'quantity_after' => 11]);
        $this->assertCount(2, InventoryMovement::all()); // 1 initial + 1 manual
        
        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($tenantB);
        $this->assertCount(0, InventoryMovement::all());

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_movement_is_append_only(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $product = Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active']);
        $inventory = app(InventoryService::class)->initializeInventory(['branch_id' => $branch->id, 'product_id' => $product->id, 'current_stock' => 10]);

        $movement = InventoryMovement::where('branch_inventory_id', $inventory->id)->first();

        // 9. Movement cannot be updated
        try {
            $movement->update(['remarks' => 'Modified']);
            $this->fail('Movement allowed update');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // 10. Movement cannot be deleted
        try {
            $movement->delete();
            $this->fail('Movement allowed deletion');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_branch_scoping_for_movements(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C1', 'code' => 'CAT1']);
        $product = Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'S1', 'selling_price' => 100, 'status' => 'active']);

        app(InventoryService::class)->initializeInventory(['branch_id' => $branch1->id, 'product_id' => $product->id, 'current_stock' => 10]);

        // 12. Branch A cannot access Branch B movement records through branch context
        $this->assertCount(1, InventoryMovement::where('branch_id', $branch1->id)->get());
        $this->assertCount(0, InventoryMovement::where('branch_id', $branch2->id)->get());

        app(TenantContext::class)->clear();
    }
}
