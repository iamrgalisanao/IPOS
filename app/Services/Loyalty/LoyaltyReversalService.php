<?php

namespace App\Services\Loyalty;

use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReversalSetting;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleRefundItem;
use App\Models\SaleVoid;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use RuntimeException;

class LoyaltyReversalService
{
    public function __construct(
        private readonly LoyaltyLedgerService $ledgerService,
        private readonly LoyaltyBalanceService $balanceService,
        private readonly LoyaltyReversalSettingService $settingService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function reverseForVoid(Sale $sale, SaleVoid $void, ?User $actor = null): array
    {
        $account = $this->accountForSale($sale);
        if (!$account) {
            return $this->emptyResult(['no_customer_financial_account']);
        }

        $settings = $this->settingService->forCurrentTenant();
        $result = $this->emptyResult();

        if ($settings->restore_redeemed_on_void) {
            foreach ($this->redeemedRedemptionsForSale($sale) as $redemption) {
                $entry = $this->postRedemptionRestore(
                    $account,
                    $sale,
                    $redemption,
                    LoyaltyLedgerEntry::TYPE_VOID_REDEMPTION_RESTORE,
                    'sale_void',
                    $void->id,
                    "void-redemption-restore:{$void->id}:{$redemption->id}",
                    $this->baseVoidSnapshot($sale, $void, $settings) + [
                        'reversal_type' => LoyaltyLedgerEntry::TYPE_VOID_REDEMPTION_RESTORE,
                        'loyalty_redemption_id' => $redemption->id,
                        'original_loyalty_ledger_entry_id' => $redemption->loyalty_ledger_entry_id,
                        'original_loyalty_points' => $redemption->points,
                    ],
                    'LOYALTY_VOID_REDEMPTION_RESTORE_POSTED',
                    $actor
                );

                $result['redemption_restore_entry_ids'][] = $entry->id;
            }
        }

        if ($settings->reverse_earned_on_void) {
            foreach ($this->accrualEntriesForSale($sale, $account) as $accrual) {
                $entry = $this->postEarnReversal(
                    $account,
                    $sale,
                    $accrual,
                    $accrual->points,
                    LoyaltyLedgerEntry::TYPE_VOID_EARN_REVERSAL,
                    'sale_void',
                    $void->id,
                    "void-earn-reversal:{$void->id}:{$accrual->id}",
                    $this->baseVoidSnapshot($sale, $void, $settings) + [
                        'reversal_type' => LoyaltyLedgerEntry::TYPE_VOID_EARN_REVERSAL,
                        'original_loyalty_ledger_entry_id' => $accrual->id,
                        'original_loyalty_entry_type' => $accrual->entry_type,
                        'original_loyalty_points' => $accrual->points,
                        'current_reversal_points' => $accrual->points,
                        'cumulative_reversed_points' => $accrual->points,
                    ],
                    'LOYALTY_VOID_EARN_REVERSAL_POSTED',
                    $settings,
                    $actor
                );

                $result['earned_reversal_entry_ids'][] = $entry->id;
                $result['negative_balance_created'] = $result['negative_balance_created']
                    || (($entry->source_snapshot['balance_after'] ?? 0) < 0);
            }
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function reverseForRefund(Sale $sale, SaleRefund $refund, Collection|array $refundItems, ?User $actor = null): array
    {
        $account = $this->accountForSale($sale);
        if (!$account) {
            return $this->emptyResult(['no_customer_financial_account']);
        }

        $settings = $this->settingService->forCurrentTenant();
        $result = $this->emptyResult();
        $refundItems = collect($refundItems);
        $cumulativeRefundCentavos = $this->cumulativeRefundCentavos($sale);
        $saleTotalCentavos = $this->saleTotalCentavos($sale);
        $scopeHash = $this->refundScopeHash($refund, $refundItems, $settings);

        if ($settings->restore_redeemed_on_refund) {
            foreach ($this->redeemedRedemptionsForSale($sale) as $redemption) {
                $expectedRestore = $this->expectedRefundRestorePoints($redemption, $settings, $cumulativeRefundCentavos, $saleTotalCentavos);
                $alreadyRestored = $this->postedPointsForOriginal(
                    $account,
                    LoyaltyLedgerEntry::TYPE_REFUND_REDEMPTION_RESTORE,
                    $redemption->loyalty_ledger_entry_id
                );
                $points = max(0, $expectedRestore - $alreadyRestored);

                if ($points <= 0) {
                    continue;
                }

                $entry = $this->postRedemptionRestore(
                    $account,
                    $sale,
                    $redemption,
                    LoyaltyLedgerEntry::TYPE_REFUND_REDEMPTION_RESTORE,
                    'sale_refund',
                    $refund->id,
                    "refund-redemption-restore:{$refund->id}:{$redemption->id}:{$scopeHash}",
                    $this->baseRefundSnapshot($sale, $refund, $refundItems, $settings, $cumulativeRefundCentavos) + [
                        'reversal_type' => LoyaltyLedgerEntry::TYPE_REFUND_REDEMPTION_RESTORE,
                        'loyalty_redemption_id' => $redemption->id,
                        'original_loyalty_ledger_entry_id' => $redemption->loyalty_ledger_entry_id,
                        'original_loyalty_points' => $redemption->points,
                        'current_restoration_points' => $points,
                        'cumulative_restored_points' => $alreadyRestored + $points,
                    ],
                    'LOYALTY_REFUND_REDEMPTION_RESTORE_POSTED',
                    $actor,
                    $points
                );

                $result['redemption_restore_entry_ids'][] = $entry->id;
            }
        }

        if ($settings->reverse_earned_on_refund) {
            foreach ($this->accrualEntriesForSale($sale, $account) as $accrual) {
                $expectedReversal = $this->expectedRefundReversalPoints($accrual, $cumulativeRefundCentavos, $saleTotalCentavos);
                $alreadyReversed = $this->postedPointsForOriginal(
                    $account,
                    LoyaltyLedgerEntry::TYPE_REFUND_EARN_REVERSAL,
                    $accrual->id
                );
                $points = max(0, $expectedReversal - $alreadyReversed);

                if ($points <= 0) {
                    continue;
                }

                $entry = $this->postEarnReversal(
                    $account,
                    $sale,
                    $accrual,
                    $points,
                    LoyaltyLedgerEntry::TYPE_REFUND_EARN_REVERSAL,
                    'sale_refund',
                    $refund->id,
                    "refund-earn-reversal:{$refund->id}:{$accrual->id}:{$scopeHash}",
                    $this->baseRefundSnapshot($sale, $refund, $refundItems, $settings, $cumulativeRefundCentavos) + [
                        'reversal_type' => LoyaltyLedgerEntry::TYPE_REFUND_EARN_REVERSAL,
                        'original_loyalty_ledger_entry_id' => $accrual->id,
                        'original_loyalty_entry_type' => $accrual->entry_type,
                        'original_loyalty_points' => $accrual->points,
                        'current_reversal_points' => $points,
                        'cumulative_reversed_points' => $alreadyReversed + $points,
                    ],
                    'LOYALTY_REFUND_EARN_REVERSAL_POSTED',
                    $settings,
                    $actor
                );

                $result['earned_reversal_entry_ids'][] = $entry->id;
                $result['negative_balance_created'] = $result['negative_balance_created']
                    || (($entry->source_snapshot['balance_after'] ?? 0) < 0);
            }
        }

        return $result;
    }

    private function accountForSale(Sale $sale): ?CustomerFinancialAccount
    {
        if ($sale->is_training_mode || !$sale->customer_financial_account_id) {
            return null;
        }

        return CustomerFinancialAccount::query()
            ->whereKey($sale->customer_financial_account_id)
            ->where('tenant_id', $sale->tenant_id)
            ->first();
    }

    /**
     * @return Collection<int,LoyaltyLedgerEntry>
     */
    private function accrualEntriesForSale(Sale $sale, CustomerFinancialAccount $account): Collection
    {
        return LoyaltyLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->where('entry_type', LoyaltyLedgerEntry::TYPE_SALE_ACCRUAL)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->orderBy('ledger_sequence')
            ->get();
    }

    /**
     * @return Collection<int,LoyaltyRedemption>
     */
    private function redeemedRedemptionsForSale(Sale $sale): Collection
    {
        return LoyaltyRedemption::query()
            ->where('sale_id', $sale->id)
            ->where('status', LoyaltyRedemption::STATUS_REDEEMED)
            ->whereNotNull('loyalty_ledger_entry_id')
            ->orderBy('redeemed_at')
            ->get();
    }

    private function postEarnReversal(
        CustomerFinancialAccount $account,
        Sale $sale,
        LoyaltyLedgerEntry $original,
        int $points,
        string $entryType,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
        array $snapshot,
        string $auditAction,
        LoyaltyReversalSetting $settings,
        ?User $actor
    ): LoyaltyLedgerEntry {
        $balanceBefore = $this->balanceService->availablePoints($account);
        $balanceAfter = $balanceBefore - $points;

        if ($balanceAfter < 0) {
            if (!$settings->allow_negative_balance || $settings->require_approval_for_negative_balance) {
                throw new RuntimeException('Mandatory loyalty reversal would create a negative balance without an approved pending reversal workflow.');
            }
        }

        $entry = $this->ledgerService->post($account, [
            'branch_id' => $sale->branch_id,
            'idempotency_key' => $idempotencyKey,
            'entry_type' => $entryType,
            'direction' => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            'points' => $points,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_reference' => $sale->sale_number ?: $sale->id,
            'source_snapshot' => $snapshot + [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'negative_balance_policy' => [
                    'allow_negative_balance' => $settings->allow_negative_balance,
                    'require_approval_for_negative_balance' => $settings->require_approval_for_negative_balance,
                    'settings_schema_version' => $settings->settings_schema_version,
                ],
            ],
            'business_date' => now()->toDateString(),
            'allow_negative_balance' => $balanceAfter < 0 && $settings->allow_negative_balance,
        ], $actor);

        $this->auditReversal($auditAction, $entry, $original->id, $points, $actor);

        return $entry;
    }

    private function postRedemptionRestore(
        CustomerFinancialAccount $account,
        Sale $sale,
        LoyaltyRedemption $redemption,
        string $entryType,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
        array $snapshot,
        string $auditAction,
        ?User $actor,
        ?int $points = null
    ): LoyaltyLedgerEntry {
        $points ??= (int) $redemption->points;
        $balanceBefore = $this->balanceService->availablePoints($account);
        $balanceAfter = $balanceBefore + $points;

        $entry = $this->ledgerService->post($account, [
            'branch_id' => $sale->branch_id,
            'idempotency_key' => $idempotencyKey,
            'entry_type' => $entryType,
            'direction' => LoyaltyLedgerEntry::DIRECTION_CREDIT,
            'points' => $points,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_reference' => $sale->sale_number ?: $sale->id,
            'source_snapshot' => $snapshot + [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ],
            'business_date' => now()->toDateString(),
        ], $actor);

        $this->auditReversal($auditAction, $entry, $redemption->loyalty_ledger_entry_id, $points, $actor);

        return $entry;
    }

    private function postedPointsForOriginal(CustomerFinancialAccount $account, string $entryType, ?string $originalEntryId): int
    {
        if (!$originalEntryId) {
            return 0;
        }

        return (int) LoyaltyLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->where('entry_type', $entryType)
            ->where('source_snapshot->original_loyalty_ledger_entry_id', $originalEntryId)
            ->sum('points');
    }

    private function expectedRefundReversalPoints(LoyaltyLedgerEntry $accrual, int $cumulativeRefundCentavos, int $saleTotalCentavos): int
    {
        if ($saleTotalCentavos <= 0) {
            return 0;
        }

        if ($cumulativeRefundCentavos >= $saleTotalCentavos) {
            return $accrual->points;
        }

        return min($accrual->points, (int) round($accrual->points * ($cumulativeRefundCentavos / $saleTotalCentavos)));
    }

    private function expectedRefundRestorePoints(
        LoyaltyRedemption $redemption,
        LoyaltyReversalSetting $settings,
        int $cumulativeRefundCentavos,
        int $saleTotalCentavos
    ): int {
        if ($settings->restore_redeemed_on_partial_refund_policy === LoyaltyReversalSetting::PARTIAL_RESTORE_NONE) {
            return 0;
        }

        if ($saleTotalCentavos <= 0) {
            return 0;
        }

        if ($cumulativeRefundCentavos >= $saleTotalCentavos) {
            return $redemption->points;
        }

        if ($settings->restore_redeemed_on_partial_refund_policy === LoyaltyReversalSetting::PARTIAL_RESTORE_FULL_WHEN_FULLY_REFUNDED) {
            return 0;
        }

        return min($redemption->points, (int) round($redemption->points * ($cumulativeRefundCentavos / $saleTotalCentavos)));
    }

    private function cumulativeRefundCentavos(Sale $sale): int
    {
        return (int) round(((float) SaleRefund::where('sale_id', $sale->id)->sum('refund_total')) * 100);
    }

    private function saleTotalCentavos(Sale $sale): int
    {
        return max(0, (int) round(((float) $sale->total) * 100));
    }

    private function refundScopeHash(SaleRefund $refund, Collection $refundItems, LoyaltyReversalSetting $settings): string
    {
        $material = [
            'sale_refund_id' => $refund->id,
            'refund_total_centavos' => (int) round(((float) $refund->refund_total) * 100),
            'refund_items' => $refundItems
                ->map(fn (SaleRefundItem $item) => [
                    'sale_item_id' => $item->sale_item_id,
                    'quantity_refunded' => (string) $item->quantity_refunded,
                    'line_refund_total' => (string) $item->line_refund_total,
                ])
                ->sortBy('sale_item_id')
                ->values()
                ->all(),
            'restore_policy' => $settings->restore_redeemed_on_partial_refund_policy,
            'earn_reversal_policy' => $settings->refund_earn_reversal_policy,
        ];

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }

    private function baseVoidSnapshot(Sale $sale, SaleVoid $void, LoyaltyReversalSetting $settings): array
    {
        return [
            'snapshot_version' => 1,
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'sale_void_id' => $void->id,
            'sale_total_centavos' => $this->saleTotalCentavos($sale),
            'reason_code' => $void->reason_code,
            'reason_notes' => $void->reason_notes,
            'actor_id' => $void->voided_by,
            'tenant_reversal_settings' => $this->settingsSnapshot($settings),
        ];
    }

    private function baseRefundSnapshot(
        Sale $sale,
        SaleRefund $refund,
        Collection $refundItems,
        LoyaltyReversalSetting $settings,
        int $cumulativeRefundCentavos
    ): array {
        return [
            'snapshot_version' => 1,
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'sale_refund_id' => $refund->id,
            'sale_total_centavos' => $this->saleTotalCentavos($sale),
            'refund_total_centavos' => (int) round(((float) $refund->refund_total) * 100),
            'cumulative_refund_total_centavos' => $cumulativeRefundCentavos,
            'refund_items' => $refundItems->map(fn (SaleRefundItem $item) => [
                'sale_refund_item_id' => $item->id,
                'sale_item_id' => $item->sale_item_id,
                'quantity_refunded' => (string) $item->quantity_refunded,
                'line_refund_total' => (string) $item->line_refund_total,
            ])->values()->all(),
            'reason_code' => $refund->reason_code,
            'reason_notes' => $refund->reason_notes,
            'actor_id' => $refund->refunded_by,
            'tenant_reversal_settings' => $this->settingsSnapshot($settings),
        ];
    }

    private function settingsSnapshot(LoyaltyReversalSetting $settings): array
    {
        return [
            'settings_schema_version' => $settings->settings_schema_version,
            'reverse_earned_on_void' => $settings->reverse_earned_on_void,
            'reverse_earned_on_refund' => $settings->reverse_earned_on_refund,
            'restore_redeemed_on_void' => $settings->restore_redeemed_on_void,
            'restore_redeemed_on_refund' => $settings->restore_redeemed_on_refund,
            'allow_negative_balance' => $settings->allow_negative_balance,
            'require_approval_for_negative_balance' => $settings->require_approval_for_negative_balance,
            'negative_balance_approval_threshold_points' => $settings->negative_balance_approval_threshold_points,
            'restore_redeemed_on_partial_refund_policy' => $settings->restore_redeemed_on_partial_refund_policy,
            'refund_earn_reversal_policy' => $settings->refund_earn_reversal_policy,
        ];
    }

    private function auditReversal(string $action, LoyaltyLedgerEntry $entry, ?string $originalEntryId, int $points, ?User $actor): void
    {
        $this->auditLogger->log($action, $entry, null, [
            'loyalty_ledger_entry_id' => $entry->id,
            'customer_financial_account_id' => $entry->customer_financial_account_id,
            'entry_type' => $entry->entry_type,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'original_loyalty_ledger_entry_id' => $originalEntryId,
            'points' => $points,
            'event_version' => 1,
        ], metadata: ['event_version' => 1], actor: $actor);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyResult(array $skippedReasons = []): array
    {
        return [
            'earned_reversal_entry_ids' => [],
            'redemption_restore_entry_ids' => [],
            'negative_balance_created' => false,
            'approval_required' => false,
            'skipped_reasons' => $skippedReasons,
        ];
    }
}
