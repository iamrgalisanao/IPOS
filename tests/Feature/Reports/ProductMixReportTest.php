<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
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

class ProductMixReportTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $manager;
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
        ]);

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'name' => 'Branch Manager',
        ]);
        $this->manager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->manager->assignToBranch($this->branch);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
            'name' => 'Owner Admin',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branch);

        app(TenantContext::class)->clear();
    }

    public function test_authorized_user_can_view_product_mix_report(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Beverages',
        ]);
        $coffee = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Coffee',
            'sku' => 'COF-001',
        ]);
        $tea = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Tea',
            'sku' => 'TEA-001',
        ]);

        $paidSale = $this->sale(['status' => 'paid']);
        $this->item($paidSale, $coffee, ['quantity' => 2, 'subtotal' => 200, 'discount_amount' => 20, 'line_total' => 180]);
        $this->item($paidSale, $tea, ['quantity' => 1, 'subtotal' => 80, 'discount_amount' => 0, 'line_total' => 80]);

        $voidedSale = $this->sale(['status' => 'voided']);
        $this->item($voidedSale, $coffee, ['quantity' => 1, 'subtotal' => 100, 'discount_amount' => 0, 'line_total' => 100]);

        $response = $this->actingAs($this->manager)
            ->get(route('reports.product-mix.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Reports/ProductMix/Index')
            ->where('filters.status', 'paid')
            ->where('summary.total_quantity_sold', 3)
            ->where('summary.total_gross_sales', 280)
            ->where('summary.total_net_sales', 260)
            ->where('summary.unique_products_sold', 2)
            ->where('summary.top_selling_product', 'Coffee')
            ->where('summary.highest_revenue_product', 'Coffee')
            ->has('rows', 2)
            ->where('filter_options.branches.0.id', $this->branch->id)
            ->where('rows.0.product_name', 'Coffee')
            ->where('rows.0.sku', 'COF-001')
            ->where('rows.0.category_name', 'Beverages')
            ->where('rows.0.quantity_sold', 2)
            ->where('rows.0.gross_sales', 200)
            ->where('rows.0.discounts', 20)
            ->where('rows.0.net_sales', 180)
            ->where('rows.0.average_selling_price', 90)
            ->where('meta.can_export', false)
        );
    }

    public function test_unauthorized_user_cannot_view_product_mix_report(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->get(route('reports.product-mix.index'));

        $response->assertForbidden();
    }

    public function test_branch_scoping_fail_closes_for_unassigned_branch_filter(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Hidden Product']);
        $sale = $this->sale(['branch_id' => $otherBranch->id, 'status' => 'paid']);
        $this->item($sale, $product, ['branch_id' => $otherBranch->id, 'quantity' => 5, 'subtotal' => 500, 'line_total' => 500]);

        $response = $this->actingAs($this->manager)
            ->get(route('reports.product-mix.index', ['branch_id' => $otherBranch->id]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_quantity_sold', 0)
            ->where('summary.total_net_sales', 0)
            ->has('rows', 0)
        );
    }

    public function test_category_and_product_search_filters_narrow_rows(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $beverages = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Beverages']);
        $food = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Food']);
        $coffee = Product::factory()->create(['tenant_id' => $this->tenant->id, 'product_category_id' => $beverages->id, 'name' => 'Coffee', 'sku' => 'COF-001']);
        $sandwich = Product::factory()->create(['tenant_id' => $this->tenant->id, 'product_category_id' => $food->id, 'name' => 'Sandwich', 'sku' => 'SND-001']);

        $sale = $this->sale(['status' => 'paid']);
        $this->item($sale, $coffee, ['quantity' => 3, 'subtotal' => 300, 'line_total' => 300]);
        $this->item($sale, $sandwich, ['quantity' => 2, 'subtotal' => 220, 'line_total' => 220]);

        $response = $this->actingAs($this->manager)
            ->get(route('reports.product-mix.index', [
                'category_id' => $beverages->id,
                'product_search' => 'COF',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.unique_products_sold', 1)
            ->where('rows.0.product_name', 'Coffee')
            ->where('rows.0.quantity_sold', 3)
        );
    }

    public function test_export_is_permission_gated_and_sanitizes_formula_cells(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $category = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id, 'name' => '=Beverages']);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => '=Coffee',
            'sku' => '+COF',
        ]);
        $sale = $this->sale(['status' => 'paid']);
        $this->item($sale, $product, ['quantity' => 1, 'subtotal' => 100, 'line_total' => 100]);

        $managerResponse = $this->actingAs($this->manager)
            ->get(route('reports.product-mix.export'));
        $managerResponse->assertForbidden();

        $ownerResponse = $this->actingAs($this->owner)
            ->get(route('reports.product-mix.export'));

        $ownerResponse->assertOk();
        $this->assertStringStartsWith('text/csv', $ownerResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('IPOS Product Mix Report', $ownerResponse->getContent());
        $this->assertStringContainsString("'=Coffee", $ownerResponse->getContent());
        $this->assertStringContainsString("'+COF", $ownerResponse->getContent());
        $this->assertStringContainsString("'=Beverages", $ownerResponse->getContent());
    }

    protected function sale(array $overrides = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->manager->id,
            'status' => 'paid',
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'reporting_basis_at' => now(),
            'confirmed_at' => now(),
        ], $overrides));
    }

    protected function item(Sale $sale, Product $product, array $overrides = []): SaleItem
    {
        $quantity = $overrides['quantity'] ?? 1;
        $subtotal = $overrides['subtotal'] ?? 100;
        $discount = $overrides['discount_amount'] ?? 0;
        $lineTotal = $overrides['line_total'] ?? ($subtotal - $discount);

        return SaleItem::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit_of_measure' => 'piece',
            'quantity' => $quantity,
            'unit_price' => $quantity > 0 ? $subtotal / $quantity : $subtotal,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => 0,
            'line_total' => $lineTotal,
            'is_inventory_tracked' => true,
            'created_at' => now(),
        ], $overrides));
    }
}
