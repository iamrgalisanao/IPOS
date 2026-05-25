<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductRecipe;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCompositionReportTest extends TestCase
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
        $this->get(route('inventory.reports.product-composition.index'))
            ->assertRedirect(route('login'));
    }

    public function test_unauthorized_users_are_forbidden(): void
    {
        $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.index'))
            ->assertForbidden();
    }

    public function test_authorized_users_can_view_direct_recipe_rows(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Meals',
            'status' => 'active',
        ]);

        $parent = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Burger',
            'sku' => 'P-BURGER',
            'unit_of_measure' => 'piece',
            'is_sellable' => true,
            'status' => 'active',
            'product_type' => 'finished_good',
        ]);

        $ingredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Beef Patty',
            'sku' => 'I-PATTY',
            'unit_of_measure' => 'piece',
            'is_sellable' => false,
            'status' => 'active',
            'product_type' => 'semi_finished',
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $parent->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 1,
            'unit' => 'piece',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/ProductComposition/Index')
            ->where('filters.expansion_mode', 'direct_only')
            ->has('rows.data', 1)
            ->where('rows.data.0.parent_product_name', 'Burger')
            ->where('rows.data.0.ingredient_name', 'Beef Patty')
            ->where('rows.data.0.mode_semantics', 'matches_live_deduction')
        );
    }

    public function test_cross_tenant_data_is_not_included_in_report_rows(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $localCategory = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Local Cat',
            'status' => 'active',
        ]);

        $localParent = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $localCategory->id,
            'name' => 'Local Parent',
            'sku' => 'LOCAL-P',
            'unit_of_measure' => 'piece',
            'is_sellable' => true,
            'status' => 'active',
        ]);

        $localIngredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $localCategory->id,
            'name' => 'Local Ingredient',
            'sku' => 'LOCAL-I',
            'unit_of_measure' => 'piece',
            'status' => 'active',
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $localParent->id,
            'ingredient_id' => $localIngredient->id,
            'quantity' => 1,
            'unit' => 'piece',
        ]);

        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($otherTenant);
        app(TenantContext::class)->setTenant($otherTenant);

        $otherCategory = ProductCategory::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Cat',
            'status' => 'active',
        ]);

        $otherParent = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'product_category_id' => $otherCategory->id,
            'name' => 'Other Parent',
            'sku' => 'OTHER-P',
            'unit_of_measure' => 'piece',
            'is_sellable' => true,
            'status' => 'active',
        ]);

        $otherIngredient = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'product_category_id' => $otherCategory->id,
            'name' => 'Other Ingredient',
            'sku' => 'OTHER-I',
            'unit_of_measure' => 'piece',
            'status' => 'active',
        ]);

        ProductRecipe::create([
            'tenant_id' => $otherTenant->id,
            'product_id' => $otherParent->id,
            'ingredient_id' => $otherIngredient->id,
            'quantity' => 1,
            'unit' => 'piece',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', 1)
            ->where('rows.data.0.parent_product_name', 'Local Parent')
            ->where('rows.data.0.ingredient_name', 'Local Ingredient')
        );
    }

    public function test_category_filter_is_tenant_scoped(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($otherTenant);
        $foreignCategory = ProductCategory::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->from(route('inventory.reports.product-composition.index'))
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.index', [
                'category_id' => $foreignCategory->id,
            ]));

        $response->assertRedirect(route('inventory.reports.product-composition.index'));
        $response->assertSessionHasErrors(['category_id']);
    }

    public function test_flatten_mode_expands_nested_subrecipes_with_planning_semantics(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $burger = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Burger',
            'sku' => 'BURGER',
            'unit_of_measure' => 'piece',
            'is_sellable' => true,
            'status' => 'active',
            'product_type' => 'finished_good',
        ]);

        $patty = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Patty',
            'sku' => 'PATTY',
            'unit_of_measure' => 'piece',
            'is_sellable' => false,
            'status' => 'active',
            'product_type' => 'semi_finished',
        ]);

        $beef = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Beef',
            'sku' => 'BEEF',
            'unit_of_measure' => 'gram',
            'is_sellable' => false,
            'status' => 'active',
            'product_type' => 'raw_material',
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $burger->id,
            'ingredient_id' => $patty->id,
            'quantity' => 1,
            'unit' => 'piece',
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $patty->id,
            'ingredient_id' => $beef->id,
            'quantity' => 150,
            'unit' => 'gram',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.index', [
                'expansion_mode' => 'flatten_subrecipes',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('semantics.mode_semantics', 'planning_only')
            ->where('semantics.banner', 'Flattened mode is planning-only. Live POS currently deducts direct recipe components only.')
            ->has('rows.data', 2)
            ->where('rows.data.0.ingredient_name', 'Patty')
            ->where('rows.data.0.mode_semantics', 'planning_only')
            ->where('rows.data.1.ingredient_name', 'Beef')
            ->where('rows.data.1.depth', 1)
            ->where('rows.data.1.effective_quantity_base', 150)
            ->where('rows.data.1.path_signature', 'Burger > Patty > Beef')
        );
    }

    public function test_flatten_mode_marks_cycles_without_infinite_recursion(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $parent = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Parent',
            'sku' => 'PARENT',
            'unit_of_measure' => 'piece',
            'is_sellable' => true,
            'status' => 'active',
        ]);

        $child = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Child',
            'sku' => 'CHILD',
            'unit_of_measure' => 'piece',
            'is_sellable' => false,
            'status' => 'active',
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $parent->id,
            'ingredient_id' => $child->id,
            'quantity' => 1,
            'unit' => 'piece',
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $child->id,
            'ingredient_id' => $parent->id,
            'quantity' => 1,
            'unit' => 'piece',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.index', [
                'expansion_mode' => 'flatten_subrecipes',
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 2)
            ->where('rows.data.1.ingredient_name', 'Parent')
            ->where('rows.data.1.recursion_status', 'cycle_detected')
            ->where('rows.data.1.row_warnings.0', 'cycle_detected')
        );
    }

    public function test_csv_export_contains_full_filtered_rows_and_escapes_formula_cells(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $firstParent = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => '=Formula Parent',
            'sku' => '=PARENT',
            'unit_of_measure' => 'piece',
            'is_sellable' => true,
            'status' => 'active',
        ]);

        $secondParent = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Regular Parent',
            'sku' => 'REG-PARENT',
            'unit_of_measure' => 'piece',
            'is_sellable' => true,
            'status' => 'active',
        ]);

        $ingredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => '+Ingredient',
            'sku' => '+ING',
            'unit_of_measure' => 'piece',
            'is_sellable' => false,
            'status' => 'active',
        ]);

        foreach ([$firstParent, $secondParent] as $parent) {
            ProductRecipe::create([
                'tenant_id' => $this->tenant->id,
                'product_id' => $parent->id,
                'ingredient_id' => $ingredient->id,
                'quantity' => 1,
                'unit' => 'piece',
            ]);
        }

        app(TenantContext::class)->clear();

        $pageResponse = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.index', [
                'per_page' => 1,
            ]));
        $pageResponse->assertOk();
        $pageResponse->assertInertia(fn (Assert $page) => $page
            ->where('rows.per_page', 1)
            ->where('rows.total', 2)
        );

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.export', [
                'per_page' => 1,
            ]));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=PARENT", $csv);
        $this->assertStringContainsString("'=Formula Parent", $csv);
        $this->assertStringContainsString("'+ING", $csv);
        $this->assertStringContainsString("'+Ingredient", $csv);
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1);
    }

    public function test_csv_export_blanks_cost_fields_without_audit_permission(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $parent = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Burger',
            'sku' => 'BURGER',
            'unit_of_measure' => 'piece',
            'is_sellable' => true,
            'status' => 'active',
        ]);

        $ingredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Beef',
            'sku' => 'BEEF',
            'unit_of_measure' => 'piece',
            'is_sellable' => false,
            'status' => 'active',
            'cost_price' => 20,
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $parent->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 2,
            'unit' => 'piece',
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $ingredient->id,
            'current_stock' => 10,
            'average_cost' => 12.5,
            'reorder_level' => 2,
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
            'description' => 'Can view inventory composition reports without cost access',
        ]);
        $role->permissions()->attach(Permission::where('name', 'view_inventory_reports')->firstOrFail());

        $viewer->assignRole($role);
        $viewer->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.export', [
                'branch_id' => $this->branch->id,
            ]));

        $response->assertOk();
        $rows = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
        $data = $rows[1];

        $this->assertSame('', $data[15]); // Branch Avg Cost
        $this->assertSame('', $data[16]); // Fallback Cost
        $this->assertSame('', $data[17]); // Cost Status
        $this->assertSame('', $data[18]); // Effective Ingredient Cost / Parent Unit
        $this->assertSame('10', $data[13]); // Branch Stock remains visible
    }

    public function test_index_masks_cost_fields_without_audit_permission(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $parent = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Burger',
            'sku' => 'BURGER',
            'unit_of_measure' => 'piece',
            'is_sellable' => true,
            'status' => 'active',
        ]);

        $ingredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Beef',
            'sku' => 'BEEF',
            'unit_of_measure' => 'piece',
            'is_sellable' => false,
            'status' => 'active',
            'cost_price' => 20,
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $parent->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 2,
            'unit' => 'piece',
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $ingredient->id,
            'current_stock' => 10,
            'average_cost' => 12.5,
            'reorder_level' => 2,
            'status' => 'active',
        ]);

        $viewer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inventory Report Viewer Index',
            'description' => 'Can view inventory composition report page without cost access',
        ]);
        $role->permissions()->attach(Permission::where('name', 'view_inventory_reports')->firstOrFail());

        $viewer->assignRole($role);
        $viewer->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.index', [
                'branch_id' => $this->branch->id,
            ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/ProductComposition/Index')
            ->where('permissions.can_view_costs', false)
            ->where('rows.data.0.branch_average_cost', null)
            ->where('rows.data.0.fallback_cost_price', null)
            ->where('rows.data.0.cost_status', null)
            ->where('rows.data.0.effective_cost_per_parent_unit', null)
            ->where('rows.data.0.branch_current_stock', 10)
        );
    }

    public function test_csv_export_enforces_configured_row_ceiling(): void
    {
        config(['reports.product_composition_export_max_rows' => 1]);

        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $ingredient = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Ingredient',
            'sku' => 'ING',
            'unit_of_measure' => 'piece',
            'status' => 'active',
        ]);

        foreach (['First', 'Second'] as $name) {
            $parent = Product::factory()->create([
                'tenant_id' => $this->tenant->id,
                'product_category_id' => $category->id,
                'name' => $name,
                'sku' => strtoupper($name),
                'unit_of_measure' => 'piece',
                'is_sellable' => true,
                'status' => 'active',
            ]);

            ProductRecipe::create([
                'tenant_id' => $this->tenant->id,
                'product_id' => $parent->id,
                'ingredient_id' => $ingredient->id,
                'quantity' => 1,
                'unit' => 'piece',
            ]);
        }

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->owner)
            ->from(route('inventory.reports.product-composition.index'))
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('inventory.reports.product-composition.export'));

        $response->assertRedirect(route('inventory.reports.product-composition.index'));
        $response->assertSessionHasErrors(['export']);
    }
}
