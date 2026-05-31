<?php

namespace Tests\Feature\Inventory;

use App\Jobs\Inventory\ProcessSaleInventoryDeductionJob;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TaxCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessSaleInventoryDeductionJobTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected Sale $sale;
    protected Product $compositeProduct;
    protected Product $ingredientProduct;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $taxCategory = TaxCategory::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT',
            'code' => 'VAT',
            'tax_type' => 'vatable',
            'rate' => 12.00,
            'status' => 'active',
        ]);

        // Create ingredient
        $this->ingredientProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'tax_category_id' => $taxCategory->id,
            'is_inventory_tracked' => true,
            'unit_of_measure' => 'grams',
        ]);

        BranchInventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->ingredientProduct->id,
            'current_stock' => 1000,
        ]);

        // Create composite product
        $this->compositeProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'tax_category_id' => $taxCategory->id,
            'is_inventory_tracked' => false,
            'unit_of_measure' => 'piece',
            'product_type' => 'composite',
        ]);

        ProductRecipe::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->compositeProduct->id,
            'ingredient_id' => $this->ingredientProduct->id,
            'quantity' => 100, // 100 grams per piece
            'unit' => 'grams',
        ]);

        // Create sale
        $this->sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => 150.0000,
            'status' => 'paid',
        ]);

        SaleItem::create([
            'id'                   => Str::uuid()->toString(),
            'tenant_id'            => $this->tenant->id,
            'branch_id'            => $this->branch->id,
            'sale_id'              => $this->sale->id,
            'product_id'           => $this->compositeProduct->id,
            'product_name'         => $this->compositeProduct->name,
            'sku'                  => $this->compositeProduct->sku,
            'barcode'              => $this->compositeProduct->barcode,
            'unit_of_measure'      => 'piece',
            'quantity'             => 2,
            'unit_price'           => 75.0000,
            'subtotal'             => 150.0000,
            'discount_amount'      => 0.0000,
            'tax_category_id'      => $taxCategory->id,
            'tax_type'             => 'vatable',
            'tax_bucket'           => SaleItem::TAX_BUCKET_VATABLE,
            'tax_rate'             => 12.0000,
            'tax_amount'           => 16.0714,
            'net_amount'           => 133.9286,
            'vatable_amount'       => 133.9286,
            'vat_exempt_amount'    => 0.0000,
            'zero_rated_amount'    => 0.0000,
            'non_vat_amount'       => 0.0000,
            'tax_source'           => SaleItem::TAX_SOURCE_SYSTEM,
            'tax_snapshot'         => json_encode([]),
            'line_total'           => 150.0000,
            'is_inventory_tracked' => false,
            'created_at'           => now(),
        ]);
    }

    public function test_it_deducts_inventory_for_sale_via_job()
    {
        $this->assertEquals(1000, BranchInventory::where('product_id', $this->ingredientProduct->id)->first()->current_stock);

        $job = new ProcessSaleInventoryDeductionJob($this->sale->id);
        $job->handle(
            app(\App\Services\InventoryService::class),
            app(TenantContext::class),
            app(\App\Services\BranchContext::class)
        );

        app(TenantContext::class)->setTenant($this->tenant); // Restore context
        app(\App\Services\BranchContext::class)->setBranch($this->branch);

        // 2 pieces * 100 grams = 200 grams deducted
        $this->assertEquals(800, BranchInventory::where('product_id', $this->ingredientProduct->id)->first()->current_stock);
        
        $this->assertEquals(1, \DB::table('inventory_movements')->count());
    }

    public function test_it_is_idempotent()
    {
        $job = new ProcessSaleInventoryDeductionJob($this->sale->id);
        $job->handle(
            app(\App\Services\InventoryService::class),
            app(TenantContext::class),
            app(\App\Services\BranchContext::class)
        );

        app(TenantContext::class)->setTenant($this->tenant); // Restore context
        app(\App\Services\BranchContext::class)->setBranch($this->branch);

        $this->assertEquals(800, BranchInventory::where('product_id', $this->ingredientProduct->id)->first()->current_stock);
        $this->assertEquals(1, \DB::table('inventory_movements')->count());

        // Dispatch again
        $job->handle(
            app(\App\Services\InventoryService::class),
            app(TenantContext::class),
            app(\App\Services\BranchContext::class)
        );

        app(TenantContext::class)->setTenant($this->tenant); // Restore context
        app(\App\Services\BranchContext::class)->setBranch($this->branch);

        // Should not deduct twice
        $this->assertEquals(800, BranchInventory::where('product_id', $this->ingredientProduct->id)->first()->current_stock);
        $this->assertEquals(1, \DB::table('inventory_movements')->count());
    }

    public function test_it_handles_missing_sale_gracefully()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $job = new ProcessSaleInventoryDeductionJob(Str::uuid()->toString());
        $job->handle(
            app(\App\Services\InventoryService::class),
            app(TenantContext::class),
            app(\App\Services\BranchContext::class)
        );
    }
}
