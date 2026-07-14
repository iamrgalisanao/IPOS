<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningTicket;
use App\Models\DiningTicketTable;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiningTicketTable> */
class DiningTicketTableFactory extends Factory
{
    protected $model = DiningTicketTable::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : Tenant::factory(),
            'branch_id' => Branch::factory(),
            'dining_ticket_id' => DiningTicket::factory(),
            'dining_table_id' => DiningTable::factory(),
            'role' => DiningTicketTable::ROLE_PRIMARY,
            'attached_at' => now(),
            'detached_at' => null,
        ];
    }
}
