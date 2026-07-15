<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SalePaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly array $payload)
    {
    }

    public static function fromSale(Sale $sale): self
    {
        return new self([
            'event_version' => 1,
            'sale_id' => $sale->id,
            'tenant_id' => $sale->tenant_id,
            'branch_id' => $sale->branch_id,
            'customer_financial_account_id' => $sale->customer_financial_account_id,
            'sale_number' => $sale->sale_number,
            'total_centavos' => (int) round(((float) $sale->total) * 100),
            'discount_total_centavos' => (int) round(((float) $sale->discount_total) * 100),
            'is_training_mode' => (bool) $sale->is_training_mode,
            'paid_at' => now()->toISOString(),
        ]);
    }
}
