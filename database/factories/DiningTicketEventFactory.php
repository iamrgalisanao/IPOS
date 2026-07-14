<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DiningTicket;
use App\Models\DiningTicketEvent;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DiningTicketEvent> */
class DiningTicketEventFactory extends Factory
{
    protected $model = DiningTicketEvent::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : Tenant::factory(),
            'branch_id' => Branch::factory(),
            'dining_ticket_id' => DiningTicket::factory(),
            'event_uuid' => (string) Str::uuid(),
            'event_sequence' => 1,
            'event_type' => 'opened',
            'summary' => 'Ticket opened.',
            'payload' => ['schema_version' => 1],
            'actor_user_id' => User::factory(),
            'terminal_id' => SalesMachineProfile::factory(),
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }
}
