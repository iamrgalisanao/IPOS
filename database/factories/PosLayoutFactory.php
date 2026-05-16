<?php

namespace Database\Factories;

use App\Models\PosLayout;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PosLayoutFactory extends Factory
{
    protected $model = PosLayout::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->words(3, true),
            'schema' => [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => []
            ],
            'status' => 'draft',
            'version' => 1,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
