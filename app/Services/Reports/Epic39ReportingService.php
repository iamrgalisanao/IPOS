<?php

namespace App\Services\Reports;

use App\Models\AccountingOutbox;
use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;
use App\Models\StoreCreditLedgerEntry;
use App\Models\StoreCreditRedemption;
use App\Models\StoreCreditRefundIssuance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Epic39ReportingService
{
    private const REPORT_SCHEMA_VERSION = 1;

    public function storeCreditLiability(User $actor, array $filters): array
    {
        $periodEntries = $this->storeCreditLedgerQuery($actor, $filters)
            ->orderBy('currency_code')
            ->orderByDesc('ledger_sequence')
            ->get();

        $closingFilters = $filters;
        unset($closingFilters['business_date_from']);

        $closingEntries = $this->storeCreditLedgerQuery($actor, $closingFilters)
            ->orderBy('currency_code')
            ->orderByDesc('ledger_sequence')
            ->get();

        $totals = $this->currencyTotals($periodEntries);
        foreach ($this->currencyTotals($closingEntries) as $currency => $closingTotal) {
            $totals[$currency] ??= $this->emptyCurrencyTotals($currency);
            $totals[$currency]['outstanding_liability_centavos'] = $closingTotal['outstanding_liability_centavos'];
        }

        $paginator = $this->storeCreditLedgerQuery($actor, $filters)
            ->with('branch:id,name')
            ->orderByDesc('ledger_sequence')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($filters));

        return array_merge($this->baseReport('store_credit_liability', $filters), [
            'ordering' => ['ledger_sequence DESC', 'posted_at DESC', 'id DESC'],
            'totals' => [
                'currency_count' => count($totals),
                'row_count' => $paginator->total(),
                'note' => 'Currency-specific totals are intentionally not aggregated into a cross-currency grand total.',
            ],
            'totals_by_currency' => $totals,
            'rows' => $this->ledgerPaginatorPayload($paginator),
        ]);
    }

    public function storeCreditMovements(User $actor, array $filters): array
    {
        $perPage = $this->perPage($filters);
        $paginator = $this->storeCreditLedgerQuery($actor, $filters)
            ->with(['branch:id,name', 'customerFinancialAccount.customer'])
            ->orderByDesc('ledger_sequence')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return array_merge($this->baseReport('store_credit_movements', $filters), [
            'ordering' => ['ledger_sequence DESC', 'posted_at DESC', 'id DESC'],
            'totals' => [
                'row_count' => $paginator->total(),
            ],
            'rows' => $this->ledgerPaginatorPayload($paginator),
        ]);
    }

    public function customerStatement(User $actor, CustomerFinancialAccount $account, array $filters): array
    {
        $this->assertAccountVisible($actor, $account);

        $base = StoreCreditLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id);

        $this->applyVisibleBranchScope($base, $actor);

        if (!empty($filters['branch_id'])) {
            $this->assertBranchVisible($actor, (string) $filters['branch_id']);
            $base->where('branch_id', $filters['branch_id']);
        }

        if (!$actor->hasPermission('view_multi_branch_dashboard')) {
            abort_unless((clone $base)->exists(), 404);
        }

        $openingQuery = (clone $base);
        if (!empty($filters['business_date_from'])) {
            $openingQuery->whereDate('business_date', '<', $filters['business_date_from']);
        } else {
            $openingQuery->whereRaw('1 = 0');
        }

        $rowsQuery = (clone $base);
        $this->applyDateFilters($rowsQuery, $filters);

        $closingQuery = (clone $base);
        if (!empty($filters['business_date_to'])) {
            $closingQuery->whereDate('business_date', '<=', $filters['business_date_to']);
        }

        $rows = $rowsQuery
            ->with('branch:id,name')
            ->orderByDesc('ledger_sequence')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->get();

        $loyaltyBase = LoyaltyLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id);
        $this->applyVisibleBranchScope($loyaltyBase, $actor);
        if (!empty($filters['branch_id'])) {
            $loyaltyBase->where('branch_id', $filters['branch_id']);
        }

        $loyaltyOpeningQuery = (clone $loyaltyBase);
        if (!empty($filters['business_date_from'])) {
            $loyaltyOpeningQuery->whereDate('business_date', '<', $filters['business_date_from']);
        } else {
            $loyaltyOpeningQuery->whereRaw('1 = 0');
        }

        $loyaltyRowsQuery = (clone $loyaltyBase);
        $this->applyDateFilters($loyaltyRowsQuery, $filters);

        $loyaltyClosingQuery = (clone $loyaltyBase);
        if (!empty($filters['business_date_to'])) {
            $loyaltyClosingQuery->whereDate('business_date', '<=', $filters['business_date_to']);
        }

        $loyaltyRows = $loyaltyRowsQuery
            ->with('branch:id,name')
            ->orderByDesc('ledger_sequence')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->get();

        return array_merge($this->baseReport('customer_account_statement', $filters), [
            'customer_financial_account_id' => $account->id,
            'basis' => 'business_date',
            'ordering' => ['ledger_sequence DESC', 'posted_at DESC', 'id DESC'],
            'totals' => [
                'store_credit_row_count' => $rows->count(),
                'loyalty_row_count' => $loyaltyRows->count(),
            ],
            'rows' => [
                'store_credit' => $rows->map(fn (StoreCreditLedgerEntry $entry) => $this->ledgerRow($entry))->values()->all(),
                'loyalty' => $loyaltyRows->map(fn (LoyaltyLedgerEntry $entry) => $this->loyaltyLedgerRow($entry))->values()->all(),
            ],
            'opening_balance' => [
                'store_credit' => [
                    $account->currency_code => [
                        'centavos' => $this->signedBalance($openingQuery->get()),
                        'currency_code' => $account->currency_code,
                    ],
                ],
                'loyalty' => ['points' => $this->loyaltySignedBalance($loyaltyOpeningQuery->get())],
            ],
            'closing_balance' => [
                'store_credit' => [
                    $account->currency_code => [
                        'centavos' => $this->signedBalance($closingQuery->get()),
                        'currency_code' => $account->currency_code,
                    ],
                ],
                'loyalty' => ['points' => $this->loyaltySignedBalance($loyaltyClosingQuery->get())],
            ],
            'sections' => [
                'store_credit' => [
                    'rows' => $rows->map(fn (StoreCreditLedgerEntry $entry) => $this->ledgerRow($entry))->values()->all(),
                ],
                'loyalty' => [
                    'totals' => $this->loyaltyTotals($loyaltyRows),
                    'rows' => $loyaltyRows->map(fn (LoyaltyLedgerEntry $entry) => $this->loyaltyLedgerRow($entry))->values()->all(),
                ],
            ],
        ]);
    }

    public function storeCreditReconciliation(User $actor, array $filters): array
    {
        $entries = $this->storeCreditLedgerQuery($actor, $filters)
            ->whereIn('entry_type', [
                StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
                StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            ])
            ->with(['storeCreditRefundIssuance', 'storeCreditRedemption', 'branch:id,name'])
            ->orderByDesc('ledger_sequence')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];
        $exceptionCounts = [
            'pending_accounting_event' => 0,
            'failed_accounting_event' => 0,
            'missing_accounting_event' => 0,
            'missing_source_evidence' => 0,
            'mismatched_accounting_payload' => 0,
        ];

        foreach ($entries as $entry) {
            $expectedEventType = $this->expectedStoreCreditAccountingEvent($entry);
            $outbox = AccountingOutbox::query()
                ->where('source_type', 'store_credit_ledger_entry')
                ->where('source_id', $entry->id)
                ->where('event_type', $expectedEventType)
                ->first();

            $exceptions = [];

            if (!$outbox) {
                $exceptions[] = 'missing_accounting_event';
            } elseif ($outbox->sync_status === 'failed') {
                $exceptions[] = 'failed_accounting_event';
            } elseif (in_array($outbox->sync_status, ['pending', 'processing'], true)) {
                $exceptions[] = 'pending_accounting_event';
            }

            if ($outbox && !$this->accountingPayloadMatchesLedger($outbox, $entry)) {
                $exceptions[] = 'mismatched_accounting_payload';
            }

            if ($this->missingSourceEvidence($entry)) {
                $exceptions[] = 'missing_source_evidence';
            }

            foreach ($exceptions as $exceptionType) {
                $exceptionCounts[$exceptionType]++;
            }

            $severity = $this->severityForExceptions($exceptions);

            $rows[] = [
                'ledger_entry_id' => $entry->id,
                'ledger_sequence' => $entry->ledger_sequence,
                'entry_type' => $entry->entry_type,
                'amount_centavos' => $entry->amount_centavos,
                'currency_code' => $entry->currency_code,
                'business_date' => $entry->business_date?->toDateString(),
                'branch_id' => $entry->branch_id,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'source_reference' => $entry->source_reference,
                'evidence' => [
                    'refund_issuance_id' => $entry->storeCreditRefundIssuance?->id,
                    'sale_refund_id' => $entry->storeCreditRefundIssuance?->sale_refund_id,
                    'redemption_id' => $entry->storeCreditRedemption?->id,
                    'sale_id' => $entry->storeCreditRedemption?->sale_id ?? $entry->storeCreditRefundIssuance?->sale_id,
                    'sale_payment_id' => $entry->storeCreditRedemption?->sale_payment_id,
                    'accounting_outbox_id' => $outbox?->id,
                    'accounting_event_type' => $outbox?->event_type,
                    'expected_accounting_event_type' => $expectedEventType,
                    'accounting_sync_status' => $outbox?->sync_status,
                ],
                'severity' => $severity,
                'exception_type' => $exceptions[0] ?? null,
                'exceptions' => $exceptions,
            ];
        }

        $warningCount = $exceptionCounts['pending_accounting_event'];
        $criticalCount = $exceptionCounts['failed_accounting_event']
            + $exceptionCounts['missing_accounting_event']
            + $exceptionCounts['missing_source_evidence']
            + $exceptionCounts['mismatched_accounting_payload'];
        $rowsPayload = $this->arrayPaginatorPayload($rows, $filters);

        return array_merge($this->baseReport('store_credit_reconciliation', $filters), [
            'ordering' => ['ledger_sequence DESC', 'posted_at DESC', 'id DESC'],
            'health' => $this->health($warningCount, $criticalCount),
            'warning_count' => $warningCount,
            'critical_count' => $criticalCount,
            'exception_counts' => $exceptionCounts,
            'totals' => [
                'row_count' => count($rows),
                'exception_count' => $warningCount + $criticalCount,
            ],
            'rows' => $rowsPayload,
        ]);
    }

    public function loyaltyActivity(User $actor, array $filters): array
    {
        $paginator = $this->loyaltyLedgerQuery($actor, $filters)
            ->with(['branch:id,name', 'customerFinancialAccount.customer'])
            ->orderByDesc('ledger_sequence')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($filters));

        $entries = $this->loyaltyLedgerQuery($actor, $filters)->get();
        $totals = $this->loyaltyTotals($entries);

        return array_merge($this->baseReport('loyalty_activity', $filters), [
            'ordering' => ['ledger_sequence DESC', 'posted_at DESC', 'id DESC'],
            'totals' => $totals,
            'rows' => $this->loyaltyLedgerPaginatorPayload($paginator),
        ]);
    }

    public function reconciliationExceptions(User $actor, array $filters): array
    {
        $report = $this->storeCreditReconciliation($actor, $filters);
        $report['report_name'] = 'epic39_reconciliation_exceptions';

        return $report;
    }

    private function storeCreditLedgerQuery(User $actor, array $filters): Builder
    {
        $query = StoreCreditLedgerEntry::query();
        $this->applyVisibleBranchScope($query, $actor);
        $this->applyDateFilters($query, $filters);

        foreach (['customer_financial_account_id', 'currency_code', 'entry_type', 'ledger_category', 'direction', 'source_type'] as $filter) {
            if (!empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (!empty($filters['customer_id'])) {
            $query->whereExists(function ($subquery) use ($filters) {
                $subquery->selectRaw('1')
                    ->from('customer_financial_accounts')
                    ->whereColumn('customer_financial_accounts.id', 'store_credit_ledger_entries.customer_financial_account_id')
                    ->where('customer_financial_accounts.customer_id', $filters['customer_id']);
            });
        }

        if (!empty($filters['branch_id'])) {
            $this->assertBranchVisible($actor, (string) $filters['branch_id']);
            $query->where('branch_id', $filters['branch_id']);
        }

        if (!empty($filters['accounting_status'])) {
            $query->whereExists(function ($subquery) use ($filters) {
                $subquery->selectRaw('1')
                    ->from('accounting_outbox')
                    ->whereColumn('accounting_outbox.source_id', 'store_credit_ledger_entries.id')
                    ->where('accounting_outbox.source_type', 'store_credit_ledger_entry')
                    ->where('accounting_outbox.sync_status', $filters['accounting_status']);
            });
        }

        return $query;
    }

    private function loyaltyLedgerQuery(User $actor, array $filters): Builder
    {
        $query = LoyaltyLedgerEntry::query();
        $this->applyVisibleBranchScope($query, $actor);
        $this->applyDateFilters($query, $filters);

        foreach (['customer_financial_account_id', 'entry_type', 'ledger_category', 'direction', 'source_type'] as $filter) {
            if (!empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (!empty($filters['customer_id'])) {
            $query->whereExists(function ($subquery) use ($filters) {
                $subquery->selectRaw('1')
                    ->from('customer_financial_accounts')
                    ->whereColumn('customer_financial_accounts.id', 'loyalty_ledger_entries.customer_financial_account_id')
                    ->where('customer_financial_accounts.customer_id', $filters['customer_id']);
            });
        }

        if (!empty($filters['branch_id'])) {
            $this->assertBranchVisible($actor, (string) $filters['branch_id']);
            $query->where('branch_id', $filters['branch_id']);
        }

        return $query;
    }

    private function applyDateFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['business_date_from'])) {
            $query->whereDate('business_date', '>=', $filters['business_date_from']);
        }

        if (!empty($filters['business_date_to'])) {
            $query->whereDate('business_date', '<=', $filters['business_date_to']);
        }
    }

    private function applyVisibleBranchScope(Builder $query, User $actor): void
    {
        if ($actor->hasPermission('view_multi_branch_dashboard')) {
            return;
        }

        $branchIds = $actor->branches()->pluck('branches.id')->all();
        $query->whereIn('branch_id', $branchIds);
    }

    private function assertBranchVisible(User $actor, string $branchId): void
    {
        if ($actor->hasPermission('view_multi_branch_dashboard')) {
            return;
        }

        abort_unless($actor->branches()->where('branches.id', $branchId)->exists(), 403);
    }

    private function assertAccountVisible(User $actor, CustomerFinancialAccount $account): void
    {
        abort_unless($account->tenant_id === $actor->tenant_id, 404);
    }

    private function baseReport(string $name, array $filters): array
    {
        return [
            'report_name' => $name,
            'tenant_id' => app(\App\Services\TenantContext::class)->getTenantId(),
            'branch_scope' => $filters['branch_id'] ?? 'visible',
            'filters' => $filters,
            'generated_at' => now()->toISOString(),
            'report_schema_version' => self::REPORT_SCHEMA_VERSION,
            'report_instance_id' => (string) Str::uuid(),
            'basis' => 'business_date',
            'projection_version' => null,
            'is_projection_stale' => false,
        ];
    }

    private function emptyCurrencyTotals(string $currency): array
    {
        return [
            'currency_code' => $currency,
            'issued_centavos' => 0,
            'redeemed_centavos' => 0,
            'reversed_centavos' => 0,
            'adjusted_centavos' => 0,
            'expired_or_forfeited_centavos' => 0,
            'total_credits_centavos' => 0,
            'total_debits_centavos' => 0,
            'outstanding_liability_centavos' => 0,
            'ledger_entry_count' => 0,
        ];
    }

    private function currencyTotals(Collection $entries): array
    {
        $totals = [];

        foreach ($entries as $entry) {
            $currency = $entry->currency_code;
            $totals[$currency] ??= $this->emptyCurrencyTotals($currency);

            if ($entry->direction === StoreCreditLedgerEntry::DIRECTION_CREDIT) {
                $totals[$currency]['total_credits_centavos'] += $entry->amount_centavos;
            }

            if ($entry->direction === StoreCreditLedgerEntry::DIRECTION_DEBIT) {
                $totals[$currency]['total_debits_centavos'] += $entry->amount_centavos;
            }

            match ($entry->entry_type) {
                StoreCreditLedgerEntry::TYPE_REFUND_CREDIT => $totals[$currency]['issued_centavos'] += $entry->amount_centavos,
                StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT => $totals[$currency]['redeemed_centavos'] += $entry->amount_centavos,
                StoreCreditLedgerEntry::TYPE_ADMIN_CREDIT_ADJUSTMENT,
                StoreCreditLedgerEntry::TYPE_ADMIN_DEBIT_ADJUSTMENT => $totals[$currency]['adjusted_centavos'] += $entry->amount_centavos,
                StoreCreditLedgerEntry::TYPE_REVERSAL_CREDIT,
                StoreCreditLedgerEntry::TYPE_REVERSAL_DEBIT => $totals[$currency]['reversed_centavos'] += $entry->amount_centavos,
                StoreCreditLedgerEntry::TYPE_EXPIRATION_DEBIT,
                StoreCreditLedgerEntry::TYPE_FORFEITURE_DEBIT => $totals[$currency]['expired_or_forfeited_centavos'] += $entry->amount_centavos,
                default => null,
            };

            $totals[$currency]['ledger_entry_count']++;
            $totals[$currency]['outstanding_liability_centavos'] =
                $totals[$currency]['total_credits_centavos'] - $totals[$currency]['total_debits_centavos'];
        }

        return $totals;
    }

    private function signedBalance(Collection $entries): int
    {
        return $entries->sum(function (StoreCreditLedgerEntry $entry) {
            return $entry->direction === StoreCreditLedgerEntry::DIRECTION_CREDIT
                ? $entry->amount_centavos
                : -1 * $entry->amount_centavos;
        });
    }

    private function loyaltySignedBalance(Collection $entries): int
    {
        return $entries->sum(function (LoyaltyLedgerEntry $entry) {
            return $entry->direction === LoyaltyLedgerEntry::DIRECTION_CREDIT
                ? $entry->points
                : -1 * $entry->points;
        });
    }

    private function loyaltyTotals(Collection $entries): array
    {
        $earned = 0;
        $redeemed = 0;
        $reversed = 0;

        foreach ($entries as $entry) {
            match ($entry->entry_type) {
                LoyaltyLedgerEntry::TYPE_SALE_ACCRUAL => $earned += $entry->points,
                LoyaltyLedgerEntry::TYPE_REDEMPTION_DEBIT => $redeemed += $entry->points,
                LoyaltyLedgerEntry::TYPE_REVERSAL_CREDIT,
                LoyaltyLedgerEntry::TYPE_REVERSAL_DEBIT => $reversed += $entry->points,
                default => null,
            };
        }

        return [
            'points' => $this->loyaltySignedBalance($entries),
            'points_earned' => $earned,
            'points_redeemed' => $redeemed,
            'points_reversed' => $reversed,
            'points_balance' => $this->loyaltySignedBalance($entries),
            'row_count' => $entries->count(),
        ];
    }

    private function ledgerPaginatorPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())
                ->map(fn (StoreCreditLedgerEntry $entry) => $this->ledgerRow($entry))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function loyaltyLedgerPaginatorPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())
                ->map(fn (LoyaltyLedgerEntry $entry) => $this->loyaltyLedgerRow($entry))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function arrayPaginatorPayload(array $rows, array $filters): array
    {
        $perPage = $this->perPage($filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $data = array_slice($rows, $offset, $perPage);

        return [
            'data' => array_values($data),
            'meta' => [
                'current_page' => $page,
                'from' => $total === 0 ? null : $offset + 1,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => $total === 0 ? null : min($offset + count($data), $total),
                'total' => $total,
            ],
        ];
    }

    private function ledgerRow(StoreCreditLedgerEntry $entry): array
    {
        return [
            'ledger_entry_id' => $entry->id,
            'customer_financial_account_id' => $entry->customer_financial_account_id,
            'ledger_sequence' => $entry->ledger_sequence,
            'entry_type' => $entry->entry_type,
            'ledger_category' => $entry->ledger_category,
            'direction' => $entry->direction,
            'amount_centavos' => $entry->amount_centavos,
            'currency_code' => $entry->currency_code,
            'business_date' => $entry->business_date?->toDateString(),
            'posted_at' => $entry->posted_at?->toISOString(),
            'branch_id' => $entry->branch_id,
            'branch_name' => $entry->branch?->name,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'source_reference' => $entry->source_reference,
        ];
    }

    private function loyaltyLedgerRow(LoyaltyLedgerEntry $entry): array
    {
        return [
            'ledger_entry_id' => $entry->id,
            'customer_financial_account_id' => $entry->customer_financial_account_id,
            'ledger_sequence' => $entry->ledger_sequence,
            'entry_type' => $entry->entry_type,
            'ledger_category' => $entry->ledger_category,
            'direction' => $entry->direction,
            'points' => $entry->points,
            'business_date' => $entry->business_date?->toDateString(),
            'posted_at' => $entry->posted_at?->toISOString(),
            'branch_id' => $entry->branch_id,
            'branch_name' => $entry->branch?->name,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'source_reference' => $entry->source_reference,
        ];
    }

    private function health(int $warningCount, int $criticalCount): array
    {
        $status = 'healthy';
        if ($criticalCount > 0) {
            $status = 'critical';
        } elseif ($warningCount > 0) {
            $status = 'warnings';
        }

        return [
            'status' => $status,
            'warning_count' => $warningCount,
            'critical_count' => $criticalCount,
        ];
    }

    private function expectedStoreCreditAccountingEvent(StoreCreditLedgerEntry $entry): string
    {
        return $entry->entry_type === StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT
            ? 'store_credit_redeemed'
            : 'store_credit_issued';
    }

    private function missingSourceEvidence(StoreCreditLedgerEntry $entry): bool
    {
        if ($entry->entry_type === StoreCreditLedgerEntry::TYPE_REFUND_CREDIT) {
            return !$entry->storeCreditRefundIssuance;
        }

        if ($entry->entry_type === StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT) {
            return !$entry->storeCreditRedemption;
        }

        return false;
    }

    private function accountingPayloadMatchesLedger(AccountingOutbox $outbox, StoreCreditLedgerEntry $entry): bool
    {
        $payload = (array) $outbox->payload;

        return ($payload['ledger_entry_id'] ?? $entry->id) === $entry->id
            && (int) ($payload['amount_centavos'] ?? $entry->amount_centavos) === $entry->amount_centavos
            && ($payload['currency_code'] ?? $entry->currency_code) === $entry->currency_code;
    }

    /**
     * @param array<int,string> $exceptions
     */
    private function severityForExceptions(array $exceptions): string
    {
        if (array_intersect($exceptions, [
            'failed_accounting_event',
            'missing_accounting_event',
            'missing_source_evidence',
            'mismatched_accounting_payload',
        ])) {
            return 'critical';
        }

        return in_array('pending_accounting_event', $exceptions, true) ? 'warning' : 'healthy';
    }

    private function perPage(array $filters): int
    {
        return max(1, min((int) ($filters['per_page'] ?? 25), 100));
    }
}
