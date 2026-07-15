<?php

namespace App\Services\Loyalty;

use App\Exceptions\Loyalty\LoyaltyLedgerAccountStateException;
use App\Exceptions\Loyalty\LoyaltyLedgerInsufficientBalanceException;
use App\Exceptions\Loyalty\LoyaltyRedemptionException;
use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;
use App\Models\LoyaltyRedemption;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

class LoyaltyCheckoutRedemptionCoordinator
{
    public function __construct(
        private readonly LoyaltyRuleService $ruleService,
        private readonly LoyaltyLedgerService $ledgerService,
        private readonly LoyaltyBalanceService $balanceService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function prepareForSaleCreation(
        string $tenantId,
        string $branchId,
        array $loyaltyRedemption,
        int $maxBenefitCentavos,
        bool $isTrainingMode,
        bool $hasStatutoryDiscount
    ): ?array {
        if (empty($loyaltyRedemption)) {
            return null;
        }

        if ($isTrainingMode) {
            throw new LoyaltyRedemptionException('Loyalty redemption is not allowed in training mode.');
        }

        if ($hasStatutoryDiscount) {
            throw new LoyaltyRedemptionException('Loyalty redemption cannot be combined with statutory discounts.');
        }

        $accountId = $loyaltyRedemption['customer_financial_account_id'] ?? null;
        if (!$accountId) {
            throw ValidationException::withMessages([
                'loyalty_redemption.customer_financial_account_id' => ['A customer financial account is required for loyalty redemption.'],
            ]);
        }

        $points = (int) ($loyaltyRedemption['points_to_redeem'] ?? 0);
        if ($points <= 0) {
            throw ValidationException::withMessages([
                'loyalty_redemption.points_to_redeem' => ['Loyalty redemption points must be greater than zero.'],
            ]);
        }

        $clientRequestUuid = trim((string) ($loyaltyRedemption['client_request_uuid'] ?? ''));
        if ($clientRequestUuid === '') {
            throw ValidationException::withMessages([
                'loyalty_redemption.client_request_uuid' => ['A loyalty redemption client request UUID is required.'],
            ]);
        }

        $account = CustomerFinancialAccount::query()->whereKey($accountId)->first();
        if (!$account || $account->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'loyalty_redemption.customer_financial_account_id' => ['The customer financial account is not available for this sale.'],
            ]);
        }

        if ($account->status !== CustomerFinancialAccount::STATUS_ACTIVE) {
            throw new LoyaltyLedgerAccountStateException('Loyalty account is not redeemable.');
        }

        $balance = $this->balanceService->availablePoints($account);
        if ($balance < $points) {
            throw new LoyaltyLedgerInsufficientBalanceException('The customer does not have enough available loyalty points for this redemption.');
        }

        $rule = $this->ruleService->activeRedemptionRule($loyaltyRedemption['redemption_rule_code'] ?? null, $branchId);
        $benefitCentavos = $this->ruleService->redemptionBenefitCentavos($points, $rule, $maxBenefitCentavos);
        if ($benefitCentavos <= 0) {
            throw new LoyaltyRedemptionException('Loyalty redemption does not produce a payable discount for this sale.');
        }

