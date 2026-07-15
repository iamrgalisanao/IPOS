<?php

namespace App\Services\StoreCredit;

use App\Models\CustomerFinancialAccount;
use App\Models\StoreCreditLedgerSequence;

class StoreCreditLedgerSequenceService
{
    public function allocate(CustomerFinancialAccount $account): int
    {
        $sequence = StoreCreditLedgerSequence::query()
            ->where('customer_financial_account_id', $account->id)
            ->lockForUpdate()
            ->first();

        if (!$sequence) {
            $sequence = StoreCreditLedgerSequence::create([
                'tenant_id' => $account->tenant_id,
                'customer_financial_account_id' => $account->id,
                'next_sequence' => 1,
            ]);
        }

        $allocated = (int) $sequence->next_sequence;

        $sequence->forceFill([
            'next_sequence' => $allocated + 1,
        ])->save();

        return $allocated;
    }
}
