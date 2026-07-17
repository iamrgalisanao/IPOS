<?php

namespace App\Services\POS\OfflineSync;

use App\Events\SalePaid;
use App\Jobs\POS\ProcessOfflineSyncConsequenceAttemptJob;
use App\Models\CustomerFinancialAccount;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncConsequenceAttempt;
use App\Models\Sale;

class OfflineLoyaltyConsequenceService
{
    public function validateAccountForSale(OfflineSalesImport $import, array $rawImport): array
    {
        $accountId = $rawImport['customer_financial_account_id'] ?? null;
        $expected = (bool) ($rawImport['loyalty_expected'] ?? false);

        if (!$accountId) {
            return [
                'customer_financial_account_id' => null,
                'status' => $expected ? 'review_required' : 'not_applicable',
                'reason' => $expected ? 'review_missing_expected_loyalty_account' : null,
            ];
        }

        $account = CustomerFinancialAccount::withoutGlobalScopes()
            ->whereKey($accountId)
            ->first();

        if (!$account || $account->tenant_id !== $import->tenant_id || $account->status !== CustomerFinancialAccount::STATUS_ACTIVE) {
            return [
                'customer_financial_account_id' => null,
                'status' => $expected ? 'review_required' : 'skipped_by_policy',
                'reason' => $expected ? 'review_invalid_expected_loyalty_account' : 'loyalty_account_invalid_optional',
            ];
        }

        return [
            'customer_financial_account_id' => $account->id,
            'status' => 'eligible',
            'reason' => null,
        ];
    }

    public function registerAttempt(OfflineSalesImport $import, Sale $sale, array $rawImport): ?OfflineSyncConsequenceAttempt
    {
        if (!$sale->customer_financial_account_id || $sale->is_training_mode) {
            return null;
        }

        $payload = SalePaid::fromSale($sale)->payload;
        $payload['offline_sales_import_id'] = $import->id;
        $payload['offline_transaction_uuid'] = $import->offline_transaction_uuid;
        $payload['resolved_business_date'] = optional($import->resolved_business_date)->toDateString()
            ?: now()->toDateString();
        $payload['offline_capture_timestamp'] = $rawImport['terminal_timestamp'] ?? $rawImport['submitted_at'] ?? null;
        $payload['accepted_at'] = now()->toISOString();
        $payload['earning_context_version'] = 'offline-loyalty-accrual-v1';

        $attempt = OfflineSyncConsequenceAttempt::firstOrCreate(
            [
                'offline_sales_import_id' => $import->id,
                'consequence_type' => OfflineSyncConsequenceAttempt::TYPE_LOYALTY_ACCRUAL,
                'idempotency_key' => "offline-loyalty-request:{$sale->id}:{$sale->customer_financial_account_id}",
            ],
            [
                'tenant_id' => $import->tenant_id,
                'branch_id' => $import->branch_id,
                'sale_id' => $sale->id,
                'status' => OfflineSyncConsequenceAttempt::STATUS_QUEUED,
                'attempt_no' => 0,
                'available_at' => now(),
                'metadata_json' => [
                    'payload' => $payload,
                    'final_ledger_idempotency_key_shape' => 'sale-accrual:{sale_id}:{account_id}:{rule_id}:{rule_version}',
                ],
            ]
        );

        ProcessOfflineSyncConsequenceAttemptJob::dispatch($attempt->id)->afterCommit();

        return $attempt;
    }
}
