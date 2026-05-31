<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductRecipe;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\UnitConversion;
use App\Models\User;
use App\Services\Inventory\RecipeCostingService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeCostingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $owner;
    protected Product $composite;
    protected Product $ingredientA;
    protected Product $ingredientB;

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

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());

        $category = ProductCategory::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->composite = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'unit_of_measure' => 'piece',
            'selling_price' => 250.00,
        ]);

        $this->ingredientA = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'unit_of_measure' => 'kg',
            'cost_price' => 100.00,
        ]);

        $this->ingredientB = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'unit_of_measure' => 'ml',
            'cost_price' => 5.00,
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->composite->id,
            'ingredient_id' => $this->ingredientA->id,
            'quantity' => 200,
            'unit' => 'gram',
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->composite->id,
            'ingredient_id' => $this->ingredientB->id,
            'quantity' => 50,
            'unit' => 'ml',
        ]);

        app(TenantContext::class)->clear();
    }

    public function test_recipe_costing_service_computes_cost_using_catalog_fallback(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        
        $service = app(RecipeCostingService::class);
        $result = $service->compute($this->composite);

        // ingredientA: 200g = 0.2kg * 100/kg = 20
        // ingredientB: 50ml = 50ml * 5/ml = 250
        // Total = 270

        $this->assertFalse($result['has_missing_costs']);
        $this->assertFalse($result['has_missing_conversions']);
        $this->assertEquals(270.00, $result['total_cost']);

        $this->assertCount(2, $result['ingredients']);
        $this->assertEquals('catalog_cost', $result['ingredients'][0]['cost_source']);
        $this->assertEquals('catalog_cost', $result['ingredients'][1]['cost_source']);
    }

    public function test_recipe_costing_service_prefers_branch_wac_when_available(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        BranchInventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->ingredientA->id,
            'average_cost' => 120.00, // WAC is higher than catalog cost (100)
        ]);

        $service = app(RecipeCostingService::class);
        $result = $service->compute($this->composite, $this->branch->id);

        // ingredientA: 200g = 0.2kg * 120/kg (WAC) = 24
        // ingredientB: 50ml = 50ml * 5/ml (Fallback) = 250
        // Total = 274

        $this->assertFalse($result['has_missing_costs']);
        $this->assertEquals(274.00, $result['total_cost']);

        $ingA = collect($result['ingredients'])->firstWhere('ingredient_id', $this->ingredientA->id);
        $this->assertEquals('branch_wac', $ingA['cost_source']);
        $this->assertEquals(120.00, $ingA['unit_cost']);

        $ingB = collect($result['ingredients'])->firstWhere('ingredient_id', $this->ingredientB->id);
        $this->assertEquals('catalog_cost', $ingB['cost_source']);
        $this->assertEquals(5.00, $ingB['unit_cost']);
    }

    public function test_recipe_costing_endpoint_returns_json_cost_preview(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->getJson(route('admin.products.recipe.cost', $this->composite->id));

        $response->assertOk()
            ->assertJsonPath('total_cost', 270)
            ->assertJsonPath('ingredients.0.cost_source', 'catalog_cost');
    }

    public function test_update_recipe_endpoint_replaces_existing_recipe(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('admin.products.recipe.update', $this->composite->id), [
                'ingredients' => [
                    [
                        'ingredient_id' => $this->ingredientB->id,
                        'quantity' => 10,
                        'unit' => 'ml'
                    ]
                ]
            ]);

        $response->assertRedirect();

        app(TenantContext::class)->setTenant($this->tenant);
        
        $this->assertDatabaseCount('product_recipes', 1);
        $this->assertDatabaseHas('product_recipes', [
            'product_id' => $this->composite->id,
            'ingredient_id' => $this->ingredientB->id,
            'quantity' => 10,
            'unit' => 'ml',
        ]);
    }
}
