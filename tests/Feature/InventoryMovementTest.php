<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Services\Inventory\InventoryMovementRecorder;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\TenantContext;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $approvedTypes = [
            'stock_in',
            'manual_adjustment',
            'sale_deduction',
            'void_reversal',
            'refund_return',
            'stock_correction',
            'inventory_opening_balance',
            'supplier_receiving',
            'supplier_return',
            'ibt_dispatch',
            'ibt_receipt',
        ];
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

    /** @test */
    public function test_movement_sequence_uuid_and_schema_are_assigned_per_branch(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $category = ProductCategory::create(['name' => 'Inventory', 'code' => 'INV']);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name' => 'Tracked Product',
            'sku' => 'TRACKED-1',
            'selling_price' => 100,
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        $inventory1 = app(InventoryService::class)->initializeInventory([
            'branch_id' => $branch1->id,
            'product_id' => $product->id,
            'current_stock' => 10,
        ]);

        app(InventoryService::class)->recordMovement($inventory1, [
            'movement_type' => 'manual_adjustment',
            'quantity_change' => 2,
            'quantity_before' => 10,
            'quantity_after' => 12,
            'reason_code' => 'test_adjustment',
        ]);

        app(InventoryService::class)->initializeInventory([
            'branch_id' => $branch2->id,
            'product_id' => $product->id,
            'current_stock' => 5,
        ]);

        $branch1Sequences = InventoryMovement::withoutGlobalScopes()
            ->where('branch_id', $branch1->id)
            ->orderBy('movement_sequence')
            ->pluck('movement_sequence')
            ->all();

        $branch2Sequences = InventoryMovement::withoutGlobalScopes()
            ->where('branch_id', $branch2->id)
            ->orderBy('movement_sequence')
            ->pluck('movement_sequence')
            ->all();

        $this->assertSame([1, 2], $branch1Sequences);
        $this->assertSame([1], $branch2Sequences);

        $movement = InventoryMovement::where('branch_id', $branch1->id)->first();
        $this->assertNotEmpty($movement->movement_uuid);
        $this->assertSame(1, $movement->movement_schema_version);
        $this->assertNotNull($movement->business_date);
        $this->assertNotNull($movement->posted_at);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_source_effect_replay_returns_existing_movement_and_drift_is_rejected(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $category = ProductCategory::create(['name' => 'Replay', 'code' => 'REP']);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name' => 'Replay Product',
            'sku' => 'REPLAY-1',
            'selling_price' => 100,
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        $inventory = BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => 10,
            'status' => 'active',
        ]);

        $payload = [
            'movement_type' => 'manual_adjustment',
            'quantity_change' => 1,
            'quantity_before' => 10,
            'quantity_after' => 11,
            'source_type' => 'test_source',
            'source_id' => 'SRC-1',
            'source_effect_key' => 'test_source:SRC-1:product:' . $product->id,
        ];

        $first = app(InventoryMovementRecorder::class)->record($inventory, $payload);
        $replay = app(InventoryMovementRecorder::class)->record($inventory, $payload);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, InventoryMovement::where('source_id', 'SRC-1')->count());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('replay drift');

        app(InventoryMovementRecorder::class)->record($inventory, array_merge($payload, [
            'quantity_change' => 2,
            'quantity_after' => 12,
        ]));
    }

    /** @test */
    public function test_migration_baseline_cannot_be_created_by_runtime_recorder(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $category = ProductCategory::create(['name' => 'Baseline', 'code' => 'BASE']);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name' => 'Baseline Product',
            'sku' => 'BASE-1',
            'selling_price' => 100,
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        $inventory = BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => 10,
            'status' => 'active',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Migration baseline movements');

        app(InventoryMovementRecorder::class)->record($inventory, [
            'movement_type' => 'inventory_migration_baseline',
            'quantity_change' => 5,
            'quantity_before' => 5,
            'quantity_after' => 10,
            'source_type' => 'inventory_migration_baseline',
            'source_id' => 'epic-40-migration',
            'source_effect_key' => 'migration_baseline:epic-40:branch:' . $branch->id . ':product:' . $product->id,
        ]);
    }

    /** @test */
    public function test_reconciliation_service_detects_current_stock_mismatch(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $category = ProductCategory::create(['name' => 'Recon', 'code' => 'REC']);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name' => 'Recon Product',
            'sku' => 'RECON-1',
            'selling_price' => 100,
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        $inventory = app(InventoryService::class)->initializeInventory([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => 10,
        ]);

        $healthy = app(InventoryReconciliationService::class)
            ->reconcileBranch($branch, $product->id)
            ->first();

        $this->assertTrue($healthy['is_reconciled']);
        $this->assertSame(0.0, $healthy['system_reconciliation_variance']);

        DB::table('branch_inventories')
            ->where('id', $inventory->id)
            ->update(['current_stock' => 12]);

        $mismatch = app(InventoryReconciliationService::class)
            ->reconcileBranch($branch, $product->id)
            ->first();

        $this->assertFalse($mismatch['is_reconciled']);
        $this->assertSame(2.0, $mismatch['system_reconciliation_variance']);

        app(TenantContext::class)->clear();
    }
}
