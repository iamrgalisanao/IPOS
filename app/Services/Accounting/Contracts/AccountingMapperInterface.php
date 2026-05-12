<?php

namespace App\Services\Accounting\Contracts;

interface AccountingMapperInterface
{
    /**
     * Map internal account types (e.g., 'sales', 'ar', 'cash') to accounting system identifiers.
     */
    public function mapAccount(string $type): string;

    /**
     * Map POS tax category UUID to accounting tax code identifier.
     */
    public function mapTaxCode(string $posTaxCategoryId): string;

    /**
     * Map POS payment method UUID to accounting payment method identifier.
     */
    public function mapPaymentMethod(string $posPaymentMethodId): string;

    /**
     * Map POS product UUID to accounting item/product identifier.
     */
    public function mapProduct(?string $posProductId): ?string;

    /**
     * Map POS customer UUID to accounting customer identifier.
     */
    public function mapCustomer(?string $posCustomerId): ?string;
}
