<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);
        $branchContext = app(\App\Services\BranchContext::class);
        $tenantId = $tenantContext->hasTenant() ? $tenantContext->getTenantId() : Tenant::factory();

        return [
            'tenant_id' => $tenantId,
            'branch_id' => $branchContext->hasBranch()
                ? $branchContext->getBranchId()
                : Branch::factory(),
            'user_id' => User::factory(),
            'client_request_uuid' => (string) Str::uuid(),
            'status' => 'created',
            'subtotal' => 0,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 0,
        ];
    }
}
