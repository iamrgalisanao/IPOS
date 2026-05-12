<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant() 
                ? $tenantContext->getTenantId() 
                : \App\Models\Tenant::factory(),
            'name' => $this->faker->city() . ' Branch',
            'branch_code' => strtoupper($this->faker->lexify('???-###')),
            'status' => 'active',
        ];
    }
}
