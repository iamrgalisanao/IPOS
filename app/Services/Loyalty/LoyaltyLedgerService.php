<?php

namespace App\Services\Loyalty;

use App\Exceptions\Loyalty\LoyaltyLedgerAccountStateException;
use App\Exceptions\Loyalty\LoyaltyLedgerIdempotencyDriftException;
use App\Exceptions\Loyalty\LoyaltyLedgerInsufficientBalanceException;
use App\Exceptions\Loyalty\LoyaltyLedgerSourceConflictException;
use App\Models\CustomerFinancialAccount;
use App\Models\LoyaltyLedgerEntry;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LoyaltyLedgerService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LoyaltyLedgerSequenceService $sequenceService,
        private readonly LoyaltyBalanceService $balanceService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function post(CustomerFinancialAccount $account, array $data, ?User $actor = null): LoyaltyLedgerEntry
    {
        $payload = $this->normalizePostingPayload($account, $data);
        $fingerprint = $this->fingerprint($payload);

        $existing = LoyaltyLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->where('idempotency_key', $payload['idempotency_key'])
            ->first();

        if ($existing) {
            if ($existing->request_fingerprint !== $fingerprint) {
                $this->audit('LOYALTY_LEDGER_IDEMPOTENCY_DRIFT_REJECTED', $existing, $actor);
                throw new LoyaltyLedgerIdempotencyDriftException('The idempotency key was already used with different loyalty ledger details.');
            }

            $this->audit('LOYALTY_LEDGER_IDEMPOTENCY_REPLAYED', $existing, $actor);

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

            $existingAfterLock = LoyaltyLedgerEntry::query()
                ->where('customer_financial_account_id', $lockedAccount->id)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->first();

            if ($existingAfterLock) {
                if ($existingAfterLock->request_fingerprint !== $fingerprint) {
                    $this->audit('LOYALTY_LEDGER_IDEMPOTENCY_DRIFT_REJECTED', $existingAfterLock, $actor);
                    throw new LoyaltyLedgerIdempotencyDriftException('The idempotency key was already used with different loyalty ledger details.');
                }

                $this->audit('LOYALTY_LEDGER_IDEMPOTENCY_REPLAYED', $existingAfterLock, $actor);

                return $existingAfterLock;
            }

            $this->assertAccountAllowsMovement($lockedAccount, $payload);
            $this->assertSourceIsUnique($lockedAccount, $payload);
            $this->assertBalanceAllowsMovement($lockedAccount, $payload);

            $sequence = $this->sequenceService->allocate($lockedAccount);
            $now = now();

            $entry = LoyaltyLedgerEntry::create([
                'tenant_id' => $lockedAccount->tenant_id,
                'branch_id' => $payload['branch_id'],
                'customer_financial_account_id' => $lockedAccount->id,
                'ledger_sequence' => $sequence,
                'ledger_schema_version' => LoyaltyLedgerEntry::LEDGER_SCHEMA_VERSION,
                'ledger_category' => LoyaltyLedgerEntry::categoryForEntryType($payload['entry_type']),
                'entry_type' => $payload['entry_type'],
                'direction' => $payload['direction'],
                'points' => $payload['points'],
                'source_type' => $payload['source_type'],
                'source_id' => $payload['source_id'],
                'source_reference' => $payload['source_reference'],
                'source_snapshot' => $payload['source_snapshot'],
                'idempotency_key' => $payload['idempotency_key'],
                'request_fingerprint' => $fingerprint,
                'fingerprint_version' => LoyaltyLedgerEntry::FINGERPRINT_VERSION,
                'business_date' => $payload['business_date'],
                'posted_by' => $actor?->id,
                'posted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->audit('LOYALTY_LEDGER_ENTRY_POSTED', $entry, $actor);

            return $entry;
        });
    }

    private function normalizePostingPayload(CustomerFinancialAccount $account, array $data): array
    {
        $entryType = (string) ($data['entry_type'] ?? LoyaltyLedgerEntry::TYPE_SALE_ACCRUAL);
        $direction = (string) ($data['direction'] ?? $this->directionForEntryType($entryType));
        $points = $data['points'] ?? null;

        if (!is_int($points) || $points <= 0) {
            throw new InvalidArgumentException('Loyalty ledger points must be a positive integer value.');
        }

        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Loyalty ledger idempotency key is required.');
        }

        if (!in_array($direction, [LoyaltyLedgerEntry::DIRECTION_CREDIT, LoyaltyLedgerEntry::DIRECTION_DEBIT], true)) {
            throw new InvalidArgumentException('Loyalty ledger direction is invalid.');
        }

        if ($direction !== $this->directionForEntryType($entryType)) {
            throw new InvalidArgumentException('Loyalty ledger direction does not match entry type.');
        }

        $snapshot = $data['source_snapshot'] ?? [];
        $snapshot['ledger_schema_version'] = LoyaltyLedgerEntry::LEDGER_SCHEMA_VERSION;

        return [
            'tenant_id' => $account->tenant_id,
            'branch_id' => $data['branch_id'] ?? null,
            'customer_financial_account_id' => $account->id,
            'entry_type' => $entryType,
            'direction' => $direction,
            'points' => $points,
            'source_type' => (string) ($data['source_type'] ?? 'manual_foundation_test'),
            'source_id' => $data['source_id'] ?? null,
            'source_reference' => $data['source_reference'] ?? null,
            'source_snapshot' => $snapshot,
            'idempotency_key' => $idempotencyKey,
            'business_date' => Carbon::parse($data['business_date'] ?? now())->toDateString(),
            'allow_negative_balance' => (bool) ($data['allow_negative_balance'] ?? false),
            'ledger_schema_version' => LoyaltyLedgerEntry::LEDGER_SCHEMA_VERSION,
            'fingerprint_version' => LoyaltyLedgerEntry::FINGERPRINT_VERSION,
        ];
    }

    private function directionForEntryType(string $entryType): string
    {
        return match ($entryType) {
            LoyaltyLedgerEntry::TYPE_SALE_ACCRUAL,
            LoyaltyLedgerEntry::TYPE_ADMIN_CREDIT_ADJUSTMENT,
            LoyaltyLedgerEntry::TYPE_REVERSAL_CREDIT,
            LoyaltyLedgerEntry::TYPE_VOID_REDEMPTION_RESTORE,
            LoyaltyLedgerEntry::TYPE_REFUND_REDEMPTION_RESTORE => LoyaltyLedgerEntry::DIRECTION_CREDIT,
            LoyaltyLedgerEntry::TYPE_REDEMPTION_DEBIT,
            LoyaltyLedgerEntry::TYPE_ADMIN_DEBIT_ADJUSTMENT,
            LoyaltyLedgerEntry::TYPE_REVERSAL_DEBIT,
            LoyaltyLedgerEntry::TYPE_VOID_EARN_REVERSAL,
            LoyaltyLedgerEntry::TYPE_REFUND_EARN_REVERSAL,
            LoyaltyLedgerEntry::TYPE_EXPIRATION_DEBIT,
            LoyaltyLedgerEntry::TYPE_FORFEITURE_DEBIT => LoyaltyLedgerEntry::DIRECTION_DEBIT,
            default => throw new InvalidArgumentException('Unsupported loyalty ledger entry type.'),
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
            'points' => $payload['points'],
            'source_type' => $payload['source_type'],
            'source_id' => $payload['source_id'],
            'source_reference' => $payload['source_reference'],
            'business_date' => $payload['business_date'],
            'allow_negative_balance' => $payload['allow_negative_balance'],
            'ledger_schema_version' => $payload['ledger_schema_version'],
            'fingerprint_version' => $payload['fingerprint_version'],
        ];

        ksort($material);

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }

    private function assertAccountAllowsMovement(CustomerFinancialAccount $account, array $payload): void
    {
        if ($account->status === CustomerFinancialAccount::STATUS_CLOSED) {
            throw new LoyaltyLedgerAccountStateException('Closed customer financial accounts cannot receive loyalty movement.');
        }

        if (
            $account->status === CustomerFinancialAccount::STATUS_SUSPENDED
            && $payload['direction'] === LoyaltyLedgerEntry::DIRECTION_DEBIT
        ) {
            throw new LoyaltyLedgerAccountStateException('Suspended customer financial accounts cannot post debit loyalty movement.');
        }
    }

    private function assertSourceIsUnique(CustomerFinancialAccount $account, array $payload): void
    {
        if (!$payload['source_type'] || !$payload['source_id']) {
            return;
        }

        if ($this->isReversalEntryType($payload['entry_type'])) {
            return;
        }

        $exists = LoyaltyLedgerEntry::query()
            ->where('customer_financial_account_id', $account->id)
            ->where('source_type', $payload['source_type'])
            ->where('source_id', $payload['source_id'])
            ->exists();

        if ($exists) {
            throw new LoyaltyLedgerSourceConflictException('Loyalty ledger source was already posted.');
        }
    }

    private function assertBalanceAllowsMovement(CustomerFinancialAccount $account, array $payload): void
    {
        if ($payload['direction'] !== LoyaltyLedgerEntry::DIRECTION_DEBIT) {
            return;
        }

        if ($payload['allow_negative_balance'] && $this->isNegativeBalanceAllowedEntryType($payload['entry_type'])) {
            return;
        }

        if ($this->balanceService->availablePoints($account) - $payload['points'] < 0) {
            throw new LoyaltyLedgerInsufficientBalanceException('Loyalty debit cannot create a negative points balance.');
        }
    }

    private function isReversalEntryType(string $entryType): bool
    {
        return in_array($entryType, [
            LoyaltyLedgerEntry::TYPE_REVERSAL_CREDIT,
            LoyaltyLedgerEntry::TYPE_REVERSAL_DEBIT,
            LoyaltyLedgerEntry::TYPE_VOID_EARN_REVERSAL,
            LoyaltyLedgerEntry::TYPE_REFUND_EARN_REVERSAL,
            LoyaltyLedgerEntry::TYPE_VOID_REDEMPTION_RESTORE,
            LoyaltyLedgerEntry::TYPE_REFUND_REDEMPTION_RESTORE,
        ], true);
    }

    private function isNegativeBalanceAllowedEntryType(string $entryType): bool
    {
        return in_array($entryType, [
            LoyaltyLedgerEntry::TYPE_VOID_EARN_REVERSAL,
            LoyaltyLedgerEntry::TYPE_REFUND_EARN_REVERSAL,
        ], true);
    }

    private function audit(string $action, LoyaltyLedgerEntry $entry, ?User $actor): void
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
                'points' => $entry->points,
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
