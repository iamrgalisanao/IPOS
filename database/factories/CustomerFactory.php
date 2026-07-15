<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);
        $name = $this->faker->name();

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : Tenant::factory(),
            'display_name' => $name,
            'normalized_display_name' => Str::of($name)->lower()->squish()->toString(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->numerify('+639#########'),
            'external_reference' => null,
            'status' => Customer::STATUS_ACTIVE,
            'metadata' => null,
        ];
    }
}
