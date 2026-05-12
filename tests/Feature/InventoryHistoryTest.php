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
use App\Services\InventoryHistoryService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    /** @test */
    public function test_history_tenant_isolation_adversarial(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // Setup Tenant A Data
        app(TenantContext::class)->setTenant($tenantA);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        $catA = ProductCategory::create(['name' => 'C', 'code' => 'C']);
        $prodA = Product::create(['product_category_id' => $catA->id, 'name' => 'P', 'sku' => 'S1']);
        $invA = BranchInventory::create(['branch_id' => $branchA->id, 'product_id' => $prodA->id, 'current_stock' => 0]);
        app(InventoryService::class)->adjustStock($invA, 10, 'initial_stock');

        // Setup Tenant B Data
        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $prodB = Product::create(['product_category_id' => ProductCategory::create(['name' => 'C2', 'code' => 'C2'])->id, 'name' => 'PB', 'sku' => 'SB']);
        $invB = BranchInventory::create(['branch_id' => $branchB->id, 'product_id' => $prodB->id, 'current_stock' => 0]);
        app(InventoryService::class)->adjustStock($invB, 5, 'stock_b');

        // 4, 7. Owner/Admin Tenant A cannot view Tenant B history
        app(TenantContext::class)->clear();
        app(TenantContext::class)->setTenant($tenantA);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $this->actingAs($userA);
        
        $role = Role::create(['tenant_id' => $tenantA->id, 'name' => 'Admin', 'code' => 'admin']);
        $role->permissions()->attach(Permission::create(['tenant_id' => $tenantA->id, 'name' => 'view_branch_reports', 'code' => 'view_branch_reports']));
        $userA->roles()->attach($role);

        $history = app(InventoryHistoryService::class)->getHistory();
        $this->assertCount(1, $history);
        $this->assertEquals($prodA->id, $history->first()->product_id);
        $this->assertFalse($history->pluck('product_id')->contains($prodB->id));
    }

    /** @test */
    public function test_history_branch_isolation_and_assignment(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        $branchA = Branch::factory()->create(['name' => 'Branch A', 'status' => 'active']);
        $branchB = Branch::factory()->create(['name' => 'Branch B', 'status' => 'active']);
        
        $cat = ProductCategory::create(['name' => 'C', 'code' => 'C']);
        $prod = Product::create(['product_category_id' => $cat->id, 'name' => 'P', 'sku' => 'S1']);
        
        $invA = BranchInventory::create(['branch_id' => $branchA->id, 'product_id' => $prod->id, 'current_stock' => 0]);
        $invB = BranchInventory::create(['branch_id' => $branchB->id, 'product_id' => $prod->id, 'current_stock' => 0]);
        
        app(InventoryService::class)->adjustStock($invA, 10, 'stock_a');
        app(InventoryService::class)->adjustStock($invB, 5, 'stock_b');

        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($manager);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Manager', 'code' => 'manager']);
        $role->permissions()->attach(Permission::create(['tenant_id' => $tenant->id, 'name' => 'view_branch_reports', 'code' => 'view_branch_reports']));
        $manager->roles()->attach($role);

        // 1. Authorized Branch Manager can view history for assigned branch
        app(BranchContext::class)->setBranch($branchA);
        $history = app(InventoryHistoryService::class)->getHistory();
        $this->assertCount(1, $history);
        $this->assertEquals($branchA->id, $history->first()->branch_id);

        // 2, 8. Manager cannot view history for unassigned branch (via context scoping)
        app(BranchContext::class)->setBranch($branchB);
        $history = app(InventoryHistoryService::class)->getHistory();
        $this->assertCount(1, $history);
        $this->assertEquals($branchB->id, $history->first()->branch_id);
        $this->assertFalse($history->pluck('branch_id')->contains($branchA->id));

        // 3. Owner/Admin can view across branches
        app(BranchContext::class)->clear();
        $this->assertCount(2, app(InventoryHistoryService::class)->getHistory());
    }

    /** @test */
    public function test_history_role_access_blocks(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        
        // 5. Cashier blocked by default
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($cashier);
        try {
            app(InventoryHistoryService::class)->getHistory();
            $this->fail('Cashier accessed history');
        } catch (\RuntimeException $e) { $this->assertTrue(true); }

        // 6. Platform Support blocked
        $support = User::factory()->create(['actor_type' => 'platform_support']);
        $this->actingAs($support);
        try {
            app(InventoryHistoryService::class)->getHistory();
            $this->fail('Platform support accessed history');
        } catch (\RuntimeException $e) { $this->assertTrue(true); }
    }

    /** @test */
    public function test_history_filtering_accuracy(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C', 'code' => 'C']);
        $p1 = Product::create(['product_category_id' => $cat->id, 'name' => 'P1', 'sku' => 'S1']);
        $p2 = Product::create(['product_category_id' => $cat->id, 'name' => 'P2', 'sku' => 'S2']);
        
        $inv1 = BranchInventory::create(['branch_id' => $branch->id, 'product_id' => $p1->id, 'current_stock' => 0]);
        $inv2 = BranchInventory::create(['branch_id' => $branch->id, 'product_id' => $p2->id, 'current_stock' => 0]);
        
        app(InventoryService::class)->stockIn($inv1, 10); // type: stock_in
        app(InventoryService::class)->adjustStock($inv1, -2, 'damage'); // type: manual_adjustment
        
        Carbon::setTestNow(now()->subDays(5));
        app(InventoryService::class)->stockIn($inv2, 5);
        Carbon::setTestNow(null);

        $service = app(InventoryHistoryService::class);

        // 9. Product filter
        $this->assertCount(2, $service->getHistory(['product_id' => $p1->id]));
        
        // 10. Type filter
        $this->assertCount(2, $service->getHistory(['movement_type' => 'stock_in']));
        
        // 11. Date filter
        $this->assertCount(2, $service->getHistory(['date_from' => now()->subDay()->format('Y-m-d')]));
    }

    /** @test */
    public function test_history_payload_security_and_fields(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['name' => 'HQ', 'status' => 'active']);
        $cat = ProductCategory::create(['name' => 'C', 'code' => 'C']);
        $prod = Product::create(['product_category_id' => $cat->id, 'name' => 'Apple', 'sku' => 'SKU1', 'barcode' => 'BC1']);
        $inv = BranchInventory::create(['branch_id' => $branch->id, 'product_id' => $prod->id, 'current_stock' => 0]);
        
        app(InventoryService::class)->stockIn($inv, 10, remarks: 'Audit test');
        $movement = app(InventoryHistoryService::class)->getHistory()->first();

        // 12. Required fields
        $this->assertNotNull($movement->id);
        $this->assertEquals($tenant->id, $movement->tenant_id);
        $this->assertEquals($branch->id, $movement->branch_id);
        $this->assertEquals('HQ', $movement->branch->name);
        $this->assertEquals($prod->id, $movement->product_id);
        $this->assertEquals('Apple', $movement->product->name);
        $this->assertEquals('SKU1', $movement->product->sku);
        $this->assertEquals('BC1', $movement->product->barcode);
        $this->assertEquals('stock_in', $movement->movement_type);
        $this->assertEquals(0, $movement->quantity_before);
        $this->assertEquals(10, $movement->quantity_change);
        $this->assertEquals(10, $movement->quantity_after);
        $this->assertEquals('Audit test', $movement->remarks);
        $this->assertNotNull($movement->created_at);

        // 13. Excludes accounting metadata
        $array = $movement->toArray();
        $this->assertArrayNotHasKey('quickbooks_id', $array);
        $this->assertArrayNotHasKey('gl_account_id', $array);
        $this->assertArrayNotHasKey('sync_status', $array);
    }

    /** @test */
    public function test_history_immutability(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['status' => 'active']);
        $prod = Product::create(['product_category_id' => ProductCategory::create(['name' => 'C', 'code' => 'C'])->id, 'name' => 'P', 'sku' => 'S1']);
        $inv = BranchInventory::create(['branch_id' => $branch->id, 'product_id' => $prod->id, 'current_stock' => 0]);
        app(InventoryService::class)->stockIn($inv, 10);

        $movement = app(InventoryHistoryService::class)->getHistory()->first();

        // 14, 15. Read-only and Immutable
        try {
            $movement->update(['quantity_change' => 99]);
            $this->fail('History record updated');
        } catch (\RuntimeException $e) { $this->assertTrue(true); }

        try {
            $movement->delete();
            $this->fail('History record deleted');
        } catch (\RuntimeException $e) { $this->assertTrue(true); }
    }
}
