<?php

namespace App\Services\POS;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePromotionLine;
use App\Models\SaleRefund;
use App\Models\SaleRefundItem;
use App\Models\PaymentReversal;
use App\Models\InventoryMovement;
use App\Models\BranchInventory;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class RefundService
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected InventoryService $inventoryService,
        protected \App\Services\Accounting\AccountingOutboxService $outboxService
    ) {}

    /**
     * Perform a refund on a sale.
     * $itemsToRefund: Array of ['sale_item_id' => string, 'quantity' => float, 'restock_action' => string]
     */
    public function refund(Sale $sale, array $itemsToRefund, string $reasonCode, ?string $reasonNotes = null, ?string $shiftId = null): SaleRefund
    {
        $this->validateSaleStatus($sale);
        $this->validateIsolation($sale);

        return DB::transaction(function () use ($sale, $itemsToRefund, $reasonCode, $reasonNotes, $shiftId) {
            $user = Auth::user();
            $refundTotal = 0;
            $refundItemsData = [];

            // 1. Process Items and validate quantities
            foreach ($itemsToRefund as $refundRequest) {
                $saleItem = SaleItem::findOrFail($refundRequest['sale_item_id']);
                
                if ($saleItem->sale_id !== $sale->id) {
                    throw new \RuntimeException("Item {$saleItem->id} does not belong to Sale {$sale->id}.");
                }

                $qtyToRefund = (float) $refundRequest['quantity'];
                $this->validateQuantity($saleItem, $qtyToRefund);

                $lineRefundTotal = ($qtyToRefund / $saleItem->quantity) * $saleItem->line_total;
                $refundTotal += $lineRefundTotal;

                $refundItemsData[] = [
                    'sale_item' => $saleItem,
                    'quantity' => $qtyToRefund,
                    'line_total' => $lineRefundTotal,
                    'restock_action' => $refundRequest['restock_action'] ?? 'restock'
                ];
            }

            // 7. Prevent cumulative refund amount from exceeding sale total
            $previousRefundTotal = SaleRefund::where('sale_id', $sale->id)->sum('refund_total');
            if (($previousRefundTotal + $refundTotal) > (float) $sale->total + 0.0001) { // Floating point safety
                throw new \RuntimeException("Cumulative refund total exceeds original sale total.");
            }

            // 8. Create SaleRefund record
            $refund = SaleRefund::create([
                'tenant_id' => $sale->tenant_id,
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'shift_id' => $shiftId,
                'reason_code' => $reasonCode,
                'reason_notes' => $reasonNotes,
                'refund_total' => $refundTotal,
                'refunded_by' => $user->id,
                'refunded_at' => now(),
            ]);

            // 9. Create SaleRefundItem rows and handle Inventory
            foreach ($refundItemsData as $data) {
                $saleItem = $data['sale_item'];
                SaleRefundItem::create([
                    'tenant_id' => $sale->tenant_id,
                    'branch_id' => $sale->branch_id,
                    'sale_refund_id' => $refund->id,
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'quantity_refunded' => $data['quantity'],
                    'unit_price_snapshot' => $saleItem->unit_price,
                    'tax_amount_snapshot' => ($data['quantity'] / $saleItem->quantity) * $saleItem->tax_total,
                    'line_refund_total' => $data['line_total'],
                    'restock_action' => $data['restock_action'],
                ]);

                if ($data['restock_action'] === 'restock' && $saleItem->is_inventory_tracked) {
                    $this->restockItem($saleItem, $data['quantity'], $refund);
                }
            }

            // 10. Create PaymentReversal rows (reverse from available payment rows)
            $this->reversePayments($sale, $refundTotal, $reasonCode, $reasonNotes, $user);

            // 13, 14. Update Sale Status
            $newStatus = ($previousRefundTotal + $refundTotal) >= (float) $sale->total - 0.0001 
                ? 'refunded' 
                : 'partially_refunded';
            $sale->update(['status' => $newStatus]);

            // 14a. Reverse Statutory Discount proportionally for refunded items
            $this->reverseStatutoryDiscountOnRefund($sale, $refund, $refundTotal, $itemsToRefund);

            // 14b. Reverse Commercial Promotion allocations for refunded quantities.
            $this->reverseCommercialPromotionOnRefund($sale, $refund, $itemsToRefund);

            // 7. Audit Logging
            $this->auditLogger->log(
                action: 'sale_refunded',
                auditable: $sale,
                metadata: [
                    'refund_id' => $refund->id,
                    'refund_total' => $refundTotal,
                    'status' => $newStatus
                ]
            );

            // 8. Accounting Outbox
            $refund->load('items');
            $paymentReversals = PaymentReversal::where('sale_id', $sale->id)
                ->where('reversal_type', 'refund_reversal')
                ->where('reason_code', $reasonCode)
                ->where('reversed_by', $user->id)
                ->where('created_at', '>=', $refund->created_at)
                ->get();

            $this->outboxService->recordEvent('sale_refunded', $refund, [
                'sale_id' => $sale->id,
                'sale_refund_id' => $refund->id,
                'refund_number' => $refund->refund_number ?: $refund->id,
                'refund_total' => (string) $refundTotal,
                'reason_code' => $reasonCode,
                'refunded_by' => $refund->refunded_by,
                'refunded_at' => (string) $refund->refunded_at,
                'refund_items' => $refund->items->map(fn($i) => [
                    'sale_item_id' => $i->sale_item_id,
                    'product_id' => $i->product_id,
                    'quantity_refunded' => (string) $i->quantity_refunded,
                    'unit_price_snapshot' => (string) $i->unit_price_snapshot,
                    'line_refund_total' => (string) $i->line_refund_total,
                    'restock_action' => $i->restock_action
                ])->toArray(),
                'payment_reversals' => $paymentReversals->map(fn($reversal) => [
                    'payment_reversal_id' => $reversal->id,
                    'payment_id' => $reversal->sale_payment_id,
                    'payment_method_id' => $reversal->salePayment?->payment_method_id,
                    'amount' => (string) $reversal->amount,
                    'reference' => $reversal->salePayment?->reference_number,
                ])->toArray(),
                'items' => $refund->items->map(fn($i) => [
                    'sale_item_id' => $i->sale_item_id,
                    'product_id' => $i->product_id,
                    'quantity' => (string) $i->quantity_refunded,
                    'line_total' => (string) $i->line_refund_total,
                    'restock_action' => $i->restock_action
                ])->toArray(),
            ]);

            return $refund;
        });
    }

    /**
     * Reverse statutory discount proportionally when items are refunded.
     *
     * For partial refunds, the statutory discount is reversed proportionally
     * based on the ratio of refunded total to original sale total.
     * For full refunds, the entire statutory discount is reversed.
     *
     * This ensures the Z-Reading and accounting outbox correctly reflect
     * the reduced statutory discount after a refund.
     */
    protected function reverseStatutoryDiscountOnRefund(
        Sale $sale,
        SaleRefund $refund,
        float $refundTotal,
        array $itemsToRefund
    ): void {
        if (!$sale->contains_statutory_discount) {
            return;
        }

        $statutoryDiscount = \App\Models\SaleStatutoryDiscount::where('sale_id', $sale->id)->first();

        if (!$statutoryDiscount) {
            return;
        }

        // Compute the refund ratio against the original gross eligible amount
        // (discount_basis_amount), NOT the net sale total. The refund total is
        // a gross line amount, so the denominator must also be gross to avoid
        // mixing net and gross figures which produces an incorrect ratio.
        $originalGrossBasis = (float) $statutoryDiscount->discount_basis_amount;
        $refundRatio = $originalGrossBasis > 0 ? ($refundTotal / $originalGrossBasis) : 0;

        $reversedDiscountAmount = (float) $statutoryDiscount->discount_amount * $refundRatio;
        $reversedVatExemptAmount = (float) $statutoryDiscount->vat_exempt_amount * $refundRatio;

        // Update the Sale's aggregate totals
        // Use raw DB to bypass the Sale model's immutability guard on financial fields
        // (refund reversals are legitimate financial corrections per BIR rules)
        $newStatutoryTotal = max(0, (float) $sale->statutory_discount_total - $reversedDiscountAmount);
        $newVatExemptAmount = max(0, (float) $sale->vat_exempt_sales_amount - $reversedVatExemptAmount);
        $newVatableAmount = (float) $sale->vatable_sales_amount + $reversedVatExemptAmount;
        $newVatAmount = (float) $sale->vat_amount + $reversedVatExemptAmount;

        DB::table('sales')->where('id', $sale->id)->update([
            'statutory_discount_total' => number_format($newStatutoryTotal, 4, '.', ''),
            'vat_exempt_sales_amount' => number_format($newVatExemptAmount, 4, '.', ''),
            'vatable_sales_amount' => number_format($newVatableAmount, 4, '.', ''),
            'vat_amount' => number_format($newVatAmount, 4, '.', ''),
            'contains_statutory_discount' => $newStatutoryTotal > 0,
        ]);

        // Log the proportional reversal for audit compliance
        $this->auditLogger->log(
            action: 'statutory_discount_reversed_refund',
            auditable: $sale,
            metadata: [
                'refund_id' => $refund->id,
                'refund_ratio' => number_format($refundRatio, 6, '.', ''),
                'reversed_discount_amount' => number_format($reversedDiscountAmount, 4, '.', ''),
                'reversed_vat_exempt_amount' => number_format($reversedVatExemptAmount, 4, '.', ''),
                'remaining_statutory_discount_total' => number_format($newStatutoryTotal, 4, '.', ''),
            ]
        );
    }

    /**
     * Reverse commercial promotion discount allocations for refunded items.
     *
     * The original sale promotion snapshots stay immutable. Remaining discount
     * fields on sales and sale_items are reduced according to cumulative refunded
     * quantity so repeated partial refunds converge exactly to zero.
     */
    protected function reverseCommercialPromotionOnRefund(
        Sale $sale,
        SaleRefund $refund,
        array $itemsToRefund
    ): void {
        $totalReversedCentavos = 0;
        $lineReversals = [];

        foreach ($itemsToRefund as $refundRequest) {
            $saleItem = SaleItem::findOrFail($refundRequest['sale_item_id']);
            $originalQuantity = (float) $saleItem->quantity;

            if ($originalQuantity <= 0) {
                continue;
            }

            $originalDiscountCentavos = (int) SalePromotionLine::query()
                ->where('sale_item_id', $saleItem->id)
                ->whereHas('salePromotion', fn ($query) => $query
                    ->where('sale_id', $sale->id)
                    ->where('is_suppressed', false))
                ->sum('discount_amount_centavos');

            if ($originalDiscountCentavos <= 0) {
                continue;
            }

            $currentRemainingCentavos = (int) (DB::table('sale_items')
                ->where('id', $saleItem->id)
                ->value('promotion_discount_centavos') ?? 0);

            $totalRefundedQuantity = (float) SaleRefundItem::where('sale_item_id', $saleItem->id)
                ->sum('quantity_refunded');
            $remainingQuantity = max(0, $originalQuantity - $totalRefundedQuantity);
            $expectedRemainingCentavos = (int) round($originalDiscountCentavos * ($remainingQuantity / $originalQuantity));
            $reversedCentavos = max(0, $currentRemainingCentavos - $expectedRemainingCentavos);

            if ($reversedCentavos <= 0) {
                continue;
            }

            $unitDiscountCentavos = $remainingQuantity > 0
                ? (int) round($expectedRemainingCentavos / $remainingQuantity)
                : 0;
            $unitPriceCentavos = (int) round(((float) $saleItem->unit_price) * 100);

            DB::table('sale_items')->where('id', $saleItem->id)->update([
                'promotion_discount_centavos' => $expectedRemainingCentavos,
                'promotion_adjusted_unit_price_centavos' => max(0, $unitPriceCentavos - $unitDiscountCentavos),
            ]);

            $totalReversedCentavos += $reversedCentavos;
            $lineReversals[] = [
                'sale_item_id' => $saleItem->id,
                'quantity_refunded_total' => number_format($totalRefundedQuantity, 4, '.', ''),
                'reversed_discount_centavos' => $reversedCentavos,
                'remaining_discount_centavos' => $expectedRemainingCentavos,
            ];
        }

        if ($totalReversedCentavos <= 0) {
            return;
        }

        $reversedAmount = $totalReversedCentavos / 100;
        $newCommercialTotal = max(0, (float) $sale->commercial_discount_total - $reversedAmount);
        $newDiscountTotal = max(0, (float) $sale->discount_total - $reversedAmount);

        DB::table('sales')->where('id', $sale->id)->update([
            'commercial_discount_total' => number_format($newCommercialTotal, 4, '.', ''),
            'discount_total' => number_format($newDiscountTotal, 4, '.', ''),
        ]);

        $this->auditLogger->log(
            action: 'commercial_promotion_reversed_refund',
            auditable: $sale,
            metadata: [
                'refund_id' => $refund->id,
                'reversed_discount_amount' => number_format($reversedAmount, 4, '.', ''),
                'remaining_commercial_discount_total' => number_format($newCommercialTotal, 4, '.', ''),
                'line_reversals' => $lineReversals,
            ]
        );
    }

    protected function validateSaleStatus(Sale $sale): void
    {
        $allowed = ['paid', 'partially_refunded'];
        if (!in_array($sale->status, $allowed)) {
            throw new \RuntimeException("Refund cannot be applied to sale with status: {$sale->status}");
        }
    }

    protected function validateIsolation(Sale $sale): void
    {
        if ($sale->tenant_id !== $this->tenantContext->getTenant()?->id || 
            $sale->branch_id !== $this->branchContext->getBranch()?->id) {
            throw new \RuntimeException('Unauthorized: Sale does not belong to active context.');
        }
    }

    protected function validateQuantity(SaleItem $item, float $qtyToRefund): void
    {
        if ($qtyToRefund <= 0) {
            throw new \RuntimeException("Refund quantity must be greater than zero.");
        }

        $alreadyRefunded = SaleRefundItem::where('sale_item_id', $item->id)->sum('quantity_refunded');
        if (($alreadyRefunded + $qtyToRefund) > (float) $item->quantity + 0.0001) {
            throw new \RuntimeException("Cumulative refunded quantity exceeds original sold quantity for item {$item->product_name}.");
        }
    }

    protected function restockItem(SaleItem $item, float $qty, SaleRefund $refund): void
    {
        $inventory = BranchInventory::where('product_id', $item->product_id)
            ->where('branch_id', $item->branch_id)
            ->first();

        if (!$inventory) return;

        $originalMovement = InventoryMovement::where('source_type', 'sale')
            ->where('source_id', $item->sale_id)
            ->where('product_id', $item->product_id)
            ->where('movement_type', 'sale_deduction')
            ->first();

        $quantityBefore = (float) $inventory->current_stock;
        $quantityAfter = $quantityBefore + $qty;

        $inventory->update(['current_stock' => $quantityAfter]);

        $this->inventoryService->recordMovement($inventory, [
            'movement_type' => 'refund_return',
            'quantity_change' => $qty,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'source_type' => 'sale_refund',
            'source_id' => $refund->id,
            'original_movement_id' => $originalMovement?->id,
            'user_id' => $refund->refunded_by,
            'remarks' => "Refund return for Sale #{$item->sale->sale_number}",
        ]);
    }

    protected function reversePayments(Sale $sale, float $totalToReverse, string $reasonCode, ?string $reasonNotes, $user): void
    {
        $remainingToReverse = $totalToReverse;
        $payments = $sale->payments()->orderBy('created_at', 'desc')->get();

        foreach ($payments as $payment) {
            if ($remainingToReverse <= 0) break;

            $alreadyReversed = PaymentReversal::where('sale_payment_id', $payment->id)->sum('amount');
            $available = (float) $payment->amount - (float) $alreadyReversed;

            if ($available <= 0) continue;

            $amountToReverse = min($available, $remainingToReverse);

            PaymentReversal::create([
                'tenant_id' => $payment->tenant_id,
                'branch_id' => $payment->branch_id,
                'sale_id' => $sale->id,
                'sale_payment_id' => $payment->id,
                'reversal_type' => 'refund_reversal',
                'amount' => $amountToReverse,
                'reason_code' => $reasonCode,
                'reason_notes' => $reasonNotes,
                'reversed_by' => $user->id,
                'reversed_at' => now(),
            ]);

            $remainingToReverse -= $amountToReverse;
        }

        if ($remainingToReverse > 0.0001) {
            throw new \RuntimeException("Insufficient payment balance to cover refund amount.");
        }
    }
}
