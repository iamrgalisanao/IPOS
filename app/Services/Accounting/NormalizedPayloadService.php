<?php

namespace App\Services\Accounting;

use App\Models\AccountingOutbox;
use App\Services\Accounting\Contracts\AccountingMapperInterface;

class NormalizedPayloadService
{
    public function __construct(
        protected AccountingMapperInterface $mapper
    ) {}

    public function normalize(AccountingOutbox $record): array
    {
        $payload = $record->payload;
        
        $base = [
            'event' => [
                'type' => $record->event_type,
                'source_type' => $record->source_type,
                'source_id' => $record->source_id,
                'occurred_at' => (string) $record->created_at,
            ],
            'context' => [
                'tenant_id' => $record->tenant_id,
                'branch_id' => $record->branch_id,
            ],
        ];

        return match ($record->event_type) {
            'sale_paid' => array_merge($base, $this->normalizeSalePaid($payload)),
            'sale_voided' => array_merge($base, $this->normalizeSaleVoided($payload)),
            'sale_refunded' => array_merge($base, $this->normalizeSaleRefunded($payload)),
            default => throw new \InvalidArgumentException("Unknown event type: {$record->event_type}")
        };
    }

    protected function normalizeSalePaid(array $payload): array
    {
        return [
            'header' => [
                'document_type' => 'sales_receipt',
                'document_number' => $payload['sale_number'],
                'currency' => 'PHP',
                'subtotal' => $payload['subtotal'],
                'tax_total' => $payload['tax_total'],
                'total' => $payload['total'],
            ],
            'lines' => collect($payload['items'] ?? [])->map(fn($item) => [
                'line_type' => 'item',
                'product_id' => $item['product_id'] ?? null,
                'mapped_item_id' => $this->mapper->mapProduct($item['product_id'] ?? null),
                'description' => $item['product_name'] ?? 'Item',
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
                'income_account' => $this->mapper->mapAccount('sales'),
            ])->toArray(),
            'taxes' => collect($payload['taxes'] ?? [])->map(fn($tax) => [
                'tax_category_id' => $tax['tax_category_id'],
                'mapped_tax_code' => $this->mapper->mapTaxCode($tax['tax_category_id']),
                'tax_rate' => $tax['tax_rate'],
                'tax_amount' => $tax['tax_amount'],
            ])->toArray(),
            'payments' => collect($payload['payments'] ?? [])->map(fn($pay) => [
                'payment_method_id' => $pay['method'],
                'mapped_payment_method' => $this->mapper->mapPaymentMethod($pay['method']),
                'amount' => $pay['amount'],
                'reference_number' => $pay['reference'] ?? null,
            ])->toArray(),
        ];
    }

    protected function normalizeSaleVoided(array $payload): array
    {
        return [
            'header' => [
                'document_type' => 'void_reversal',
                'document_number' => $payload['sale_number'],
                'currency' => 'PHP',
                'total' => $payload['total'],
            ],
            'payments' => collect($payload['payment_reversals'] ?? [])->map(fn($rev) => [
                'payment_method_id' => $rev['payment_method_id'],
                'mapped_payment_method' => $this->mapper->mapPaymentMethod($rev['payment_method_id']),
                'amount' => $rev['amount'],
                'reference_number' => $rev['reference'] ?? null,
            ])->toArray(),
        ];
    }

    protected function normalizeSaleRefunded(array $payload): array
    {
        return [
            'header' => [
                'document_type' => 'refund_credit',
                'document_number' => $payload['refund_number'],
                'currency' => 'PHP',
                'refund_total' => $payload['refund_total'],
            ],
            'lines' => collect($payload['refund_items'] ?? [])->map(fn($item) => [
                'line_type' => 'refund_item',
                'product_id' => $item['product_id'],
                'mapped_item_id' => $this->mapper->mapProduct($item['product_id']),
                'quantity' => $item['quantity_refunded'],
                'unit_price' => $item['unit_price_snapshot'],
                'line_total' => $item['line_refund_total'],
            ])->toArray(),
            'payments' => collect($payload['payment_reversals'] ?? [])->map(fn($rev) => [
                'payment_method_id' => $rev['payment_method_id'],
                'mapped_payment_method' => $this->mapper->mapPaymentMethod($rev['payment_method_id']),
                'amount' => $rev['amount'],
            ])->toArray(),
        ];
    }
}
