<?php

namespace App\Services\Settlement;

use App\Models\AccountingOutbox;
use App\Models\PaymentReversal;
use App\Models\QuickBooksConnection;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\SettlementPeriod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SettlementVarianceQueryService
{
    protected const CATEGORY_KEYS = [
        'timing_gap',
        'sync_failure',
        'mapping_gap',
        'connection_gap',
        'payment_mismatch',
        'refund_mismatch',
        'void_mismatch',
        'manual_review_required',
    ];

    public function summarize(SettlementPeriod $period, User $actor): array
    {
        $this->assertAuthorized($actor);
        $this->assertCanViewPeriod($period, $actor);

        $items = collect()
            ->concat($this->timingGapItems($period))
            ->concat($this->syncFailureItems($period))
            ->concat($this->mappingGapItems($period))
            ->concat($this->connectionGapItems($period))
            ->concat($this->paymentMismatchItems($period))
            ->concat($this->refundMismatchItems($period))
            ->concat($this->voidMismatchItems($period))
            ->concat($this->manualReviewItems($period))
            ->sortBy([
                ['category', 'asc'],
                ['source_type', 'asc'],
                ['source_id', 'asc'],
            ])
            ->values();

        $byCategory = array_fill_keys(self::CATEGORY_KEYS, 0);
        foreach ($items as $item) {
            $byCategory[$item['category']]++;
        }

        return [
            'period_id' => $period->id,
            'summary' => [
                'total_variance_count' => $items->count(),
                'by_category' => $byCategory,
            ],
            'items' => $items->all(),
        ];
    }

    protected function timingGapItems(SettlementPeriod $period): Collection
    {
        return $this->scopedQuery(AccountingOutbox::query(), $period, 'created_at')
            ->whereIn('sync_status', ['pending', 'processing'])
            ->get()
            ->map(fn (AccountingOutbox $record) => $this->item(
                'timing_gap',
                'accounting_outbox',
                $record->id,
                'warning',
                sprintf('Accounting sync is still %s for %s event.', $record->sync_status, $record->event_type),
                $this->amountFromOutbox($record),
                [
                    'event_type' => $record->event_type,
                    'sync_status' => $record->sync_status,
                    'sync_error_category' => $record->sync_error_category,
                ],
            ));
    }

    protected function syncFailureItems(SettlementPeriod $period): Collection
    {
        return $this->scopedQuery(AccountingOutbox::query(), $period, 'created_at')
            ->where('sync_status', 'failed')
            ->where(function (Builder $query) {
                $query->whereNull('sync_error_category')
                    ->orWhere('sync_error_category', '!=', 'mapping');
            })
            ->get()
            ->map(fn (AccountingOutbox $record) => $this->item(
                'sync_failure',
                'accounting_outbox',
                $record->id,
                'warning',
                sprintf('Accounting sync failed for %s event.', $record->event_type),
                $this->amountFromOutbox($record),
                [
                    'event_type' => $record->event_type,
                    'sync_status' => $record->sync_status,
                    'sync_error_category' => $record->sync_error_category,
                ],
            ));
    }

    protected function mappingGapItems(SettlementPeriod $period): Collection
    {
        return $this->scopedQuery(AccountingOutbox::query(), $period, 'created_at')
            ->where('sync_status', 'failed')
            ->where(function (Builder $query) {
                $query->where('sync_error_category', 'mapping')
                    ->orWhere('sync_error', 'like', '%mapping%')
                    ->orWhere('sync_error', 'like', '%missing map%')
                    ->orWhere('sync_error', 'like', '%missing mapping%');
            })
            ->get()
            ->map(fn (AccountingOutbox $record) => $this->item(
                'mapping_gap',
                'accounting_outbox',
                $record->id,
                'warning',
                sprintf('Accounting mapping is missing for %s event.', $record->event_type),
                $this->amountFromOutbox($record),
                [
                    'event_type' => $record->event_type,
                    'sync_status' => $record->sync_status,
                    'sync_error_category' => $record->sync_error_category,
                ],
            ));
    }

    protected function connectionGapItems(SettlementPeriod $period): Collection
    {
        return QuickBooksConnection::query()
            ->where('tenant_id', $period->tenant_id)
            ->whereIn('status', [
                QuickBooksConnection::STATUS_DISCONNECTED,
                QuickBooksConnection::STATUS_EXPIRED,
                QuickBooksConnection::STATUS_ERROR,
            ])
            ->whereBetween('updated_at', [$period->period_start_at, $period->period_end_at])
            ->get()
            ->map(fn (QuickBooksConnection $connection) => $this->item(
                'connection_gap',
                'quickbooks_connection',
                $connection->id,
                'warning',
                sprintf('QuickBooks connection is %s during the settlement period.', $connection->status),
                '0.0000',
                [
                    'status' => $connection->status,
                    'realm_id' => $connection->realm_id,
                ],
            ));
    }

    protected function paymentMismatchItems(SettlementPeriod $period): Collection
    {
        return $this->scopedQuery(Sale::query(), $period, 'confirmed_at')
            ->with(['payments' => fn ($query) => $query->where('status', 'paid')])
            ->get()
            ->filter(function (Sale $sale) {
                return !$this->decimalEquals($sale->payments->sum('amount'), $sale->total);
            })
            ->map(function (Sale $sale) {
                $paidTotal = $sale->payments->sum('amount');

                return $this->item(
                    'payment_mismatch',
                    'sale',
                    $sale->id,
                    'warning',
                    'Paid sale total does not match recorded payment total.',
                    $this->absoluteDifference($sale->total, $paidTotal),
                    [
                        'sale_total' => $this->decimalString($sale->total),
                        'payment_total' => $this->decimalString($paidTotal),
                    ],
                );
            });
    }

    protected function refundMismatchItems(SettlementPeriod $period): Collection
    {
        return $this->scopedQuery(SaleRefund::query(), $period, 'refunded_at')
            ->select('sale_id', DB::raw('MIN(id) as representative_id'), DB::raw('SUM(refund_total) as refund_total'))
            ->groupBy('sale_id')
            ->get()
            ->filter(function ($refundGroup) use ($period) {
                $reversalTotal = $this->refundReversalTotalForSale($period, $refundGroup->sale_id);

                return !$this->decimalEquals($reversalTotal, $refundGroup->refund_total);
            })
            ->map(function ($refundGroup) use ($period) {
                $reversalTotal = $this->refundReversalTotalForSale($period, $refundGroup->sale_id);

                return $this->item(
                    'refund_mismatch',
                    'sale_refund',
                    $refundGroup->representative_id,
                    'warning',
                    'Refund total does not match recorded refund payment reversals.',
                    $this->absoluteDifference($refundGroup->refund_total, $reversalTotal),
                    [
                        'refund_total' => $this->decimalString($refundGroup->refund_total),
                        'reversal_total' => $this->decimalString($reversalTotal),
                        'sale_id' => $refundGroup->sale_id,
                    ],
                );
            });
    }

    protected function voidMismatchItems(SettlementPeriod $period): Collection
    {
        return $this->scopedQuery(SaleVoid::query(), $period, 'voided_at')
            ->with('sale')
            ->get()
            ->filter(function (SaleVoid $void) use ($period) {
                $paymentReversed = $this->voidReversalPaymentTotalForSale($period, $void->sale_id);
                $expectedInventoryReversals = $this->expectedVoidInventoryReversalCount($void->sale_id);
                $actualInventoryReversals = $this->actualVoidInventoryReversalCount($void->id);

                return !$this->decimalEquals($paymentReversed, $void->sale?->total)
                    || $actualInventoryReversals < $expectedInventoryReversals;
            })
            ->map(function (SaleVoid $void) use ($period) {
                $paymentReversed = $this->voidReversalPaymentTotalForSale($period, $void->sale_id);
                $expectedInventoryReversals = $this->expectedVoidInventoryReversalCount($void->sale_id);
                $actualInventoryReversals = $this->actualVoidInventoryReversalCount($void->id);

                return $this->item(
                    'void_mismatch',
                    'sale_void',
                    $void->id,
                    'warning',
                    'Voided sale is missing full payment reversal and/or inventory reversal coverage.',
                    $this->absoluteDifference($void->sale?->total, $paymentReversed),
                    [
                        'sale_id' => $void->sale_id,
                        'sale_total' => $this->decimalString($void->sale?->total),
                        'payment_reversal_total' => $this->decimalString($paymentReversed),
                        'expected_inventory_reversals' => $expectedInventoryReversals,
                        'actual_inventory_reversals' => $actualInventoryReversals,
                    ],
                );
            });
    }

    protected function manualReviewItems(SettlementPeriod $period): Collection
    {
        return $this->scopedQuery(PaymentReversal::query(), $period, 'reversed_at')
            ->where('tenant_id', $period->tenant_id)
            ->whereNotIn('reversal_type', ['refund_reversal', 'void_reversal'])
            ->get()
            ->map(fn (PaymentReversal $reversal) => $this->item(
                'manual_review_required',
                'payment_reversal',
                $reversal->id,
                'warning',
                sprintf('Payment reversal type %s requires manual review.', $reversal->reversal_type),
                $this->decimalString($reversal->amount),
                [
                    'sale_id' => $reversal->sale_id,
                    'reversal_type' => $reversal->reversal_type,
                ],
            ));
    }

    protected function refundReversalTotalForSale(SettlementPeriod $period, string $saleId): mixed
    {
        return $this->scopedQuery(PaymentReversal::query(), $period, 'reversed_at')
            ->where('tenant_id', $period->tenant_id)
            ->where('sale_id', $saleId)
            ->where('reversal_type', 'refund_reversal')
            ->sum('amount');
    }

    protected function voidReversalPaymentTotalForSale(SettlementPeriod $period, string $saleId): mixed
    {
        return $this->scopedQuery(PaymentReversal::query(), $period, 'reversed_at')
            ->where('tenant_id', $period->tenant_id)
            ->where('sale_id', $saleId)
            ->where('reversal_type', 'void_reversal')
            ->sum('amount');
    }

    protected function expectedVoidInventoryReversalCount(string $saleId): int
    {
        return (int) DB::table('inventory_movements')
            ->where('source_type', 'sale')
            ->where('source_id', $saleId)
            ->where('movement_type', 'sale_deduction')
            ->count();
    }

    protected function actualVoidInventoryReversalCount(string $voidId): int
    {
        return (int) DB::table('inventory_movements')
            ->where('source_type', 'sale_void')
            ->where('source_id', $voidId)
            ->where('movement_type', 'void_reversal')
            ->count();
    }

    protected function amountFromOutbox(AccountingOutbox $record): string
    {
        $payload = $record->payload ?? [];

        return $this->decimalString(
            $payload['refund_total']
            ?? $payload['original_sale_total']
            ?? $payload['total']
            ?? 0
        );
    }

    protected function item(
        string $category,
        string $sourceType,
        string $sourceId,
        string $severity,
        string $message,
        string $amount,
        array $metadata = []
    ): array {
        return [
            'category' => $category,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'severity' => $severity,
            'message' => $message,
            'amount' => $amount,
            'metadata' => $metadata,
        ];
    }

    protected function scopedQuery(Builder $query, SettlementPeriod $period, string $timestampColumn): Builder
    {
        $table = $query->getModel()->getTable();

        $query->where($table . '.tenant_id', $period->tenant_id);

        if ($period->branch_id !== null) {
            $query->where($table . '.branch_id', $period->branch_id);
        }

        return $query
            ->where($timestampColumn, '>=', $period->period_start_at)
            ->where($timestampColumn, '<=', $period->period_end_at);
    }

    protected function assertAuthorized(User $actor): void
    {
        if (!$actor->hasPermission('manage_settlement_periods') && !$actor->hasPermission('view_settlement_periods')) {
            throw new AuthorizationException('Unauthorized. Permission required: view_settlement_periods');
        }
    }

    protected function assertCanViewPeriod(SettlementPeriod $period, User $actor): void
    {
        if ($actor->hasPermission('view_multi_branch_dashboard')) {
            return;
        }

        if ($period->branch_id === null) {
            throw new AuthorizationException('Branch-scoped users cannot summarize tenant-wide settlement variances.');
        }

        $allowedBranchIds = $actor->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all();
        if (!in_array($period->branch_id, $allowedBranchIds, true)) {
            throw new AuthorizationException('Branch scope access denied for this settlement variance summary.');
        }
    }

    protected function decimalEquals(mixed $left, mixed $right): bool
    {
        return $this->decimalString($left) === $this->decimalString($right);
    }

    protected function absoluteDifference(mixed $left, mixed $right): string
    {
        return $this->decimalString(abs((float) ($left ?? 0) - (float) ($right ?? 0)));
    }

    protected function decimalString(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }
}
