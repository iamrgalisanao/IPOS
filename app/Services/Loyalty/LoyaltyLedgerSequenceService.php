<?php

namespace App\Services\Loyalty;

use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerSequence;

class LoyaltyLedgerSequenceService
{
    public function allocate(CustomerFinancialAccount $account): int
    {
        $sequence = LoyaltyLedgerSequence::query()
            ->where('customer_financial_account_id', $account->id)
            ->lockForUpdate()
            ->first();

        if (!$sequence) {
            $sequence = LoyaltyLedgerSequence::create([
                'tenant_id' => $account->tenant_id,
                'customer_financial_account_id' => $account->id,
                'next_sequence' => 1,
            ]);
        }

        $allocated = (int) $sequence->next_sequence;

        $sequence->forceFill(['next_sequence' => $allocated + 1])->save();

        return $allocated;
    }
}
