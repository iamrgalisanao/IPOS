<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesMachineProfile> */
class SalesMachineProfileFactory extends Factory
{
    protected $model = SalesMachineProfile::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : Tenant::factory(),
            'branch_id' => Branch::factory(),
            'profile_code' => strtoupper($this->faker->unique()->bothify('REG-###')),
            'terminal_identifier' => strtoupper($this->faker->unique()->bothify('TERM-###')),
            'status' => 'active',
            'activation_status' => SalesMachineProfile::STATUS_ACTIVE,
        ];
    }
}
