<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : Tenant::factory(),
            'name' => $this->faker->word(),
            'code' => strtoupper($this->faker->unique()->bothify('CAT-??????')),
            'status' => 'active',
        ];
    }
}
