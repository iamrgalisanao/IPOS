<?php

namespace App\Services\POS\OfflineSync;

class OfflineConsequenceStatusBuilder
{
    public function empty(): array
    {
        return [
            'sale' => 'not_applicable',
            'payment' => 'not_applicable',
            'inventory' => 'not_applicable',
            'variance' => 'not_applicable',
            'loyalty' => 'not_applicable',
            'store_credit' => 'not_applicable',
            'receipt' => 'not_applicable',
            'accounting_outbox' => 'not_applicable',
            'business_date' => 'not_applicable',
        ];
    }

    public function accepted(array $overrides = []): array
    {
        return array_replace($this->empty(), [
            'sale' => 'committed',
            'payment' => 'committed',
            'inventory' => 'not_applicable',
            'variance' => 'not_applicable',
            'loyalty' => 'not_applicable',
            'store_credit' => 'not_applicable',
            'receipt' => 'pending',
            'accounting_outbox' => 'queued',
            'business_date' => 'committed',
        ], $overrides);
    }
}
