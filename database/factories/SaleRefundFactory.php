<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SaleRefund> */
class SaleRefundFactory extends Factory
{
    protected $model = SaleRefund::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);
        $tenantId = $tenantContext->hasTenant()
            ? $tenantContext->getTenantId()
            : Tenant::factory();

        return [
            'tenant_id' => $tenantId,
            'branch_id' => Branch::factory()->state(['tenant_id' => $tenantId]),
            'sale_id' => Sale::factory()->state(['tenant_id' => $tenantId]),
            'shift_id' => null,
            'reason_code' => 'RETURN',
            'reason_notes' => null,
            'refund_total' => 10.00,
            'refunded_by' => User::factory()->state(['tenant_id' => $tenantId]),
            'refunded_at' => now(),
        ];
    }
}
