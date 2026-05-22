<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchInventoryThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->category = ProductCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General',
            'code' => 'GEN'
        ]);
    }

    public function test_can_create_branch_inventory_with_threshold_fields()
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true
        ]);

        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 50,
            'reorder_level' => 15,
            'par_level' => 100,
            'lead_time_days' => 5,
            'safety_stock_buffer' => 10,
            'status' => 'active'
        ]);

        $this->assertDatabaseHas('branch_inventories', [
            'id' => $inventory->id,
            'reorder_level' => 15.0000,
            'par_level' => 100.0000,
            'lead_time_days' => 5,
            'safety_stock_buffer' => 10.0000,
        ]);
    }

    public function test_threshold_fields_have_correct_casts()
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true
        ]);

        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => '50.1234',
            'reorder_level' => '15.5678',
            'par_level' => '100.8901',
            'lead_time_days' => '5',
            'safety_stock_buffer' => '10.2345',
            'status' => 'active'
        ]);

        $inventory->refresh();

        $this->assertEquals(50.1234, (float) $inventory->current_stock);
        $this->assertEquals(15.5678, (float) $inventory->reorder_level);
        $this->assertEquals(100.8901, (float) $inventory->par_level);
        $this->assertSame(5, $inventory->lead_time_days);
        $this->assertEquals(10.2345, (float) $inventory->safety_stock_buffer);
    }
}
