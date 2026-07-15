<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerFinancialAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerFinancialAccount> */
class CustomerFinancialAccountFactory extends Factory
{
    protected $model = CustomerFinancialAccount::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);
        $tenantId = $tenantContext->hasTenant()
            ? $tenantContext->getTenantId()
            : Tenant::factory();

        return [
            'tenant_id' => $tenantId,
            'customer_id' => Customer::factory()->state(['tenant_id' => $tenantId]),
            'status' => CustomerFinancialAccount::STATUS_ACTIVE,
            'currency_code' => 'PHP',
            'opened_at' => now(),
        ];
    }
}
