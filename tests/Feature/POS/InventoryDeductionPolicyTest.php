<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\InventoryVarianceStatusEvent;
use App\Models\InventoryVarianceLog;
use App\Models\UnitConversion;
use App\Models\ProductRecipe;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryDeductionPolicyTest extends TestCase
{
    use RefreshDatabase, \Tests\Traits\InteractsWithShifts;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected PaymentMethod $cashMethod;
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
            'status' => 'active',
            'inventory_deduction_policy' => 'strict_block'
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'actor_type' => 'tenant_user'
        ]);
        
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier']);
        $permission = Permission::where('name', 'create_sale')->first() 
            ?? Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'create_sale']);
        $role->permissions()->attach($permission);

        $openShiftPermission = Permission::where('name', 'open_shift')->first() 
            ?? Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'open_shift']);
        $role->permissions()->attach($openShiftPermission);

        $this->user->assignRole($role);
        $this->user->assignToBranch($this->branch);
        
        $this->category = ProductCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General',
            'code' => 'GEN'
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'status' => 'active',
            'is_system' => true,
        ]);

        $this->openShiftFor($this->user, $this->branch);
    }

    private function createSale(array $items): Sale
    {
        $total = collect($items)->sum('line_total');
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => $total,
            'status' => 'created',
        ]);

        foreach ($items as $item) {
            SaleItem::create(array_merge([
                'tenant_id' => $this->tenant->id,
                'branch_id' => $this->branch->id,
                'sale_id' => $sale->id,
                'subtotal' => $item['line_total'],
                'tax_amount' => 0.00,
            ], $item));
        }

        return $sale;
    }

    private function postPayment(Sale $sale, float $amount): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.sales.payments', ['sale_id' => $sale->id]), [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => $amount
            ]);
    }

    public function test_allow_negative_with_warning_creates_variance_log_and_movement(): void
    {
        $this->branch->update(['inventory_deduction_policy' => 'allow_negative_with_warning']);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'status' => 'active'
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 1.00,
            'status' => 'active'
        ]);

        $sale = $this->createSale([[
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 3.00,
            'unit_price' => 50.00,
            'line_total' => 150.00,
            'is_inventory_tracked' => true
        ]]);

        $response = $this->postPayment($sale, 150.00);
        $response->assertStatus(200);

        // Verify inventory went negative
        $inventory = BranchInventory::where('product_id', $product->id)->first();
        $this->assertEquals(-2.00, (float)$inventory->current_stock);

        // Verify variance log is created
        $this->assertDatabaseHas('inventory_variance_logs', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => null, // direct sale
            'ingredient_id' => $product->id,
            'variance_category' => 'negative_stock',
            'current_status' => 'open',
            'required_quantity' => 3.00,
            'available_quantity_before' => 1.00,
            'shortage_quantity' => 2.00,
            'resulting_quantity' => -2.00,
            'incremental_shortage_quantity' => 2.00,
            'resulting_negative_quantity' => 2.00,
            'policy' => 'allow_negative_with_warning',
            'reason' => 'POS Checkout stock shortage deduction.',
        ]);

        // Verify movement log exists and shows negative balance
        $this->assertDatabaseHas('inventory_movements', [
            'source_id' => $sale->id,
            'product_id' => $product->id,
            'quantity_change' => -3.00,
            'quantity_before' => 1.00,
            'quantity_after' => -2.00
        ]);

        $variance = InventoryVarianceLog::where('sale_id', $sale->id)->firstOrFail();
        $movement = InventoryMovement::where('source_id', $sale->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame($movement->id, $variance->movement_id);
        $this->assertSame((string) $movement->movement_sequence, (string) $variance->movement_sequence);
        $this->assertDatabaseHas('inventory_variance_status_events', [
            'inventory_variance_log_id' => $variance->id,
            'event_type' => 'created',
            'to_status' => 'open',
        ]);
    }

    public function test_low_stock_above_zero_does_not_create_negative_stock_exception(): void
    {
        $this->branch->update(['inventory_deduction_policy' => 'allow_negative_with_warning']);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'status' => 'active'
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => 5.00,
            'reorder_level' => 10.00,
            'status' => 'active'
        ]);

        $sale = $this->createSale([[
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 4.00,
            'unit_price' => 50.00,
            'line_total' => 200.00,
            'is_inventory_tracked' => true
        ]]);

        $this->postPayment($sale, 200.00)->assertStatus(200);

        $inventory = BranchInventory::where('product_id', $product->id)->firstOrFail();
        $this->assertEquals(1.00, (float) $inventory->current_stock);
        $this->assertTrue($inventory->isLowStock());
        $this->assertDatabaseMissing('inventory_variance_logs', [
            'sale_id' => $sale->id,
            'variance_category' => 'negative_stock',
        ]);
    }

    public function test_existing_negative_stock_records_incremental_shortage_and_total_exposure(): void
    {
        $this->branch->update(['inventory_deduction_policy' => 'allow_negative_with_warning']);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'status' => 'active'
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => -2.00,
            'status' => 'active'
        ]);

        $sale = $this->createSale([[
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 5.00,
            'unit_price' => 50.00,
            'line_total' => 250.00,
            'is_inventory_tracked' => true
        ]]);

        $this->postPayment($sale, 250.00)->assertStatus(200);

        $this->assertDatabaseHas('inventory_variance_logs', [
            'sale_id' => $sale->id,
            'quantity_before' => -2.00,
            'quantity_required' => 5.00,
            'quantity_after' => -7.00,
            'incremental_shortage_quantity' => 5.00,
            'resulting_negative_quantity' => 7.00,
        ]);
    }

    public function test_recipe_shortage_logs_composite_parent_and_ingredient_correctly(): void
    {
        $this->branch->update(['inventory_deduction_policy' => 'allow_negative_with_warning']);

        // Composite Product (e.g., Burger)
        $burger = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => false,
            'status' => 'active'
        ]);

        // Ingredient Product (e.g., Bun)
        $bun = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'unit_of_measure' => 'piece',
            'status' => 'active'
        ]);

        // Recipe: 1 Burger requires 2 Buns
        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $burger->id,
            'ingredient_id' => $bun->id,
            'quantity' => 2.00,
            'unit' => 'piece'
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $bun->id,
            'current_stock' => 1.00,
            'status' => 'active'
        ]);

        // Sell 1 Burger (requires 2 Buns, shortage of 1.00)
        $sale = $this->createSale([[
            'product_id' => $burger->id,
            'product_name' => $burger->name,
            'quantity' => 1.00,
            'unit_price' => 100.00,
            'line_total' => 100.00,
            'is_inventory_tracked' => false
        ]]);

        $response = $this->postPayment($sale, 100.00);
        $response->assertStatus(200);

        // Verify Bun stock went negative
        $inventory = BranchInventory::where('product_id', $bun->id)->first();
        $this->assertEquals(-1.00, (float)$inventory->current_stock);

        // Verify variance log has parent product (Burger) and ingredient (Bun)
        $this->assertDatabaseHas('inventory_variance_logs', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => $burger->id,
            'ingredient_id' => $bun->id,
            'required_quantity' => 2.00,
            'available_quantity_before' => 1.00,
            'shortage_quantity' => 1.00,
            'resulting_quantity' => -1.00,
            'policy' => 'allow_negative_with_warning'
        ]);
    }

    public function test_dynamic_unit_conversion_rules_applied(): void
    {
        $this->branch->update(['inventory_deduction_policy' => 'strict_block']);

        $burger = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => false,
            'status' => 'active'
        ]);

        $sauce = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'unit_of_measure' => 'ml',
            'status' => 'active'
        ]);

        // Recipe: 1 Burger requires 2 Grams of sauce (unit mismatch with 'ml')
        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $burger->id,
            'ingredient_id' => $sauce->id,
            'quantity' => 2.00,
            'unit' => 'gram'
        ]);

        // Product-specific unit conversion: 1 gram = 1.5 ml
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $sauce->id,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 1.5000,
            'is_active' => true
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $sauce->id,
            'current_stock' => 10.00,
            'status' => 'active'
        ]);

        // Sell 2 Burgers (requires 2 * 2 * 1.5 = 6 ml of sauce)
        $sale = $this->createSale([[
            'product_id' => $burger->id,
            'product_name' => $burger->name,
            'quantity' => 2.00,
            'unit_price' => 100.00,
            'line_total' => 200.00,
            'is_inventory_tracked' => false
        ]]);

        $response = $this->postPayment($sale, 200.00);
        $response->assertStatus(200);

        // Verify stock is 10.0 - 6.0 = 4.0
        $inventory = BranchInventory::where('product_id', $sauce->id)->first();
        $this->assertEquals(4.00, (float)$inventory->current_stock);

        $movement = \App\Models\InventoryMovement::where('product_id', $sauce->id)->firstOrFail();
        $this->assertSame('gram', $movement->source_unit_id);
        $this->assertSame('ml', $movement->base_unit_id);
        $this->assertSame('direct', $movement->conversion_snapshot['conversion_path']);
        $this->assertSame('product_rule', $movement->conversion_snapshot['resolution_source']);
        $this->assertNotEmpty($movement->conversion_snapshot['conversion_rule_uuid']);
    }

    public function test_product_specific_conversion_override_precedence(): void
    {
        $this->branch->update(['inventory_deduction_policy' => 'strict_block']);

        $burger = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => false,
            'status' => 'active'
        ]);

        $sauce = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'unit_of_measure' => 'ml',
            'status' => 'active'
        ]);

        // Recipe: 1 Burger requires 2 Grams of sauce
        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $burger->id,
            'ingredient_id' => $sauce->id,
            'quantity' => 2.00,
            'unit' => 'gram'
        ]);

        // Global unit conversion: 1 gram = 1.5 ml
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => null,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 1.5000,
            'is_active' => true
        ]);

        // Product-specific unit conversion: 1 gram = 2.0 ml (for this sauce)
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $sauce->id,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 2.0000,
            'is_active' => true
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $sauce->id,
            'current_stock' => 10.00,
            'status' => 'active'
        ]);

        // Sell 2 Burgers (requires 2 * 2 * 2.0 = 8 ml of sauce)
        $sale = $this->createSale([[
            'product_id' => $burger->id,
            'product_name' => $burger->name,
            'quantity' => 2.00,
            'unit_price' => 100.00,
            'line_total' => 200.00,
            'is_inventory_tracked' => false
        ]]);

        $response = $this->postPayment($sale, 200.00);
        $response->assertStatus(200);

        // Verify stock is 10.0 - 8.0 = 2.0
        $inventory = BranchInventory::where('product_id', $sauce->id)->first();
        $this->assertEquals(2.00, (float)$inventory->current_stock);
    }

    public function test_inactive_conversion_ignored(): void
    {
        $this->branch->update(['inventory_deduction_policy' => 'strict_block']);

        $burger = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => false,
            'status' => 'active'
        ]);

        $sauce = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'unit_of_measure' => 'ml',
            'status' => 'active'
        ]);

        // Recipe: 1 Burger requires 2 Grams of sauce
        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $burger->id,
            'ingredient_id' => $sauce->id,
            'quantity' => 2.00,
            'unit' => 'gram'
        ]);

        // Product-specific unit conversion: 1 gram = 1.5 ml (INACTIVE)
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $sauce->id,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 1.5000,
            'is_active' => false
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $sauce->id,
            'current_stock' => 10.00,
            'status' => 'active'
        ]);

        // Sell 2 Burgers. Cross-dimension gram -> ml must fail closed when the only rule is inactive.
        $sale = $this->createSale([[
            'product_id' => $burger->id,
            'product_name' => $burger->name,
            'quantity' => 2.00,
            'unit_price' => 100.00,
            'line_total' => 200.00,
            'is_inventory_tracked' => false
        ]]);

        $response = $this->postPayment($sale, 200.00);
        $response->assertStatus(422);

        // Verify stock remains unchanged.
        $inventory = BranchInventory::where('product_id', $sauce->id)->first();
        $this->assertEquals(10.00, (float)$inventory->current_stock);
    }

    public function test_unknown_conversion_throws_and_fails(): void
    {
        $this->branch->update(['inventory_deduction_policy' => 'strict_block']);

        $burger = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => false,
            'status' => 'active'
        ]);

        $sauce = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true,
            'unit_of_measure' => 'ml',
            'status' => 'active'
        ]);

        // Recipe: 1 Burger requires 2 CustomUnit of sauce
        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $burger->id,
            'ingredient_id' => $sauce->id,
            'quantity' => 2.00,
            'unit' => 'CustomUnit'
        ]);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $sauce->id,
            'current_stock' => 10.00,
            'status' => 'active'
        ]);

        // Sell 2 Burgers (requires CustomUnit -> ml conversion which doesn't exist)
        $sale = $this->createSale([[
            'product_id' => $burger->id,
            'product_name' => $burger->name,
            'quantity' => 2.00,
            'unit_price' => 100.00,
            'line_total' => 200.00,
            'is_inventory_tracked' => false
        ]]);

        $response = $this->postPayment($sale, 200.00);
        $response->assertStatus(422); // blocked checkout
    }

    public function test_variance_logs_are_immutable_and_cannot_be_deleted(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'status' => 'active'
        ]);

        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => 100.00,
        ]);

        $log = InventoryVarianceLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => null,
            'ingredient_id' => $product->id,
            'required_quantity' => 5.00,
            'available_quantity_before' => 1.00,
            'shortage_quantity' => 4.00,
            'resulting_quantity' => -3.00,
            'unit' => 'piece',
            'policy' => 'allow_negative_with_warning',
            'reason' => 'POS Checkout stock shortage deduction.',
            'metadata' => ['test' => true],
        ]);

        $this->expectException(\RuntimeException::class);
        $log->update(['required_quantity' => 10.00]);
    }

    public function test_variance_logs_cannot_be_deleted(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'status' => 'active'
        ]);

        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => 100.00,
        ]);

        $log = InventoryVarianceLog::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $sale->id,
            'product_id' => null,
            'ingredient_id' => $product->id,
            'required_quantity' => 5.00,
            'available_quantity_before' => 1.00,
            'shortage_quantity' => 4.00,
            'resulting_quantity' => -3.00,
            'unit' => 'piece',
            'policy' => 'allow_negative_with_warning',
            'reason' => 'POS Checkout stock shortage deduction.',
            'metadata' => ['test' => true],
        ]);

        $this->expectException(\RuntimeException::class);
        $log->delete();
    }

    public function test_tenant_wide_conversion_uniqueness(): void
    {
        // First tenant-wide rule
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => null,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 1.00,
            'is_active' => true
        ]);

        // Creating a duplicate tenant-wide rule should throw Exception/QueryException
        $this->expectException(\Illuminate\Database\QueryException::class);
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => null,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 2.00,
            'is_active' => true
        ]);
    }

    public function test_product_specific_conversion_uniqueness(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'status' => 'active'
        ]);

        // First product-specific rule
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 1.00,
            'is_active' => true
        ]);

        // Creating a duplicate product-specific rule should throw Exception/QueryException
        $this->expectException(\Illuminate\Database\QueryException::class);
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 2.00,
            'is_active' => true
        ]);
    }

    public function test_conversions_can_coexist_if_different_products_or_null(): void
    {
        $product1 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'status' => 'active'
        ]);
        
        $product2 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'status' => 'active'
        ]);

        // 1. Tenant-wide rule
        $c1 = UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => null,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 1.00,
            'is_active' => true
        ]);

        // 2. Product 1 override rule
        $c2 = UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product1->id,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 2.00,
            'is_active' => true
        ]);

        // 3. Product 2 override rule
        $c3 = UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product2->id,
            'from_unit' => 'gram',
            'to_unit' => 'ml',
            'conversion_factor' => 3.00,
            'is_active' => true
        ]);

        $this->assertNotNull($c1);
        $this->assertNotNull($c2);
        $this->assertNotNull($c3);
    }
}
