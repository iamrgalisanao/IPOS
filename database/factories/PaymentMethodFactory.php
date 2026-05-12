<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : Tenant::factory(),
            'code' => strtoupper($this->faker->lexify('PAY-???')),
            'name' => $this->faker->word(),
            'type' => 'cash',
            'status' => 'active',
        ];
    }
}
