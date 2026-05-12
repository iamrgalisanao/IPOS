<?php

namespace App\Services\Accounting;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Services\BranchContext;
use App\Services\TenantContext;
use InvalidArgumentException;
use RuntimeException;

class QuickBooksPayloadBuilderService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected NormalizedPayloadService $normalizer
    ) {}

    public function build(AccountingOutbox $record): array
    {
        $this->assertTenantScope($record);

        $previousBranch = $this->branchContext->getBranch();
        $this->activateBranchContext($record);

        try {
            $normalized = $this->normalizer->normalize($record);

            return match ($record->event_type) {
                'sale_paid' => $this->buildSalesReceipt($normalized),
                'sale_refunded' => $this->buildRefundReceipt($normalized),
                'sale_voided' => $this->buildVoidCommand($normalized),
                default => throw new InvalidArgumentException("Unsupported QuickBooks event type: {$record->event_type}"),
            };
        } finally {
            $previousBranch
                ? $this->branchContext->setBranch($previousBranch)
                : $this->branchContext->clear();
        }
    }

    protected function buildSalesReceipt(array $normalized): array
    {
        $payment = $normalized['payments'][0] ?? null;

        return [
            'provider' => 'quickbooks',
            'entity' => 'SalesReceipt',
            'operation' => 'create',
            'idempotency_key' => $this->idempotencyKey($normalized),
            'tenant_id' => $normalized['context']['tenant_id'],
            'branch_id' => $normalized['context']['branch_id'],
            'payload' => array_filter([
                'DocNumber' => $normalized['header']['document_number'],
                'CurrencyRef' => ['value' => $normalized['header']['currency']],
                'TotalAmt' => $this->money($normalized['header']['total']),
                'Line' => $this->salesLines($normalized),
                'TxnTaxDetail' => $this->taxDetail($normalized),
                'PaymentMethodRef' => $payment ? ['value' => $this->requireMapped($payment['mapped_payment_method'], 'payment method')] : null,
                'PrivateNote' => 'IPOS sale ' . $normalized['event']['source_id'],
            ]),
        ];
    }

    protected function buildRefundReceipt(array $normalized): array
    {
        $payment = $normalized['payments'][0] ?? null;

        return [
            'provider' => 'quickbooks',
            'entity' => 'RefundReceipt',
            'operation' => 'create',
            'idempotency_key' => $this->idempotencyKey($normalized),
            'tenant_id' => $normalized['context']['tenant_id'],
            'branch_id' => $normalized['context']['branch_id'],
            'payload' => array_filter([
                'DocNumber' => $normalized['header']['document_number'],
                'CurrencyRef' => ['value' => $normalized['header']['currency']],
                'TotalAmt' => $this->money($normalized['header']['refund_total']),
                'Line' => $this->refundLines($normalized),
                'PaymentMethodRef' => $payment ? ['value' => $this->requireMapped($payment['mapped_payment_method'], 'payment method')] : null,
                'PrivateNote' => 'IPOS refund ' . $normalized['event']['source_id'],
            ]),
        ];
    }

    protected function buildVoidCommand(array $normalized): array
    {
        return [
            'provider' => 'quickbooks',
            'entity' => 'SalesReceipt',
            'operation' => 'void',
            'idempotency_key' => $this->idempotencyKey($normalized),
            'tenant_id' => $normalized['context']['tenant_id'],
            'branch_id' => $normalized['context']['branch_id'],
            'payload' => [
                'DocNumber' => $normalized['header']['document_number'],
                'TotalAmt' => $this->money($normalized['header']['total']),
                'PrivateNote' => 'IPOS void ' . $normalized['event']['source_id'],
                'PaymentReversals' => collect($normalized['payments'])->map(fn(array $payment) => [
                    'PaymentMethodRef' => ['value' => $this->requireMapped($payment['mapped_payment_method'], 'payment method')],
                    'Amount' => $this->money($payment['amount']),
                    'ReferenceNumber' => $payment['reference_number'] ?? null,
                ])->values()->all(),
            ],
        ];
    }

    protected function salesLines(array $normalized): array
    {
        return collect($normalized['lines'])->map(fn(array $line) => [
            'DetailType' => 'SalesItemLineDetail',
            'Description' => $line['description'],
            'Amount' => $this->money($line['line_total']),
            'SalesItemLineDetail' => [
                'ItemRef' => ['value' => $this->requireMapped($line['mapped_item_id'], 'item')],
                'Qty' => $this->quantity($line['quantity']),
                'UnitPrice' => $this->money($line['unit_price']),
                'TaxCodeRef' => ['value' => $this->taxCodeForProductLine($normalized, $line)],
            ],
        ])->values()->all();
    }

    protected function refundLines(array $normalized): array
    {
        return collect($normalized['lines'])->map(fn(array $line) => [
            'DetailType' => 'SalesItemLineDetail',
            'Amount' => $this->money($line['line_total']),
            'SalesItemLineDetail' => [
                'ItemRef' => ['value' => $this->requireMapped($line['mapped_item_id'], 'item')],
                'Qty' => $this->quantity($line['quantity']),
                'UnitPrice' => $this->money($line['unit_price']),
            ],
        ])->values()->all();
    }

    protected function taxDetail(array $normalized): ?array
    {
        if (empty($normalized['taxes'])) {
            return null;
        }

        return [
            'TotalTax' => $this->money($normalized['header']['tax_total']),
            'TaxLine' => collect($normalized['taxes'])->map(fn(array $tax) => [
                'Amount' => $this->money($tax['tax_amount']),
                'DetailType' => 'TaxLineDetail',
                'TaxLineDetail' => [
                    'TaxRateRef' => ['value' => $this->requireMapped($tax['mapped_tax_code'], 'tax code')],
                    'PercentBased' => true,
                    'TaxPercent' => $this->money($tax['tax_rate']),
                    'NetAmountTaxable' => $this->money($normalized['header']['subtotal']),
                ],
            ])->values()->all(),
        ];
    }

    protected function taxCodeForProductLine(array $normalized, array $line): string
    {
        $tax = $normalized['taxes'][0] ?? null;

        return $tax
            ? $this->requireMapped($tax['mapped_tax_code'], 'tax code')
            : 'NON';
    }

    protected function idempotencyKey(array $normalized): string
    {
        return implode(':', [
            'ipos',
            $normalized['context']['tenant_id'],
            $normalized['event']['type'],
            $normalized['event']['source_id'],
        ]);
    }

    protected function assertTenantScope(AccountingOutbox $record): void
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new RuntimeException('Tenant context is required to build QuickBooks payloads.');
        }

        if ($record->tenant_id !== $this->tenantContext->getTenantId()) {
            throw new RuntimeException('Cannot build QuickBooks payload for another tenant.');
        }
    }

    protected function activateBranchContext(AccountingOutbox $record): void
    {
        if (blank($record->branch_id)) {
            $this->branchContext->clear();

            return;
        }

        $branch = Branch::find($record->branch_id);

        if ($branch) {
            $this->branchContext->setBranch($branch);
        }
    }

    protected function requireMapped(?string $value, string $label): string
    {
        if (blank($value)) {
            throw new RuntimeException("Missing QuickBooks mapping for {$label}.");
        }

        return $value;
    }

    protected function money(string|int|float|null $value): float
    {
        return round((float) $value, 2);
    }

    protected function quantity(string|int|float|null $value): float
    {
        return round((float) $value, 4);
    }
}
