<?php

namespace App\Services\Loyalty;

use App\Events\SalePaid;
use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;

class LoyaltyAccrualService
{
    public function __construct(
        private readonly LoyaltyRuleService $ruleService,
        private readonly LoyaltyLedgerService $ledgerService,
    ) {
    }

    public function accrueFromSalePaid(SalePaid $event): ?LoyaltyLedgerEntry
    {
        $payload = $event->payload;

        if (($payload['is_training_mode'] ?? false) || empty($payload['customer_financial_account_id'])) {
            return null;
        }

        $account = CustomerFinancialAccount::query()
            ->whereKey($payload['customer_financial_account_id'])
            ->first();

        if (!$account || $account->tenant_id !== ($payload['tenant_id'] ?? null)) {
            return null;
        }

        if ($account->status !== CustomerFinancialAccount::STATUS_ACTIVE) {
            return null;
        }

        $rule = $this->ruleService->activeEarningRule($payload['branch_id'] ?? null);
        $eligibleCentavos = max(0, (int) ($payload['total_centavos'] ?? 0));
        $points = $this->ruleService->earningPoints($eligibleCentavos, $rule);

        if ($points <= 0) {
            return null;
        }

        $idempotencyKey = sprintf(
            'sale-accrual:%s:%s:%s:%s',
            $payload['sale_id'],
            $account->id,
            $rule->id,
            $rule->rule_version
        );

        return $this->ledgerService->post($account, [
            'branch_id' => $payload['branch_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'entry_type' => LoyaltyLedgerEntry::TYPE_SALE_ACCRUAL,
            'direction' => LoyaltyLedgerEntry::DIRECTION_CREDIT,
            'points' => $points,
            'source_type' => 'sale',
            'source_id' => $payload['sale_id'],
            'source_reference' => $payload['sale_number'] ?? $payload['sale_id'],
            'source_snapshot' => [
                'event_version' => $payload['event_version'] ?? 1,
                'sale_paid_payload' => $payload,
                'rule_snapshot' => [
                    'rule_id' => $rule->id,
                    'code' => $rule->code,
                    'rule_version' => $rule->rule_version,
                    'configuration' => $rule->configuration,
                ],
                'points' => $points,
                'eligible_amount_centavos' => $eligibleCentavos,
            ],
            'business_date' => $payload['resolved_business_date'] ?? $payload['business_date'] ?? now()->toDateString(),
        ]);
    }
}
