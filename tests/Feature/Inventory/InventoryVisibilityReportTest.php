<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\ExpiryLot;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryVisibilityReportTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $owner;
    protected User $cashier;

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

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get(route('inventory.reports.visibility.index'))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_users_are_forbidden(): void
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.visibility.index'))
            ->assertForbidden();
    }

    public function test_authorized_user_can_view_inventory_visibility_report(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Beverages',
            'status' => 'active',
        ]);

        $lowProduct = $this->product($category, [
            'name' => 'Milk 1L',
            'sku' => 'MILK-1',
            'barcode' => '480001',
            'unit_of_measure' => 'piece',
            'expiry_tracking_enabled' => true,
        ]);
        $normalProduct = $this->product($category, [
            'name' => 'Coffee Beans',
            'sku' => 'COFFEE-1',
            'unit_of_measure' => 'bag',
            'expiry_tracking_enabled' => true,
        ]);
        $unsoldProduct = $this->product($category, [
            'name' => 'Slow Syrup',
            'sku' => 'SYRUP-1',
            'unit_of_measure' => 'bottle',
        ]);

        $lowInventory = $this->inventory($lowProduct, ['current_stock' => 3, 'reorder_level' => 5]);
        $this->inventory($normalProduct, ['current_stock' => 20, 'reorder_level' => 5]);
        $this->inventory($unsoldProduct, ['current_stock' => 10, 'reorder_level' => 5]);

        ExpiryLot::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $lowProduct->id,
            'batch_code' => 'MILK-LOT',
            'quantity_received' => 10,
            'quantity_remaining' => 4,
            'expiry_date' => now()->addDays(7)->toDateString(),
            'status' => 'active',
        ]);
        ExpiryLot::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $normalProduct->id,
            'batch_code' => 'COFFEE-LOT',
            'quantity_received' => 10,
            'quantity_remaining' => 8,
            'expiry_date' => now()->addDays(90)->toDateString(),
            'status' => 'active',
        ]);

        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $lowProduct->id,
            'branch_inventory_id' => $lowInventory->id,
            'movement_type' => 'stock_in',
            'quantity_before' => 0,
            'quantity_change' => 3,
            'quantity_after' => 3,
            'created_at' => now()->subDays(2),
        ]);

        $this->saleItem($lowProduct, now()->subDays(45)->toDateTimeString());
        $this->saleItem($normalProduct, now()->subDays(2)->toDateTimeString());

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.visibility.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Visibility/Index')
            ->where('summary.total_skus_tracked', 3)
            ->where('summary.skus_below_reorder', 1)
            ->where('summary.skus_with_expiry_risk', 1)
            ->where('summary.slow_moving_or_unsold_skus', 2)
            ->has('rows', 3)
            ->where('rows.0.product_name', 'Milk 1L')
            ->where('rows.0.stock_state', 'low')
            ->where('rows.0.expiry_status', 'soon')
            ->where('rows.0.movement_status', 'slow_moving')
            ->where('rows.0.current_stock', 3)
            ->where('rows.0.reorder_level', 5)
            ->where('rows.0.unit_of_measure', 'piece')
            ->where('rows.1.product_name', 'Coffee Beans')
            ->where('rows.1.expiry_status', 'clear')
            ->where('rows.1.movement_status', 'active')
            ->where('rows.2.product_name', 'Slow Syrup')
            ->where('rows.2.movement_status', 'unsold')
            ->where('meta.can_export', true)
        );
    }

    public function test_filters_narrow_low_stock_and_expiry_risk_rows(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Perishables',
            'status' => 'active',
        ]);
        $otherCategory = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Dry Goods',
            'status' => 'active',
        ]);

        $milk = $this->product($category, ['name' => 'Filter Milk', 'sku' => 'FILTER-MILK']);
        $rice = $this->product($otherCategory, ['name' => 'Rice Sack', 'sku' => 'RICE-1']);
        $this->inventory($milk, ['current_stock' => 1, 'reorder_level' => 5]);
        $this->inventory($rice, ['current_stock' => 30, 'reorder_level' => 5]);

        ExpiryLot::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $milk->id,
            'batch_code' => 'FILTER-MILK-LOT',
            'quantity_received' => 5,
            'quantity_remaining' => 1,
            'expiry_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.visibility.index', [
                'category_id' => $category->id,
                'search' => 'MILK',
                'low_stock_only' => '1',
                'expiry_risk_only' => '1',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('filters.category_id', $category->id)
            ->where('filters.search', 'MILK')
            ->where('filters.low_stock_only', true)
            ->where('filters.expiry_risk_only', true)
            ->where('summary.total_skus_tracked', 1)
            ->where('rows.0.product_name', 'Filter Milk')
        );
    }

    public function test_branch_limited_user_only_sees_assigned_branch_inventory(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $otherBranch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hidden Branch',
            'status' => 'active',
        ]);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inventory Report Viewer',
            'description' => 'Inventory report view only',
        ]);
        $role->permissions()->attach(Permission::where('name', 'view_inventory_reports')->firstOrFail());
        $viewer->assignRole($role);
        $viewer->branches()->attach($this->branch->id);

        $visibleProduct = $this->product(null, ['name' => 'Visible Product', 'sku' => 'VISIBLE']);
        $hiddenProduct = $this->product(null, ['name' => 'Hidden Product', 'sku' => 'HIDDEN']);
        $this->inventory($visibleProduct, ['branch_id' => $this->branch->id, 'current_stock' => 5, 'reorder_level' => 2]);
        $this->inventory($hiddenProduct, ['branch_id' => $otherBranch->id, 'current_stock' => 5, 'reorder_level' => 2]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.visibility.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_skus_tracked', 1)
            ->where('rows.0.product_name', 'Visible Product')
        );

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.visibility.index', ['branch_id' => $otherBranch->id]))
            ->assertForbidden();
    }

    public function test_export_uses_same_scope_and_sanitizes_formula_cells(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => '=Risk Cat',
            'status' => 'active',
        ]);
        $product = $this->product($category, [
            'name' => '=Milk',
            'sku' => '+MILK',
            'barcode' => '@BAR',
        ]);
        $this->inventory($product, ['current_stock' => 2, 'reorder_level' => 5]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.visibility.export'));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('IPOS Inventory Visibility Report', $response->getContent());
        $this->assertStringContainsString("'=Milk", $response->getContent());
        $this->assertStringContainsString("'+MILK", $response->getContent());
        $this->assertStringContainsString("'@BAR", $response->getContent());
        $this->assertStringContainsString("'=Risk Cat", $response->getContent());
        $this->assertStringContainsString('This report summarizes existing inventory records only', $response->getContent());
    }

    protected function product(?ProductCategory $category = null, array $overrides = []): Product
    {
        $category ??= ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        return Product::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'status' => 'active',
            'is_inventory_tracked' => true,
        ], $overrides));
    }

    protected function inventory(Product $product, array $overrides = []): BranchInventory
    {
        return BranchInventory::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 10,
            'reorder_level' => 5,
            'average_cost' => 1,
            'status' => 'active',
        ], $overrides));
    }

    protected function saleItem(Product $product, $createdAt): SaleItem
    {
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'status' => 'paid',
            'total' => 100,
            'reporting_basis_at' => $createdAt,
            'confirmed_at' => $createdAt,
            'created_at' => $createdAt,
        ]);

        return SaleItem::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit_of_measure' => $product->unit_of_measure ?? 'piece',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 100,
            'is_inventory_tracked' => true,
            'created_at' => $createdAt,
        ]);
    }
}
