<?php

namespace App\Services\Loyalty;

use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;

class LoyaltyBalanceService
{
    public function availablePoints(CustomerFinancialAccount $account): int
    {
        $entries = LoyaltyLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->orderBy('ledger_sequence')
            ->get(['direction', 'points']);

        return $entries->reduce(function (int $balance, LoyaltyLedgerEntry $entry): int {
            return match ($entry->direction) {
                LoyaltyLedgerEntry::DIRECTION_CREDIT => $balance + $entry->points,
                LoyaltyLedgerEntry::DIRECTION_DEBIT => $balance - $entry->points,
                default => $balance,
            };
        }, 0);
    }
}
