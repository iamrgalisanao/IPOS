<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DiningTicket;
use App\Models\DiningTicketVersion;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiningTicketVersion> */
class DiningTicketVersionFactory extends Factory
{
    protected $model = DiningTicketVersion::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : Tenant::factory(),
            'branch_id' => Branch::factory(),
            'dining_ticket_id' => DiningTicket::factory(),
            'version' => 1,
            'operation' => 'opened',
            'actor_user_id' => User::factory(),
            'terminal_id' => SalesMachineProfile::factory(),
            'after_snapshot' => ['schema_version' => 1],
            'metadata' => ['schema_version' => 1],
            'created_at' => now(),
        ];
    }
}
