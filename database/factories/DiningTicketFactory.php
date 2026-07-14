<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DiningTicket;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DiningTicket> */
class DiningTicketFactory extends Factory
{
    protected $model = DiningTicket::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : Tenant::factory(),
            'branch_id' => Branch::factory(),
            'ticket_number' => 'DT-' . now()->format('Ymd') . '-' . $this->faker->unique()->numerify('######'),
            'status' => DiningTicket::STATUS_OPEN,
            'guest_count' => 1,
            'subtotal_centavos' => 0,
            'discount_centavos' => 0,
            'service_charge_centavos' => 0,
            'tax_centavos' => 0,
            'grand_total_centavos' => 0,
            'opened_by' => User::factory(),
            'opened_at' => now(),
            'terminal_id' => SalesMachineProfile::factory(),
            'ticket_revision' => 1,
            'client_request_uuid' => (string) Str::uuid(),
            'client_request_fingerprint' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
