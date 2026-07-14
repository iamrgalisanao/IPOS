<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DiningTicket;
use App\Models\DiningTicketItem;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiningTicketItem> */
class DiningTicketItemFactory extends Factory
{
    protected $model = DiningTicketItem::class;

    public function definition(): array
    {
        $tenantContext = app(\App\Services\TenantContext::class);

        return [
            'tenant_id' => $tenantContext->hasTenant()
                ? $tenantContext->getTenantId()
                : Tenant::factory(),
            'branch_id' => Branch::factory(),
            'dining_ticket_id' => DiningTicket::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'unit_price_centavos' => 0,
            'line_total_centavos' => 0,
            'status' => DiningTicketItem::STATUS_OPEN,
        ];
    }
}
