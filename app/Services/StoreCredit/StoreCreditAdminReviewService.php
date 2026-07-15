<?php

namespace App\Services\StoreCredit;

use App\Models\Customer;
use App\Models\CustomerFinancialAccount;
use App\Models\StoreCreditLedgerEntry;
use App\Models\StoreCreditRedemption;
use App\Models\StoreCreditRefundIssuance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class StoreCreditAdminReviewService
{
    public function __construct(
        private readonly StoreCreditBalanceService $balanceService,
    ) {
    }

    public function accountReview(CustomerFinancialAccount $account, int $recentLimit = 10): array
    {
        $account->loadMissing('customer');

        return [
            'customer_financial_account' => $this->accountPayload($account),
            'store_credit' => $this->storeCreditSummary($account),
            'recent_ledger_entries' => $this->ledgerQuery($account, [])
                ->limit($recentLimit)
                ->get()
                ->map(fn (StoreCreditLedgerEntry $entry) => $this->ledgerEntryPayload($entry))
                ->values()
                ->all(),
        ];
    }

    public function accountListItem(CustomerFinancialAccount $account): array
    {
        $account->loadMissing('customer');
        $payload = $account->toArray();

        if (($payload['customer']['status'] ?? null) === Customer::STATUS_ANONYMIZED) {
            $payload['customer']['email'] = null;
            $payload['customer']['phone'] = null;
            $payload['customer']['external_reference'] = null;
        }

        $payload['store_credit'] = $this->storeCreditSummary($account);

        return $payload;
    }

    public function ledgerHistory(CustomerFinancialAccount $account, array $filters = []): array
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min($perPage, 100));

        $paginator = $this->ledgerQuery($account, $filters)->paginate($perPage);

        return [
            'customer_financial_account' => $this->accountPayload($account->loadMissing('customer')),
            'store_credit' => $this->storeCreditSummary($account),
            'ledger_entries' => $this->paginatorPayload($paginator),
        ];
    }

    public function ledgerEntry(CustomerFinancialAccount $account, StoreCreditLedgerEntry $entry): array
    {
        abort_unless($entry->customer_financial_account_id === $account->id, 404);

        return [
            'customer_financial_account' => $this->accountPayload($account->loadMissing('customer')),
            'ledger_entry' => $this->ledgerEntryPayload($this->loadLedgerEvidence($entry)),
        ];
    }

    private function ledgerQuery(CustomerFinancialAccount $account, array $filters): Builder
    {
        $query = StoreCreditLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->with([
                'branch:id,name',
                'postedBy:id,name,first_name,last_name,email',
                'storeCreditRefundIssuance',
                'storeCreditRedemption',
            ])
            ->orderByDesc('ledger_sequence');

        if (!empty($filters['date_from'])) {
            $query->whereDate('business_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('business_date', '<=', $filters['date_to']);
        }

        foreach (['branch_id', 'entry_type', 'ledger_category', 'direction', 'source_type', 'posted_by'] as $filter) {
            if (!empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (!empty($filters['source_reference'])) {
            $query->where('source_reference', 'like', '%' . $filters['source_reference'] . '%');
        }

        return $query;
    }

    private function storeCreditSummary(CustomerFinancialAccount $account): array
    {
        $summary = StoreCreditLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->selectRaw('COUNT(*) as ledger_entry_count, MAX(ledger_sequence) as last_ledger_sequence, MAX(posted_at) as last_posted_at')
            ->first();

        return [
            'available_balance_centavos' => $this->balanceService->availableBalanceCentavos($account),
            'currency_code' => $account->currency_code,
            'balance_source' => 'ledger',
            'ledger_entry_count' => (int) ($summary?->ledger_entry_count ?? 0),
            'last_ledger_sequence' => $summary?->last_ledger_sequence !== null
                ? (int) $summary->last_ledger_sequence
                : null,
            'last_posted_at' => $summary?->last_posted_at
                ? $this->serializeDateTime($summary->last_posted_at)
                : null,
        ];
    }

    private function accountPayload(CustomerFinancialAccount $account): array
    {
        $customer = $account->customer;
        $isAnonymized = $customer?->status === Customer::STATUS_ANONYMIZED;

        return [
            'id' => $account->id,
            'customer_id' => $account->customer_id,
            'customer_display_name' => $customer?->display_name,
            'customer_status' => $customer?->status,
            'customer_email' => $isAnonymized ? null : $customer?->email,
            'customer_phone' => $isAnonymized ? null : $customer?->phone,
            'customer_external_reference' => $isAnonymized ? null : $customer?->external_reference,
            'account_status' => $account->status,
            'currency_code' => $account->currency_code,
            'opened_at' => $account->opened_at?->toISOString(),
            'suspended_at' => $account->suspended_at?->toISOString(),
            'closed_at' => $account->closed_at?->toISOString(),
        ];
    }

    private function ledgerEntryPayload(StoreCreditLedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'ledger_sequence' => $entry->ledger_sequence,
            'ledger_schema_version' => $entry->ledger_schema_version,
            'ledger_category' => $entry->ledger_category,
            'entry_type' => $entry->entry_type,
            'direction' => $entry->direction,
            'amount_centavos' => $entry->amount_centavos,
            'currency_code' => $entry->currency_code,
            'business_date' => $entry->business_date?->toDateString(),
            'posted_at' => $entry->posted_at?->toISOString(),
            'branch' => $entry->branch ? [
                'id' => $entry->branch->id,
                'name' => $entry->branch->name,
            ] : null,
            'source' => $this->sourcePayload($entry),
            'source_snapshot' => $entry->source_snapshot,
            'posted_by' => $entry->postedBy ? [
                'id' => $entry->postedBy->id,
                'name' => $this->userDisplayName($entry->postedBy),
            ] : null,
        ];
    }

    private function sourcePayload(StoreCreditLedgerEntry $entry): array
    {
        $refundIssuance = $entry->storeCreditRefundIssuance;

        if ($refundIssuance instanceof StoreCreditRefundIssuance) {
            return [
                'type' => $entry->source_type,
                'id' => $entry->source_id,
                'reference' => $entry->source_reference,
                'link_type' => 'store_credit_refund_issuance',
                'link_available' => true,
                'store_credit_refund_issuance_id' => $refundIssuance->id,
                'sale_id' => $refundIssuance->sale_id,
                'sale_refund_id' => $refundIssuance->sale_refund_id,
            ];
        }

        $redemption = $entry->storeCreditRedemption;

        if ($redemption instanceof StoreCreditRedemption) {
            return [
                'type' => $entry->source_type,
                'id' => $entry->source_id,
                'reference' => $entry->source_reference,
                'link_type' => 'store_credit_redemption',
                'link_available' => true,
                'store_credit_redemption_id' => $redemption->id,
                'sale_id' => $redemption->sale_id,
                'sale_payment_id' => $redemption->sale_payment_id,
                'authorized_balance_centavos' => $redemption->authorized_balance_centavos,
            ];
        }

        return [
            'type' => $entry->source_type,
            'id' => $entry->source_id,
            'reference' => $entry->source_reference,
            'link_type' => null,
            'link_available' => false,
        ];
    }

    private function loadLedgerEvidence(StoreCreditLedgerEntry $entry): StoreCreditLedgerEntry
    {
        return $entry->loadMissing([
            'branch:id,name',
            'postedBy:id,name,first_name,last_name,email',
            'storeCreditRefundIssuance',
            'storeCreditRedemption',
        ]);
    }

    private function paginatorPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())
                ->map(fn (StoreCreditLedgerEntry $entry) => $this->ledgerEntryPayload($entry))
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

    private function userDisplayName($user): string
    {
        $name = trim((string) ($user->name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))));

        return $name !== '' ? $name : (string) $user->email;
    }

    private function serializeDateTime(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($value)->toISOString();
        }

        return \Illuminate\Support\Carbon::parse($value)->toISOString();
    }
}
