<?php

namespace App\Jobs\Loyalty;

use App\Events\SalePaid;
use App\Services\Loyalty\LoyaltyAccrualService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AccrueLoyaltyForSaleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly array $salePaidPayload)
    {
    }

    public function handle(LoyaltyAccrualService $service): void
    {
        $service->accrueFromSalePaid(new SalePaid($this->salePaidPayload));
    }
}
