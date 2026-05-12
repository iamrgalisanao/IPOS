<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);
        $tenantId = $tenantContext->hasTenant() ? $tenantContext->getTenantId() : null;

        return [
            'tenant_id' => $tenantId,
            'product_category_id' => fn(array $attributes) => ProductCategory::factory()->create([
                'tenant_id' => $attributes['tenant_id'] ?? $tenantId ?? Tenant::factory(),
            ])->id,
            'name' => $this->faker->word(),
            'sku' => $this->faker->unique()->bothify('SKU-####'),
            'selling_price' => 100.00,
            'cost_price' => 50.00,
            'is_inventory_tracked' => true,
            'status' => 'active',
        ];
    }
}
