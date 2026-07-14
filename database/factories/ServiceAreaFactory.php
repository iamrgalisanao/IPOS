<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ServiceArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ServiceArea> */
class ServiceAreaFactory extends Factory
{
    protected $model = ServiceArea::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);
        $name = $this->faker->unique()->words(2, true);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : \App\Models\Tenant::factory(),
            'branch_id' => Branch::factory(),
            'name' => $name,
            'normalized_name' => Str::of($name)->lower()->squish()->toString(),
            'layout_metadata' => ServiceArea::DEFAULT_LAYOUT_METADATA,
            'layout_revision' => 1,
            'is_active' => true,
        ];
    }
}