        return [
            'account' => $account,
            'rule' => $rule,
            'points' => $points,
            'benefit_centavos' => $benefitCentavos,
            'authorized_balance_points' => $balance,
            'client_request_uuid' => $clientRequestUuid,
            'rule_snapshot' => [
                'schema_version' => LoyaltyRedemption::SNAPSHOT_VERSION,
                'rule_id' => $rule->id,
                'code' => $rule->code,
                'rule_version' => $rule->rule_version,
                'configuration' => $rule->configuration,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $prepared
     */
    public function persistPending(Sale $sale, array $prepared, ?User $actor = null): LoyaltyRedemption
    {
        $account = $prepared['account'];
        $rule = $prepared['rule'];
        $idempotencyKey = 'loyalty-redemption:' . $sale->id . ':' . $prepared['client_request_uuid'];

        $redemption = LoyaltyRedemption::create([
            'tenant_id' => $sale->tenant_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'customer_financial_account_id' => $account->id,
            'loyalty_rule_id' => $rule->id,
            'points' => (int) $prepared['points'],
            'benefit_centavos' => (int) $prepared['benefit_centavos'],
            'authorized_balance_points' => (int) $prepared['authorized_balance_points'],
            'status' => LoyaltyRedemption::STATUS_PENDING,
            'idempotency_key' => $idempotencyKey,
            'rule_snapshot' => $prepared['rule_snapshot'],
            'source_snapshot' => $this->sourceSnapshot($sale, $prepared, $idempotencyKey),
            'authorized_at' => now(),
        ]);

        $this->auditLogger->log('LOYALTY_REDEMPTION_AUTHORIZED', $sale, null, [
            'loyalty_redemption_id' => $redemption->id,
            'customer_financial_account_id' => $account->id,
            'points' => $redemption->points,
            'benefit_centavos' => $redemption->benefit_centavos,
        ], metadata: ['event_version' => 1], actor: $actor);

        return $redemption;
    }

    public function finalizeForSale(Sale $sale, User $actor): ?LoyaltyRedemption
    {
        $redemption = LoyaltyRedemption::query()
            ->where('sale_id', $sale->id)
            ->lockForUpdate()
            ->first();

        if (!$redemption) {
            return null;
        }

        if ($redemption->status === LoyaltyRedemption::STATUS_REDEEMED) {
            return $redemption->load('ledgerEntry');
        }

        if ($redemption->status === LoyaltyRedemption::STATUS_FAILED) {
            throw new LoyaltyRedemptionException('Loyalty redemption has already failed for this sale.');
        }

        $account = CustomerFinancialAccount::query()
            ->whereKey($redemption->customer_financial_account_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($account->status !== CustomerFinancialAccount::STATUS_ACTIVE) {
            throw new LoyaltyLedgerAccountStateException('Loyalty account is not redeemable.');
        }

        if ($this->balanceService->availablePoints($account) < $redemption->points) {
            throw new LoyaltyLedgerInsufficientBalanceException('The customer does not have enough available loyalty points for this redemption.');
        }

        $ledgerEntry = $this->ledgerService->post($account, [
            'branch_id' => $sale->branch_id,
            'idempotency_key' => $redemption->idempotency_key,
            'entry_type' => LoyaltyLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            'points' => $redemption->points,
            'source_type' => 'loyalty_redemption',
            'source_id' => $redemption->id,
            'source_reference' => $sale->sale_number ?: $sale->id,
            'source_snapshot' => array_merge((array) $redemption->source_snapshot, [
                'redeemed_at' => now()->toISOString(),
                'sale_status_before_payment' => $sale->status,
            ]),
            'business_date' => now()->toDateString(),
        ], $actor);

        $redemption->forceFill([
            'loyalty_ledger_entry_id' => $ledgerEntry->id,
            'status' => LoyaltyRedemption::STATUS_REDEEMED,
            'redeemed_by' => $actor->id,
            'redeemed_at' => now(),
        ])->save();

        $this->auditLogger->log('LOYALTY_REDEEMED', $sale, null, [
            'loyalty_redemption_id' => $redemption->id,
            'loyalty_ledger_entry_id' => $ledgerEntry->id,
            'customer_financial_account_id' => $account->id,
            'points' => $redemption->points,
            'benefit_centavos' => $redemption->benefit_centavos,
        ], metadata: ['event_version' => 1], actor: $actor);

        return $redemption->load('ledgerEntry');
    }

    public function markFailedForSale(Sale $sale, string $reason): void
    {
        LoyaltyRedemption::query()
            ->where('sale_id', $sale->id)
            ->where('status', LoyaltyRedemption::STATUS_PENDING)
            ->update([
                'status' => LoyaltyRedemption::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => mb_substr($reason, 0, 255),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array<string,mixed> $prepared
     */
    private function sourceSnapshot(Sale $sale, array $prepared, string $idempotencyKey): array
    {
        return [
            'snapshot_version' => LoyaltyRedemption::SNAPSHOT_VERSION,
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'sale_total' => (string) $sale->total,
            'customer_financial_account_id' => $prepared['account']->id,
            'points' => (int) $prepared['points'],
            'benefit_centavos' => (int) $prepared['benefit_centavos'],
            'authorized_balance_points' => (int) $prepared['authorized_balance_points'],
            'rule_snapshot' => $prepared['rule_snapshot'],
            'idempotency_key_fingerprint' => hash('sha256', $idempotencyKey),
            'future_reversal_source_reference' => $sale->id,
            'authorized_at' => now()->toISOString(),
        ];
    }
}
