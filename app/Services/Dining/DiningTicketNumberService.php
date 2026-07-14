<?php

namespace App\Services\Dining;

use App\Models\Branch;
use App\Models\DiningTicketSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DiningTicketNumberService
{
    public function nextForBranch(string $tenantId, string $branchId, ?CarbonInterface $businessDate = null): string
    {
        $branch = Branch::query()->whereKey($branchId)->first();
        $date = ($businessDate ?? now($branch?->getTimezone()))->toDateString();

        return DB::transaction(function () use ($tenantId, $branchId, $date) {
            DB::table('dining_ticket_sequences')->upsert([[
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'business_date' => $date,
                'next_sequence' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['tenant_id', 'branch_id', 'business_date'], ['updated_at']);

            $sequence = DiningTicketSequence::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->whereDate('business_date', $date)
                ->lockForUpdate()
                ->firstOrFail();

            $value = $sequence->next_sequence;
            $sequence->next_sequence = $value + 1;
            $sequence->save();

            return sprintf('DT-%s-%06d', str_replace('-', '', $date), $value);
        });
    }
}
