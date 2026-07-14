<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\ServiceArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiningTable> */
class DiningTableFactory extends Factory
{
    protected $model = DiningTable::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : \App\Models\Tenant::factory(),
            'branch_id' => Branch::factory(),
            'service_area_id' => ServiceArea::factory(),
            'table_number' => (string) $this->faker->unique()->numberBetween(1, 200),
            'capacity' => $this->faker->numberBetween(1, 8),
            'operational_state' => DiningTable::STATE_AVAILABLE,
            'position_metadata' => DiningTable::DEFAULT_POSITION_METADATA,
            'is_active' => true,
        ];
    }
}
