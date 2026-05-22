<?php

namespace App\Services\POS;

use App\Models\Sale;
use App\Models\SaleItem;

class ReceiptService
{
    /**
     * Format a sale and its items for receipt generation.
     * 
     * Strictly read-only.
     * Uses immutable snapshots from sale_items.
     * Excludes cost and accounting metadata.
     */
    public function getReceiptData(Sale $sale): array
    {
        $sale->load(['items', 'tenant', 'branch', 'user', 'payments.paymentMethod']);

        return [
            'sale_id'             => $sale->id,
            'sale_number'         => $sale->sale_number,
            'client_request_uuid' => $sale->client_request_uuid,
            'receipt_reference'   => $sale->sale_number ?: $sale->client_request_uuid,
            'created_at'          => $sale->created_at->toDateTimeString(),
            'cashier_name'        => $sale->user ? $sale->user->name : 'N/A',
            'receipt_print_count' => (int) $sale->receipt_print_count,
            'last_reprint_reason' => $sale->last_reprint_reason,
            'is_reprint'          => $sale->receipt_print_count > 1,
            'reprint_watermark'   => $sale->receipt_print_count > 1 ? '*** REPRINT / DUPLICATE ***' : null,
            'is_training_mode'    => (bool) $sale->is_training_mode,
            'training_watermark'  => $sale->is_training_mode ? '*** TRAINING MODE - NOT A VALID INVOICE ***' : null,

            'tenant' => [
                'business_name'                => $sale->tenant->name,
                'business_registration_number' => $sale->tenant->business_registration_number,
                'receipt_header'               => $sale->tenant->receipt_header,
                'receipt_footer'               => $sale->tenant->receipt_footer,
            ],

            'branch' => [
                'branch_name'           => $sale->branch->name,
                'branch_code'           => $sale->branch->branch_code,
                'branch_address'        => $sale->branch->address,
                'branch_contact_number' => $sale->branch->contact_number,
            ],

            'items' => $sale->items->map(fn(SaleItem $item) => [
                'product_name'    => $item->product_name,
                'sku'             => $item->sku,
                'barcode'         => $item->barcode,
                'unit_of_measure' => $item->unit_of_measure,
                'quantity'        => (float) $item->quantity,
                'unit_price'      => (float) $item->unit_price,
                'subtotal'        => (float) $item->subtotal,
                'discount_amount' => (float) $item->discount_amount,
                'tax_type'        => $item->tax_type,
                'tax_rate'        => (float) $item->tax_rate,
                'tax_amount'      => (float) $item->tax_amount,
                'line_total'      => (float) $item->line_total,
            ])->toArray(),

            'totals' => [
                'subtotal'       => (float) $sale->subtotal,
                'discount_total' => (float) $sale->discount_total,
                'tax_total'      => (float) $sale->tax_total,
                'total'          => (float) $sale->total,
                'total_paid'     => (float) $sale->payments->sum('amount'),
            ],

            'payments' => $sale->payments->map(fn($payment) => [
                'payment_id'       => $payment->id,
                'method_name'      => $payment->paymentMethod->name,
                'method_type'      => $payment->paymentMethod->type,
                'amount'           => (float) $payment->amount,
                'reference_number' => $payment->reference_number,
                'paid_at'          => $payment->created_at->toDateTimeString(),
            ])->toArray(),
        ];
    }
}
