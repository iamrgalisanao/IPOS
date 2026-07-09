<?php

namespace App\Services\POS;

use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\PaymentReversal;
use App\Models\InventoryMovement;
use App\Models\BranchInventory;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class VoidService
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected InventoryService $inventoryService,
        protected \App\Services\Accounting\AccountingOutboxService $outboxService
    ) {}

    /**
     * Perform a full-sale void.
     */
    public function void(Sale $sale, string $reasonCode, ?string $reasonNotes = null): SaleVoid
    {
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot void sale without active TenantContext.');
        }

        if (!$this->branchContext->getBranch()) {
            throw new \RuntimeException('Cannot void sale without active BranchContext.');
        }

        // 18, 19: Isolation Guard
        if ($sale->tenant_id !== $this->tenantContext->getTenant()->id || 
            $sale->branch_id !== $this->branchContext->getBranch()->id) {
            throw new \RuntimeException('Unauthorized: Sale does not belong to the active tenant/branch context.');
        }

        // 9. Status Guard: Only allow void when sale.status = paid
        if ($sale->status !== 'paid') {
            throw new \RuntimeException("Only paid sales can be voided. Current status: {$sale->status}");
        }

        // 5. Idempotency Guard: Unique constraint on sale_id handled by DB, 
        // but we check here for a cleaner error.
        if (SaleVoid::where('sale_id', $sale->id)->exists()) {
            throw new \RuntimeException('This sale has already been voided.');
        }

        return DB::transaction(function () use ($sale, $reasonCode, $reasonNotes) {
            $user = Auth::user();

            // 1. Create SaleVoid record
            $void = SaleVoid::create([
                'tenant_id' => $sale->tenant_id,
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'reason_code' => $reasonCode,
                'reason_notes' => $reasonNotes,
                'voided_by' => $user->id,
                'voided_at' => now(),
            ]);

            // 2. Create PaymentReversal rows
            foreach ($sale->payments as $payment) {
                PaymentReversal::create([
                    'tenant_id' => $payment->tenant_id,
                    'branch_id' => $payment->branch_id,
                    'sale_id' => $sale->id,
                    'sale_payment_id' => $payment->id,
                    'reversal_type' => 'void_reversal',
                    'amount' => $payment->amount,
                    'reason_code' => $reasonCode,
                    'reason_notes' => $reasonNotes,
                    'reversed_by' => $user->id,
                    'reversed_at' => now(),
                ]);
            }

            // 3. Reverse Inventory Movements
            $this->reverseInventory($sale, $void);

            // 4. Update Sale status
            $sale->update(['status' => 'voided']);

            // 4a. Reverse Statutory Discount Totals (for Z-Reading accuracy)
            $this->reverseStatutoryDiscountOnVoid($sale, $void);

            // 5. Audit Logging
            $this->auditLogger->log(
                action: 'sale_voided',
                auditable: $sale,
                metadata: [
                    'void_id' => $void->id,
                    'reason_code' => $reasonCode,
                    'payment_count' => $sale->payments->count()
                ]
            );

            // 6. Accounting Outbox
            $paymentReversals = PaymentReversal::where('sale_id', $sale->id)
                ->where('reversal_type', 'void_reversal')
                ->get();

            $this->outboxService->recordEvent('sale_voided', $void, [
                'sale_id' => $sale->id,
                'sale_void_id' => $void->id,
                'sale_number' => $sale->sale_number,
                'total' => (string) $sale->total,
                'original_sale_total' => (string) $sale->total,
                'reason_code' => $reasonCode,
                'voided_by' => $void->voided_by,
                'voided_at' => (string) $void->voided_at,
                'statutory_discount_reversed' => $sale->contains_statutory_discount,
                'payment_reversals' => $paymentReversals->map(fn($reversal) => [
                    'payment_reversal_id' => $reversal->id,
                    'payment_id' => $reversal->sale_payment_id,
                    'payment_method_id' => $reversal->salePayment?->payment_method_id,
                    'amount' => (string) $reversal->amount,
                    'reference' => $reversal->salePayment?->reference_number,
                ])->toArray(),
                'reversals' => $paymentReversals->map(fn($reversal) => [
                    'payment_reversal_id' => $reversal->id,
                    'payment_id' => $reversal->sale_payment_id,
                    'amount' => (string) $reversal->amount
                ])->toArray()
            ]);

            return $void;
        });
    }

    /**
     * Reverse statutory discount totals when a sale is voided.
     *
     * This ensures the Z-Reading and accounting outbox correctly reflect
     * that the statutory discount is no longer valid. The original
     * SaleStatutoryDiscount record is retained for audit trail purposes
     * (BIR requires voided transactions to remain visible), but the
     * Sale's aggregate totals are zeroed out.
     */
    protected function reverseStatutoryDiscountOnVoid(Sale $sale, SaleVoid $void): void
    {
        if (!$sale->contains_statutory_discount) {
            return;
        }

        $statutoryDiscount = \App\Models\SaleStatutoryDiscount::where('sale_id', $sale->id)->first();

        if (!$statutoryDiscount) {
            return;
        }

        // Zero out the Sale's statutory discount aggregate fields
        // The original line-item breakdown remains in SaleStatutoryDiscount for audit
        // Use raw DB to bypass the Sale model's immutability guard on financial fields
        // (void/refund reversals are legitimate financial corrections per BIR rules)
        DB::table('sales')->where('id', $sale->id)->update([
            'statutory_discount_total' => 0,
            'contains_statutory_discount' => false,
            'vat_exempt_sales_amount' => $sale->vat_exempt_sales_amount - $statutoryDiscount->vat_exempt_amount,
            'vatable_sales_amount' => $sale->vatable_sales_amount + $statutoryDiscount->vat_exempt_amount,
            'vat_amount' => $sale->vat_amount + $statutoryDiscount->vat_exempt_amount,
        ]);

        // Log the reversal for audit compliance
        $this->auditLogger->log(
            action: 'statutory_discount_reversed_void',
            auditable: $sale,
            metadata: [
                'void_id' => $void->id,
                'original_discount_amount' => (string) $statutoryDiscount->discount_amount,
                'original_vat_exempt_amount' => (string) $statutoryDiscount->vat_exempt_amount,
                'reason_code' => $void->reason_code,
            ]
        );
    }

    /**
     * Reverse all inventory deductions for the sale.
     */
    protected function reverseInventory(Sale $sale, SaleVoid $void): void
    {
        // Find all sale_deduction movements for this sale
        $movements = InventoryMovement::where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('movement_type', 'sale_deduction')
            ->get();

        foreach ($movements as $original) {
            // 8. Inventory Over-Reversal Guard
            $alreadyReversed = InventoryMovement::where('original_movement_id', $original->id)
                ->where('movement_type', 'void_reversal')
                ->exists();

            if ($alreadyReversed) {
                continue;
            }

            $inventory = BranchInventory::find($original->branch_inventory_id);
            if (!$inventory) {
                throw new \RuntimeException("Inventory record not found for movement {$original->id}.");
            }

            $quantityBefore = (float) $inventory->current_stock;
            $quantityChange = abs((float) $original->quantity_change);
            $quantityAfter = $quantityBefore + $quantityChange;

            // Update BranchInventory
            $inventory->update(['current_stock' => $quantityAfter]);

            // 7. Create void_reversal movement
            $this->inventoryService->recordMovement($inventory, [
                'movement_type' => 'void_reversal',
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'source_type' => 'sale_void',
                'source_id' => $void->id,
                'original_movement_id' => $original->id,
                'user_id' => $void->voided_by,
                'remarks' => "Void reversal for Sale #{$sale->sale_number}",
            ]);
        }
    }
}
