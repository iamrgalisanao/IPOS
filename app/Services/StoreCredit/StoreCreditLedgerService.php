<?php

namespace App\Services\StoreCredit;

use App\Exceptions\StoreCredit\StoreCreditLedgerAccountStateException;
use App\Exceptions\StoreCredit\StoreCreditLedgerCurrencyMismatchException;
use App\Exceptions\StoreCredit\StoreCreditLedgerIdempotencyDriftException;
use App\Exceptions\StoreCredit\StoreCreditLedgerInsufficientBalanceException;
use App\Exceptions\StoreCredit\StoreCreditLedgerSourceConflictException;
use App\Models\CustomerFinancialAccount;
use App\Models\StoreCreditLedgerEntry;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class StoreCreditLedgerService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StoreCreditLedgerSequenceService $sequenceService,
        private readonly StoreCreditBalanceService $balanceService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function post(CustomerFinancialAccount $account, array $data, ?User $actor = null): StoreCreditLedgerEntry
    {
        $payload = $this->normalizePostingPayload($account, $data);
        $fingerprint = $this->fingerprint($payload);

        $existing = StoreCreditLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->first();

        if ($existing) {
            if ($existing->request_fingerprint !== $fingerprint) {
                $this->audit('STORE_CREDIT_LEDGER_IDEMPOTENCY_DRIFT_REJECTED', $existing, $actor);

                throw new StoreCreditLedgerIdempotencyDriftException(
                    'The idempotency key was already used with different store credit ledger details.'
                );
            }

            $this->audit('STORE_CREDIT_LEDGER_IDEMPOTENCY_REPLAYED', $existing, $actor);

            return $existing;
        }

        return DB::transaction(function () use ($account, $payload, $fingerprint, $actor) {
            $lockedAccount = CustomerFinancialAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedAccount || $lockedAccount->tenant_id !== $this->tenantContext->getTenantId()) {
                throw new RuntimeException('Customer financial account is not available for this tenant.');
            }

            $existingAfterLock = StoreCreditLedgerEntry::query()
                ->where('customer_financial_account_id', $lockedAccount->id)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->first();

            if ($existingAfterLock) {
                if ($existingAfterLock->request_fingerprint !== $fingerprint) {
                    $this->audit('STORE_CREDIT_LEDGER_IDEMPOTENCY_DRIFT_REJECTED', $existingAfterLock, $actor);

                    throw new StoreCreditLedgerIdempotencyDriftException(
                        'The idempotency key was already used with different store credit ledger details.'
                    );
                }

                $this->audit('STORE_CREDIT_LEDGER_IDEMPOTENCY_REPLAYED', $existingAfterLock, $actor);

                return $existingAfterLock;
            }

            $this->assertAccountAllowsMovement($lockedAccount, $payload);
            $this->assertCurrencyMatches($lockedAccount, $payload['currency_code']);
            $this->assertSourceIsUnique($lockedAccount, $payload);
            $this->assertBalanceAllowsMovement($lockedAccount, $payload);

            $sequence = $this->sequenceService->allocate($lockedAccount);
            $now = now();

            $entry = StoreCreditLedgerEntry::create([
                'tenant_id' => $lockedAccount->tenant_id,
                'branch_id' => $payload['branch_id'],
                'customer_financial_account_id' => $lockedAccount->id,
                'ledger_sequence' => $sequence,
                'ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION,
                'ledger_category' => StoreCreditLedgerEntry::categoryForEntryType($payload['entry_type']),
                'entry_type' => $payload['entry_type'],
                'direction' => $payload['direction'],
                'amount_centavos' => $payload['amount_centavos'],
                'currency_code' => $payload['currency_code'],
                'source_type' => $payload['source_type'],
                'source_id' => $payload['source_id'],
                'source_reference' => $payload['source_reference'],
                'source_snapshot' => $payload['source_snapshot'],
                'idempotency_key' => $payload['idempotency_key'],
                'request_fingerprint' => $fingerprint,
                'fingerprint_version' => StoreCreditLedgerEntry::FINGERPRINT_VERSION,
                'business_date' => $payload['business_date'],
                'posted_by' => $actor?->id,
                'posted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->buildAccountingLiabilityPayload($entry);
            $this->audit('STORE_CREDIT_LEDGER_ENTRY_POSTED', $entry, $actor);

            return $entry;
        });
    }

    public function buildAccountingLiabilityPayload(StoreCreditLedgerEntry $entry): array
    {
        $account = $entry->customerFinancialAccount()->firstOrFail();

        return [
            'event_version' => 1,
            'ledger_entry_id' => $entry->id,
            'tenant_id' => $entry->tenant_id,
            'branch_id' => $entry->branch_id,
            'customer_financial_account_id' => $entry->customer_financial_account_id,
            'account_currency' => $account->currency_code,
            'entry_type' => $entry->entry_type,
            'ledger_category' => $entry->ledger_category,
            'direction' => $entry->direction,
            'amount_centavos' => $entry->amount_centavos,
            'currency_code' => $entry->currency_code,
            'business_date' => $entry->business_date?->toDateString(),
            'ledger_sequence' => $entry->ledger_sequence,
            'posted_at' => $entry->posted_at?->toISOString(),
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'ledger_schema_version' => $entry->ledger_schema_version,
        ];
    }

    private function normalizePostingPayload(CustomerFinancialAccount $account, array $data): array
    {
        $entryType = (string) ($data['entry_type'] ?? StoreCreditLedgerEntry::TYPE_REFUND_CREDIT);
        $direction = (string) ($data['direction'] ?? $this->directionForEntryType($entryType));
        $amount = $data['amount_centavos'] ?? null;

        if (!is_int($amount) || $amount <= 0) {
            throw new InvalidArgumentException('Store credit ledger amount must be a positive integer centavo value.');
        }

        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Store credit ledger idempotency key is required.');
        }

        if (!in_array($direction, [StoreCreditLedgerEntry::DIRECTION_CREDIT, StoreCreditLedgerEntry::DIRECTION_DEBIT], true)) {
            throw new InvalidArgumentException('Store credit ledger direction is invalid.');
        }

        if ($direction !== $this->directionForEntryType($entryType)) {
            throw new InvalidArgumentException('Store credit ledger direction does not match entry type.');
        }

        $snapshot = $data['source_snapshot'] ?? [];
        $snapshot['ledger_schema_version'] = StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION;

        return [
            'tenant_id' => $account->tenant_id,
            'branch_id' => $data['branch_id'] ?? null,
            'customer_financial_account_id' => $account->id,
            'entry_type' => $entryType,
            'direction' => $direction,
            'amount_centavos' => $amount,
            'currency_code' => strtoupper((string) ($data['currency_code'] ?? $account->currency_code)),
            'source_type' => (string) ($data['source_type'] ?? 'manual_foundation_test'),
            'source_id' => $data['source_id'] ?? null,
            'source_reference' => $data['source_reference'] ?? null,
            'source_snapshot' => $snapshot,
            'idempotency_key' => $idempotencyKey,
            'business_date' => Carbon::parse($data['business_date'] ?? now())->toDateString(),
            'ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION,
            'fingerprint_version' => StoreCreditLedgerEntry::FINGERPRINT_VERSION,
        ];
    }

    private function directionForEntryType(string $entryType): string
    {
        return match ($entryType) {
            StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            StoreCreditLedgerEntry::TYPE_ADMIN_CREDIT_ADJUSTMENT,
            StoreCreditLedgerEntry::TYPE_REVERSAL_CREDIT => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            StoreCreditLedgerEntry::TYPE_ADMIN_DEBIT_ADJUSTMENT,
            StoreCreditLedgerEntry::TYPE_REVERSAL_DEBIT,
            StoreCreditLedgerEntry::TYPE_EXPIRATION_DEBIT,
            StoreCreditLedgerEntry::TYPE_FORFEITURE_DEBIT => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            default => throw new InvalidArgumentException('Unsupported store credit ledger entry type.'),
        };
    }

    private function fingerprint(array $payload): string
    {
        $material = [
            'tenant_id' => $payload['tenant_id'],
            'branch_id' => $payload['branch_id'],
            'customer_financial_account_id' => $payload['customer_financial_account_id'],
            'entry_type' => $payload['entry_type'],
            'direction' => $payload['direction'],
            'amount_centavos' => $payload['amount_centavos'],
            'currency_code' => $payload['currency_code'],
            'source_type' => $payload['source_type'],
            'source_id' => $payload['source_id'],
            'source_reference' => $payload['source_reference'],
            'business_date' => $payload['business_date'],
            'ledger_schema_version' => $payload['ledger_schema_version'],
            'fingerprint_version' => $payload['fingerprint_version'],
        ];

        ksort($material);

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }

    private function assertAccountAllowsMovement(CustomerFinancialAccount $account, array $payload): void
    {
        if ($account->status === CustomerFinancialAccount::STATUS_CLOSED) {
            throw new StoreCreditLedgerAccountStateException('Closed customer financial accounts cannot receive store credit movement.');
        }

        if (
            $account->status === CustomerFinancialAccount::STATUS_SUSPENDED
            && $payload['direction'] === StoreCreditLedgerEntry::DIRECTION_DEBIT
        ) {
            throw new StoreCreditLedgerAccountStateException('Suspended customer financial accounts cannot post debit store credit movement.');
        }
    }

    private function assertCurrencyMatches(CustomerFinancialAccount $account, string $currencyCode): void
    {
        if ($currencyCode !== $account->currency_code) {
            throw new StoreCreditLedgerCurrencyMismatchException('Store credit ledger currency must match the account currency.');
        }
    }

    private function assertSourceIsUnique(CustomerFinancialAccount $account, array $payload): void
    {
        if (!$payload['source_type'] || !$payload['source_id']) {
            return;
        }

        $exists = StoreCreditLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->where('source_type', $payload['source_type'])
            ->where('source_id', $payload['source_id'])
            ->exists();

        if ($exists) {
            throw new StoreCreditLedgerSourceConflictException('Store credit ledger source was already posted.');
        }
    }

    private function assertBalanceAllowsMovement(CustomerFinancialAccount $account, array $payload): void
    {
        if ($payload['direction'] !== StoreCreditLedgerEntry::DIRECTION_DEBIT) {
            return;
        }

        $balance = $this->balanceService->availableBalanceCentavos($account);

        if ($balance - $payload['amount_centavos'] < 0) {
            throw new StoreCreditLedgerInsufficientBalanceException('Store credit debit cannot create a negative balance.');
        }
    }

    private function audit(string $action, StoreCreditLedgerEntry $entry, ?User $actor): void
    {
        $this->auditLogger->log(
            $action,
            $entry,
            null,
            [
                'id' => $entry->id,
                'customer_financial_account_id' => $entry->customer_financial_account_id,
                'entry_type' => $entry->entry_type,
                'ledger_category' => $entry->ledger_category,
                'direction' => $entry->direction,
                'amount_centavos' => $entry->amount_centavos,
                'currency_code' => $entry->currency_code,
                'ledger_sequence' => $entry->ledger_sequence,
                'business_date' => $entry->business_date?->toDateString(),
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'idempotency_fingerprint' => $entry->request_fingerprint,
                'event_version' => 1,
            ],
            metadata: ['event_version' => 1],
            actor: $actor
        );
    }
}
