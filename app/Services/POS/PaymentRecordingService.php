<?php

namespace App\Services\POS;

use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentRecordingService
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected InventoryService $inventoryService,
        protected \App\Services\Accounting\AccountingOutboxService $outboxService
    ) {}

    /**
     * Record a single payment for a sale.
     */
    public function record(string $saleId, array $data, User $user): SalePayment
    {
        return $this->recordSplit($saleId, [$data], $user)->first();
    }

    /**
     * Record multiple payments for a sale.
     *
     * @param string $saleId
     * @param array $paymentsData
     * @param User $user
     * @return Collection<SalePayment>
     * @throws ValidationException
     */
    public function recordSplit(string $saleId, array $paymentsData, User $user): Collection
    {
        $auditContext = [];
        $sale = null;

        try {
            return DB::transaction(function () use ($saleId, $paymentsData, $user, &$auditContext, &$sale) {
                // 1. Manual Lookup (Ensuring tenant/branch context)
                $sale = Sale::where('id', $saleId)->first();

                if (!$sale) {
                    $auditContext = [
                        'reason' => 'Sale not found',
                        'metadata' => [
                            'sale_id' => $saleId,
                            'attempted_payments' => count($paymentsData)
                        ]
                    ];
                    throw ValidationException::withMessages([
                        'sale' => ['The specified sale does not exist or you do not have access to it.'],
                    ]);
                }

                // 2. State Guard: Reject if already paid
                if ($sale->status === 'paid') {
                    $auditContext = [
                        'reason' => 'Sale already paid'
                    ];
                    throw ValidationException::withMessages([
                        'status' => ['This sale has already been paid.'],
                    ]);
                }

                $totalPaymentAmount = '0.0000';
                $salePayments = collect();

                // 3. Process each payment entry
                foreach ($paymentsData as $index => $data) {
                    $paymentMethod = PaymentMethod::where('id', $data['payment_method_id'])
                        ->active()
                        ->first();

                    if (!$paymentMethod) {
                        throw ValidationException::withMessages([
                            "payments.{$index}.payment_method_id" => ['The selected payment method is invalid or inactive.'],
                        ]);
                    }

                    $amount = (string) $data['amount'];
                    if (bccomp($amount, '0', 4) <= 0) {
                        throw ValidationException::withMessages([
                            "payments.{$index}.amount" => ['Payment amount must be positive.'],
                        ]);
                    }

                    $totalPaymentAmount = bcadd($totalPaymentAmount, $amount, 4);

                    // Reference Validation
                    $referenceNumber = isset($data['reference_number']) ? trim($data['reference_number']) : null;
                    if ($paymentMethod->reference_required) {
                        if (empty($referenceNumber)) {
                            throw ValidationException::withMessages([
                                "payments.{$index}.reference_number" => ['A reference number is required for this payment method.'],
                            ]);
                        }
                        if ($paymentMethod->strict_reference_mode && empty($referenceNumber)) {
                            throw ValidationException::withMessages([
                                "payments.{$index}.reference_number" => ['Reference number cannot be blank or just whitespace.'],
                            ]);
                        }
                    }

                    // Stage SalePayment creation (but do not return yet)
                    $salePayments->push([
                        'tenant_id' => $sale->tenant_id,
                        'branch_id' => $sale->branch_id,
                        'sale_id' => $sale->id,
                        'payment_method_id' => $paymentMethod->id,
                        'payment_type' => $paymentMethod->type,
                        'amount' => $amount,
                        'reference_number' => $referenceNumber,
                        'status' => 'recorded',
                        'paid_at' => now(),
                        'created_by' => $user->id,
                    ]);
                }

                // 4. Sum Validation
                $saleTotal = (string) $sale->total;
                if (bccomp($totalPaymentAmount, $saleTotal, 4) !== 0) {
                    $auditContext = [
                        'reason' => 'Amount mismatch',
                        'metadata' => [
                            'expected' => $saleTotal,
                            'received' => $totalPaymentAmount
                        ]
                    ];
                    throw ValidationException::withMessages([
                        'amount' => ['Split payment total must match the sale total.'],
                    ]);
                }

                // 5. Atomic Creation
                $createdPayments = $salePayments->map(function ($paymentData) {
                    return SalePayment::create($paymentData);
                });

                // 6. Final Status Update
                $sale->status = 'paid';
                $sale->save();

                // 7. Success Audit
                $this->auditLogger->log('payment_recorded', $sale, null, ['status' => 'paid'], null, null, [
                    'payment_count' => $createdPayments->count(),
                    'total_amount' => $totalPaymentAmount,
                    'payment_ids' => $createdPayments->pluck('id')->toArray()
                ]);

                // 8. Inventory Deduction
                $this->inventoryService->deductFromSale($sale);

                // 9. Accounting Outbox
                $this->outboxService->recordEvent('sale_paid', $sale, [
                    'sale_id' => $sale->id,
                    'sale_number' => $sale->sale_number,
                    'subtotal' => (string) $sale->subtotal,
                    'tax_total' => (string) $sale->tax_total,
                    'discount_total' => (string) $sale->discount_total,
                    'total' => (string) $sale->total,
                    'paid_at' => (string) now(),
                    'items' => $sale->items->map(fn($item) => [
                        'sale_item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'quantity' => (string) $item->quantity,
                        'unit_price' => (string) $item->unit_price,
                        'line_total' => (string) $item->line_total,
                    ])->toArray(),
                    'taxes' => $sale->items
                        ->filter(fn($item) => $item->tax_category_id && bccomp((string) $item->tax_amount, '0', 4) !== 0)
                        ->groupBy('tax_category_id')
                        ->map(fn($items, $taxCategoryId) => [
                            'tax_category_id' => $taxCategoryId,
                            'tax_rate' => (string) $items->first()->tax_rate,
                            'tax_amount' => (string) $items->reduce(
                                fn($carry, $item) => bcadd($carry, (string) $item->tax_amount, 4),
                                '0.0000'
                            ),
                        ])
                        ->values()
                        ->toArray(),
                    'payments' => $createdPayments->map(fn($p) => [
                        'id' => $p->id,
                        'method' => $p->payment_method_id,
                        'amount' => (string) $p->amount,
                        'reference' => $p->reference_number
                    ])->toArray()
                ]);

                return $createdPayments;
            });
        } catch (ValidationException $e) {
            if (!empty($auditContext)) {
                $this->auditLogger->log(
                    'payment_recording_failed',
                    $sale,
                    null,
                    null,
                    $auditContext['reason'],
                    null,
                    $auditContext['metadata'] ?? []
                );
            }
            throw $e;
        }
    }
}
