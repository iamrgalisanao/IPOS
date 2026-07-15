<?php

namespace App\Services\StoreCredit;

use App\Exceptions\StoreCredit\StoreCreditRefundAccountConflictException;
use App\Exceptions\StoreCredit\StoreCreditRefundAlreadyIssuedException;
use App\Exceptions\StoreCredit\StoreCreditRefundCurrencyMismatchException;
use App\Exceptions\StoreCredit\StoreCreditRefundOfflineNotAllowedException;
use App\Models\CustomerFinancialAccount;
use App\Models\SaleRefund;
use App\Models\StoreCreditLedgerEntry;
use App\Models\StoreCreditRefundIssuance;
use App\Services\Accounting\AccountingOutboxService;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Values\POS\RefundPayoutCommand;

class StoreCreditRefundIssuer
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StoreCreditLedgerService $ledgerService,
        private readonly AccountingOutboxService $outboxService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function issue(SaleRefund $refund, RefundPayoutCommand $command): StoreCreditRefundIssuance
    {
        if (!$command->isStoreCredit()) {
            throw new StoreCreditRefundAccountConflictException('Refund payout command is not for store credit.');
        }

        if ($command->sourceChannel === 'offline') {
            throw new StoreCreditRefundOfflineNotAllowedException('Offline refund-to-store-credit issuance is not allowed.');
        }

        $requestKey = trim((string) $command->idempotencyKey);
        if ($requestKey === '') {
            throw new StoreCreditRefundAccountConflictException('Store credit refund idempotency key is required.');
        }

        if (StoreCreditRefundIssuance::query()->where('sale_refund_id', $refund->id)->exists()) {
            throw new StoreCreditRefundAlreadyIssuedException('This refund has already issued store credit.');
        }

        $account = CustomerFinancialAccount::query()
            ->whereKey($command->customerFinancialAccountId)
            ->first();

        if (!$account || $account->tenant_id !== $refund->tenant_id) {
            throw new StoreCreditRefundAccountConflictException('Customer financial account is not available for this refund.');
        }

        $tenantCurrency = strtoupper((string) ($this->tenantContext->getTenant()?->currency ?? $account->currency_code));
        if ($account->currency_code !== $tenantCurrency) {
            throw new StoreCreditRefundCurrencyMismatchException('Customer financial account currency does not match the refund currency.');
        }

        $amountCentavos = (int) round(((float) $refund->refund_total) * 100);
        $sourceSnapshot = $this->sourceSnapshot($refund, $account, $command, $amountCentavos, $requestKey);
        $ledgerKey = 'refund-credit:' . $refund->id . ':' . $requestKey;

        $ledgerEntry = $this->ledgerService->post($account, [
            'branch_id' => $refund->branch_id,
            'idempotency_key' => $ledgerKey,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REFUND_CREDIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_CREDIT,
            'amount_centavos' => $amountCentavos,
            'currency_code' => $account->currency_code,
            'source_type' => 'sale_refund',
            'source_id' => $refund->id,
            'source_reference' => $refund->refund_number ?: $refund->id,
            'source_snapshot' => $sourceSnapshot,
            'business_date' => $refund->refunded_at?->toDateString() ?? now()->toDateString(),
        ], $command->requestedBy);

        $issuedAt = now();
        $issuance = StoreCreditRefundIssuance::create([
            'tenant_id' => $refund->tenant_id,
            'branch_id' => $refund->branch_id,
            'sale_id' => $refund->sale_id,
            'sale_refund_id' => $refund->id,
            'customer_financial_account_id' => $account->id,
            'store_credit_ledger_entry_id' => $ledgerEntry->id,
            'amount_centavos' => $amountCentavos,
            'currency_code' => $account->currency_code,
            'idempotency_key' => $ledgerKey,
            'source_snapshot' => $sourceSnapshot,
            'issued_by' => $command->requestedBy?->id,
            'issued_at' => $issuedAt,
            'created_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ]);

        $this->outboxService->recordEvent(
            'store_credit_issued',
            $ledgerEntry,
            array_merge($this->ledgerService->buildAccountingLiabilityPayload($ledgerEntry), [
                'event_version' => 1,
                'sale_id' => $refund->sale_id,
                'sale_refund_id' => $refund->id,
                'refund_number' => $refund->refund_number ?: $refund->id,
                'refund_reason_code' => $refund->reason_code,
                'store_credit_refund_issuance_id' => $issuance->id,
                'source_snapshot_version' => StoreCreditRefundIssuance::SNAPSHOT_VERSION,
            ])
        );

        $this->auditLogger->log(
            'STORE_CREDIT_REFUND_ISSUED',
            $refund,
            null,
            [
                'sale_id' => $refund->sale_id,
                'sale_refund_id' => $refund->id,
                'customer_financial_account_id' => $account->id,
                'store_credit_ledger_entry_id' => $ledgerEntry->id,
                'amount_centavos' => $amountCentavos,
                'currency_code' => $account->currency_code,
                'reason_code' => $refund->reason_code,
                'idempotency_key_fingerprint' => hash('sha256', $ledgerKey),
                'approval_reference' => $command->approvalReference,
                'event_version' => 1,
            ],
            metadata: ['event_version' => 1],
            actor: $command->requestedBy
        );

        return $issuance->load('ledgerEntry');
    }

    private function sourceSnapshot(
        SaleRefund $refund,
        CustomerFinancialAccount $account,
        RefundPayoutCommand $command,
        int $amountCentavos,
        string $requestKey
    ): array {
        return [
            'snapshot_version' => StoreCreditRefundIssuance::SNAPSHOT_VERSION,
            'refund_schema_version' => StoreCreditRefundIssuance::REFUND_SCHEMA_VERSION,
            'ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION,
            'customer_account_schema_version' => StoreCreditRefundIssuance::CUSTOMER_ACCOUNT_SCHEMA_VERSION,
            'sale_id' => $refund->sale_id,
            'sale_refund_id' => $refund->id,
            'refund_number' => $refund->refund_number ?: $refund->id,
            'refund_reason_code' => $refund->reason_code,
            'refund_total' => (string) $refund->refund_total,
            'amount_centavos' => $amountCentavos,
            'currency_code' => $account->currency_code,
            'customer_financial_account_id' => $account->id,
            'idempotency_key_fingerprint' => hash('sha256', $requestKey),
            'issued_by' => $command->requestedBy?->id,
            'issued_at' => now()->toISOString(),
        ];
    }
}
