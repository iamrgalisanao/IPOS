<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchInventoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'current_stock' => $this->faker->randomFloat(4, 0, 1000),
            'reorder_level' => $this->faker->randomFloat(4, 0, 50),
            'status' => 'active',
        ];
    }
}
