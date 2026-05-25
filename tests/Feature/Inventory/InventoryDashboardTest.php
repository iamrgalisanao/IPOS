<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);

        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'name' => 'Main Branch',
        ]);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get(route('inventory.dashboard.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authorized_owner_can_view_slice_d_dashboard_payload(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Category A',
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Product A',
            'sku' => 'PROD-A',
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 2,
            'reorder_level' => 8,
            'average_cost' => 4.5,
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.dashboard.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Dashboard/Index')
            ->has('categories', 1)
            ->where('permissions.can_view_costs', true)
            ->has('reorderPriorities', 1)
            ->where('reorderPriorities.0.product_name', 'Product A')
            ->where('reorderPriorities.0.priority_class', 'high')
            ->where('summary.suggested_reorder_units', 6)
            ->where('summary.estimated_reorder_value', 27)
        );
    }

    public function test_cost_fields_are_masked_for_non_audit_user(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Category B',
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Product B',
            'sku' => 'PROD-B',
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => -1,
            'reorder_level' => 5,
            'average_cost' => 9.25,
            'status' => 'active',
        ]);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inventory Dashboard Viewer',
            'description' => 'Read-only inventory dashboard access without cost visibility',
        ]);

        $role->permissions()->attach(Permission::where('name', 'inventory.stocktake.view')->firstOrFail());
        $viewer->assignRole($role);
        $viewer->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($viewer)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.dashboard.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Dashboard/Index')
            ->where('permissions.can_view_costs', false)
            ->where('summary.estimated_reorder_value', null)
            ->where('productVisibility.0.average_cost', null)
            ->where('productVisibility.0.estimated_reorder_value', null)
            ->where('reorderPriorities.0.estimated_reorder_value', null)
        );
    }

    public function test_category_and_priority_filters_narrow_results(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $categoryA = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Category A',
            'status' => 'active',
        ]);

        $categoryB = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Category B',
            'status' => 'active',
        ]);

        $criticalProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $categoryA->id,
            'name' => 'Critical Product',
            'sku' => 'CRIT-1',
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        $normalProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $categoryB->id,
            'name' => 'Normal Product',
            'sku' => 'NORM-1',
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $criticalProduct->id,
            'current_stock' => -2,
            'reorder_level' => 3,
            'average_cost' => 2.5,
            'status' => 'active',
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $normalProduct->id,
            'current_stock' => 20,
            'reorder_level' => 5,
            'average_cost' => 2.5,
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.dashboard.index', [
                'category_id' => $categoryA->id,
                'priority' => 'critical',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('filters.category_id', $categoryA->id)
            ->where('filters.priority', 'critical')
            ->where('productVisibility.0.product_name', 'Critical Product')
            ->where('productVisibility.0.priority_class', 'critical')
            ->where('summary.negative_stock_count', 1)
        );
    }

    public function test_movement_type_and_source_filters_narrow_movement_summary(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Movement Category',
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Movement Product',
            'sku' => 'MOVE-1',
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 12,
            'reorder_level' => 5,
            'average_cost' => 2,
            'status' => 'active',
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'branch_inventory_id' => $inventory->id,
            'movement_type' => 'stock_in',
            'source_type' => 'receiving',
            'quantity_before' => 10,
            'quantity_change' => 2,
            'quantity_after' => 12,
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'branch_inventory_id' => $inventory->id,
            'movement_type' => 'manual_adjustment',
            'source_type' => 'stocktake',
            'quantity_before' => 12,
            'quantity_change' => -1,
            'quantity_after' => 11,
        ]);

        $viewBranchInventoryPermission = Permission::firstOrCreate(
            [
                'tenant_id' => $this->tenant->id,
                'name' => 'view_branch_inventory',
            ],
            [
                'description' => 'View branch inventory dashboard data',
            ]
        );

        $this->owner->roles()->first()->permissions()->syncWithoutDetaching([
            $viewBranchInventoryPermission->id,
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.dashboard.index', [
                'movement_type' => 'stock_in',
                'source_type' => 'receiving',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('filters.movement_type', 'stock_in')
            ->where('filters.source_type', 'receiving')
            ->where('movementSummary.total_count', 1)
            ->where('movementSummary.recent_movements.0.movement_type', 'stock_in')
            ->where('movementSummary.recent_movements.0.source_type', 'receiving')
        );
    }

    public function test_movement_summary_is_empty_without_inventory_movement_permission(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Restricted Category',
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Restricted Product',
            'sku' => 'RES-1',
            'status' => 'active',
            'is_inventory_tracked' => true,
        ]);

        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 3,
            'reorder_level' => 5,
            'average_cost' => 1,
            'status' => 'active',
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'branch_inventory_id' => $inventory->id,
            'movement_type' => 'stock_in',
            'source_type' => 'receiving',
            'quantity_before' => 0,
            'quantity_change' => 3,
            'quantity_after' => 3,
        ]);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Dashboard Stocktake Viewer',
            'description' => 'Dashboard access without movement permission',
        ]);

        $role->permissions()->attach(Permission::where('name', 'inventory.stocktake.view')->firstOrFail());
        $viewer->assignRole($role);
        $viewer->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($viewer)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.dashboard.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('movementSummary.total_count', 0)
            ->where('movementSummary.recent_movements', [])
            ->where('movementSummary.type_counts', [])
            ->where('movementSummary.source_type_counts', [])
        );
    }
}
