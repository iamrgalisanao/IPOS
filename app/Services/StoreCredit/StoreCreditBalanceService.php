<?php

namespace App\Services\StoreCredit;

use App\Models\CustomerFinancialAccount;
use App\Models\StoreCreditLedgerEntry;

class StoreCreditBalanceService
{
    public function availableBalanceCentavos(CustomerFinancialAccount $account): int
    {
        $entries = StoreCreditLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->orderBy('ledger_sequence')
            ->get(['direction', 'amount_centavos']);

        return $entries->reduce(function (int $balance, StoreCreditLedgerEntry $entry): int {
            return match ($entry->direction) {
                StoreCreditLedgerEntry::DIRECTION_CREDIT => $balance + $entry->amount_centavos,
                StoreCreditLedgerEntry::DIRECTION_DEBIT => $balance - $entry->amount_centavos,
                default => $balance,
            };
        }, 0);
    }
}
