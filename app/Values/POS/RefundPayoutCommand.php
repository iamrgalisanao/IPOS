<?php

namespace App\Values\POS;

use App\Models\User;

class RefundPayoutCommand
{
    public const METHOD_CASH = 'cash';
    public const METHOD_ELECTRONIC = 'electronic';
    public const METHOD_CASH_EXCEPTION = 'cash_exception';
    public const METHOD_STORE_CREDIT = 'store_credit';

    public function __construct(
        public readonly string $payoutMethod,
        public readonly ?string $customerFinancialAccountId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?User $requestedBy = null,
        public readonly ?string $approvalReference = null,
        public readonly ?string $sourceChannel = 'pos',
    ) {
    }

    public function isStoreCredit(): bool
    {
        return $this->payoutMethod === self::METHOD_STORE_CREDIT;
    }
}
