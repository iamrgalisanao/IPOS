<?php

namespace App\Services\Accounting;

use App\Services\Accounting\Contracts\AccountingMapperInterface;

class StaticAccountingMapper implements AccountingMapperInterface
{
    public function mapAccount(string $type): string
    {
        return match ($type) {
            'sales' => 'ACCOUNT_SALES',
            'ar' => 'ACCOUNT_AR',
            'cash' => 'ACCOUNT_CASH',
            'vat_payable' => 'ACCOUNT_VAT_PAYABLE',
            default => 'ACCOUNT_DEFAULT'
        };
    }

    public function mapTaxCode(string $posTaxCategoryId): string
    {
        // For Story 8.5, we return a predictable mapping based on ID or prefix
        return 'TAX_CODE_' . substr($posTaxCategoryId, 0, 8);
    }

    public function mapPaymentMethod(string $posPaymentMethodId): string
    {
        return 'PAYMENT_METHOD_' . substr($posPaymentMethodId, 0, 8);
    }

    public function mapProduct(?string $posProductId): ?string
    {
        return $posProductId ? 'ITEM_' . substr($posProductId, 0, 8) : 'ITEM_DEFAULT';
    }

    public function mapCustomer(?string $posCustomerId): ?string
    {
        return $posCustomerId ? 'CUST_' . substr($posCustomerId, 0, 8) : 'CUST_DEFAULT';
    }
}
