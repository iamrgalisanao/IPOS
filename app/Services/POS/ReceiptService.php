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
        $sale->load([
            'items',
            'tenant',
            'branch',
            'user',
            'payments.paymentMethod',
            'saleDiscounts.discountType',
            'saleDiscounts.beneficiaries',
            'salePromotions.lines.saleItem',
        ]);

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
                'subtotal'                  => (float) $sale->subtotal,
                'discount_total'            => (float) $sale->discount_total,
                'statutory_discount_total'  => (float) $sale->statutory_discount_total,
                'commercial_discount_total' => (float) $sale->commercial_discount_total,
                'tax_total'                 => (float) $sale->tax_total,
                'total'                     => (float) $sale->total,
                'total_paid'                => (float) $sale->payments->sum('amount'),
            ],

            'promotions' => $sale->salePromotions
                ->where('is_suppressed', false)
                ->values()
                ->map(fn ($promotion) => [
                    'id' => $promotion->id,
                    'promotion_id' => $promotion->promotion_id,
                    'promotion_rule_id' => $promotion->promotion_rule_id,
                    'name' => $promotion->promotion_name,
                    'rule_type' => $promotion->rule_type,
                    'condition_type' => $promotion->condition_type,
                    'reward_type' => $promotion->reward_type,
                    'discount_amount' => $this->centavosToDecimal($promotion->discount_amount_centavos),
                    'base_amount' => $this->centavosToDecimal($promotion->base_amount_centavos),
                    'lines' => $promotion->lines->map(fn ($line) => [
                        'sale_item_id' => $line->sale_item_id,
                        'product_name' => $line->saleItem?->product_name,
                        'role' => $line->role,
                        'quantity_applied' => (float) $line->quantity_applied,
                        'discount_amount' => $this->centavosToDecimal($line->discount_amount_centavos),
                        'original_amount' => $this->centavosToDecimal($line->original_amount_centavos),
                        'final_amount' => $this->centavosToDecimal($line->final_amount_centavos),
                    ])->values()->toArray(),
                ])
                ->toArray(),

            'contains_statutory_discount' => (bool) $sale->contains_statutory_discount,
            'statutory_discount' => $sale->contains_statutory_discount && $sale->saleDiscounts->isNotEmpty()
                ? [
                    'discount_type' => [
                        'name' => $sale->saleDiscounts->first()->discountType?->name,
                        'code' => $sale->saleDiscounts->first()->discountType?->code,
                        'statutory_category' => $sale->saleDiscounts->first()->discountType?->statutory_category,
                    ],
                    'application_mode'    => $sale->saleDiscounts->first()->application_mode,
                    'base_amount'         => (float) $sale->saleDiscounts->first()->base_amount,
                    'discount_amount'     => (float) $sale->saleDiscounts->first()->discount_amount,
                    'vat_exempt_amount'   => (float) $sale->saleDiscounts->first()->vat_exempt_amount,
                    'eligible_person_count' => (int) $sale->saleDiscounts->first()->eligible_person_count,
                    'total_pax_count'     => $sale->saleDiscounts->first()->total_pax_count ? (int) $sale->saleDiscounts->first()->total_pax_count : null,
                    'beneficiaries'       => $sale->saleDiscounts->first()->beneficiaries->map(fn($b) => [
                        'beneficiary_name' => $b->beneficiary_name,
                        'id_number'        => $b->id_number ? '***' . substr($b->id_number, -4) : null,
                        'tin'              => $b->tin ? '***' . substr($b->tin, -4) : null,
                        'spic_number'      => $b->spic_number ? '***' . substr($b->spic_number, -4) : null,
                        'child_name'       => $b->child_name,
                    ])->toArray(),
                ]
                : null,

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

    protected function centavosToDecimal(?int $centavos): float
    {
        return round(((int) $centavos) / 100, 4);
    }
}
