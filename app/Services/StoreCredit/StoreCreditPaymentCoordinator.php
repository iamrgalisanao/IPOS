<?php

namespace App\Services\StoreCredit;

use App\Exceptions\StoreCredit\StoreCreditAlreadyRedeemedException;
use App\Exceptions\StoreCredit\StoreCreditLedgerAccountStateException;
use App\Exceptions\StoreCredit\StoreCreditLedgerCurrencyMismatchException;
use App\Exceptions\StoreCredit\StoreCreditLedgerInsufficientBalanceException;
use App\Models\CustomerFinancialAccount;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StoreCreditLedgerEntry;
use App\Models\StoreCreditRedemption;
use App\Models\User;
use App\Services\Accounting\AccountingOutboxService;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Validation\ValidationException;

class StoreCreditPaymentCoordinator
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StoreCreditLedgerService $ledgerService,
        private readonly StoreCreditBalanceService $balanceService,
        private readonly AccountingOutboxService $outboxService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $paymentsData
     * @return array<int,array<string,mixed>>
     */
    public function preflight(Sale $sale, array $paymentsData, User $actor, ?string $idempotencyKey): array
    {
        $intents = [];

        foreach ($paymentsData as $index => $data) {
            $paymentMethod = PaymentMethod::query()
                ->whereKey($data['payment_method_id'] ?? null)
                ->active()
                ->first();

            if (!$paymentMethod?->isStoreCredit()) {
                continue;
            }

            if ($idempotencyKey === null || trim($idempotencyKey) === '') {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Store credit redemption requires an Idempotency-Key header.'],
                ]);
            }

            if ($intents !== []) {
                throw ValidationException::withMessages([
                    'payments' => ['Only one store credit tender line is allowed per sale.'],
                ]);
            }

            $accountId = $data['customer_financial_account_id'] ?? null;
            if (!$accountId) {
                throw ValidationException::withMessages([
                    "payments.{$index}.customer_financial_account_id" => ['A customer financial account is required for store credit redemption.'],
                ]);
            }

            $account = CustomerFinancialAccount::query()->whereKey($accountId)->first();
            if (!$account || $account->tenant_id !== $sale->tenant_id) {
                throw ValidationException::withMessages([
                    "payments.{$index}.customer_financial_account_id" => ['The customer financial account is not available for this sale.'],
                ]);
            }

            if ($account->status !== CustomerFinancialAccount::STATUS_ACTIVE) {
                throw new StoreCreditLedgerAccountStateException('Store credit account is not redeemable.');
            }

            $tenantCurrency = strtoupper((string) ($this->tenantContext->getTenant()?->currency ?? $account->currency_code));
            if ($account->currency_code !== $tenantCurrency) {
                throw new StoreCreditLedgerCurrencyMismatchException('Store credit account currency does not match the sale currency.');
            }

            $amountCentavos = $this->amountToCentavos($data['amount']);
            $available = $this->balanceService->availableBalanceCentavos($account);

            if ($available < $amountCentavos) {
                throw new StoreCreditLedgerInsufficientBalanceException('The customer does not have enough available store credit for this redemption.');
            }

            $intents[$index] = [
                'account' => $account,
                'payment_method' => $paymentMethod,
                'amount_centavos' => $amountCentavos,
                'authorized_balance_centavos' => $available,
                'authorization' => $data['store_credit_authorization'] ?? [],
                'idempotency_key' => trim($idempotencyKey),
                'actor' => $actor,
            ];
        }

        return $intents;
    }

    /**
     * @param array<string,mixed> $intent
     */
    public function redeem(Sale $sale, SalePayment $payment, array $intent): StoreCreditRedemption
    {
        if (StoreCreditRedemption::query()->where('sale_payment_id', $payment->id)->exists()) {
            throw new StoreCreditAlreadyRedeemedException('This store credit payment has already been redeemed.');
        }

        /** @var CustomerFinancialAccount $account */
        $account = $intent['account'];
        $account = CustomerFinancialAccount::query()
            ->whereKey($account->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($account->status !== CustomerFinancialAccount::STATUS_ACTIVE) {
            throw new StoreCreditLedgerAccountStateException('Store credit account is not redeemable.');
        }

        $amountCentavos = (int) $intent['amount_centavos'];
        $currentAvailable = $this->balanceService->availableBalanceCentavos($account);
        if ($currentAvailable < $amountCentavos) {
            throw new StoreCreditLedgerInsufficientBalanceException('The customer does not have enough available store credit for this redemption.');
        }

        $ledgerKey = 'redemption-debit:' . $payment->id . ':' . $intent['idempotency_key'];
        $snapshot = $this->sourceSnapshot($sale, $payment, $account, $intent, $currentAvailable, $ledgerKey);

        $ledgerEntry = $this->ledgerService->post($account, [
            'branch_id' => $sale->branch_id,
            'idempotency_key' => $ledgerKey,
            'entry_type' => StoreCreditLedgerEntry::TYPE_REDEMPTION_DEBIT,
            'direction' => StoreCreditLedgerEntry::DIRECTION_DEBIT,
            'amount_centavos' => $amountCentavos,
            'currency_code' => $account->currency_code,
            'source_type' => 'sale_payment',
            'source_id' => $payment->id,
            'source_reference' => $sale->sale_number ?: $sale->id,
            'source_snapshot' => $snapshot,
            'business_date' => $payment->paid_at?->toDateString() ?? now()->toDateString(),
        ], $intent['actor']);

        $redeemedAt = now();
        $redemption = StoreCreditRedemption::create([
            'tenant_id' => $sale->tenant_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
            'customer_financial_account_id' => $account->id,
            'store_credit_ledger_entry_id' => $ledgerEntry->id,
            'amount_centavos' => $amountCentavos,
            'currency_code' => $account->currency_code,
            'idempotency_key' => $ledgerKey,
            'authorized_balance_centavos' => (int) $intent['authorized_balance_centavos'],
            'source_snapshot' => $snapshot,
            'redeemed_by' => $intent['actor']?->id,
            'redeemed_at' => $redeemedAt,
            'created_at' => $redeemedAt,
            'updated_at' => $redeemedAt,
        ]);

        if (!$sale->is_training_mode) {
            $this->outboxService->recordEvent(
                'store_credit_redeemed',
                $ledgerEntry,
                array_merge($this->ledgerService->buildAccountingLiabilityPayload($ledgerEntry), [
                    'event_version' => 1,
                    'sale_id' => $sale->id,
                    'sale_number' => $sale->sale_number,
                    'sale_payment_id' => $payment->id,
                    'payment_method_code' => $payment->paymentMethod?->code,
                    'store_credit_redemption_id' => $redemption->id,
                    'customer_financial_account_id' => $account->id,
                    'ledger_entry_id' => $ledgerEntry->id,
                    'amount_centavos' => $amountCentavos,
                    'currency_code' => $account->currency_code,
                    'authorized_balance_centavos' => (int) $intent['authorized_balance_centavos'],
                    'redeemed_at' => $redeemedAt->toISOString(),
                    'source_snapshot_version' => StoreCreditRedemption::SNAPSHOT_VERSION,
                ])
            );
        }

        $this->auditLogger->log(
            'STORE_CREDIT_REDEEMED',
            $payment,
            null,
            [
                'sale_id' => $sale->id,
                'sale_payment_id' => $payment->id,
                'customer_financial_account_id' => $account->id,
                'store_credit_ledger_entry_id' => $ledgerEntry->id,
                'amount_centavos' => $amountCentavos,
                'authorized_balance_centavos' => (int) $intent['authorized_balance_centavos'],
                'currency_code' => $account->currency_code,
                'event_version' => 1,
            ],
            metadata: ['event_version' => 1],
            actor: $intent['actor']
        );

        return $redemption->load('ledgerEntry');
    }

    public function amountToCentavos(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * @param array<string,mixed> $intent
     */
    private function sourceSnapshot(
        Sale $sale,
        SalePayment $payment,
        CustomerFinancialAccount $account,
        array $intent,
        int $currentAvailable,
        string $ledgerKey
    ): array {
        $authorization = (array) ($intent['authorization'] ?? []);
        $paymentMethod = $payment->paymentMethod;

        return [
            'snapshot_version' => StoreCreditRedemption::SNAPSHOT_VERSION,
            'payment_schema_version' => StoreCreditRedemption::PAYMENT_SCHEMA_VERSION,
            'ledger_schema_version' => StoreCreditLedgerEntry::LEDGER_SCHEMA_VERSION,
            'customer_account_schema_version' => StoreCreditRedemption::CUSTOMER_ACCOUNT_SCHEMA_VERSION,
            'authorization_schema_version' => StoreCreditRedemption::AUTHORIZATION_SCHEMA_VERSION,
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'sale_total' => (string) $sale->total,
            'sale_status_before_payment' => (string) $sale->status,
            'sale_payment_id' => $payment->id,
            'payment_method_id' => $payment->payment_method_id,
            'payment_method_code' => $paymentMethod?->code,
            'customer_financial_account_id' => $account->id,
            'amount_centavos' => (int) $intent['amount_centavos'],
            'currency_code' => $account->currency_code,
            'authorized_balance_centavos' => (int) $intent['authorized_balance_centavos'],
            'transaction_balance_centavos' => $currentAvailable,
            'business_date' => $payment->paid_at?->toDateString() ?? now()->toDateString(),
            'cashier_id' => $intent['actor']?->id,
            'terminal_id' => null,
            'shift_id' => $payment->shift_id,
            'branch_id' => $sale->branch_id,
            'idempotency_key_fingerprint' => hash('sha256', $ledgerKey),
            'verification_method' => $authorization['verification_method'] ?? 'cashier_confirmed_customer',
            'verification_reference_masked' => $authorization['verification_reference'] ?? null,
            'future_reversal_source_reference' => $payment->id,
            'redeemed_at' => now()->toISOString(),
        ];
    }
}
