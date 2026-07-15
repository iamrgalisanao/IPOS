<?php

namespace App\Services\Procurement;

use App\Models\BranchInventory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceiving;
use App\Models\PurchaseReceivingLine;
use App\Models\Product;
use App\Models\ExpiryLot;
use App\Services\AuditLogger;
use App\Services\BranchContext;
use App\Services\Inventory\InventoryMovementRecorder;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceivingPostingService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected AuditLogger $auditLogger,
        protected InventoryMovementRecorder $movementRecorder
    ) {}

    /**
     * Post a Goods Receiving Voucher (Purchase Receiving) draft.
     */
    public function post(PurchaseReceiving $receiving): PurchaseReceiving
    {
        // 1. Initial State Validation (Draft check)
        if (!$receiving->isDraft()) {
            throw new \RuntimeException("Only draft receiving vouchers can be posted. Current status: {$receiving->status}");
        }

        return DB::transaction(function () use ($receiving) {
            // 2. Lock Purchase Receiving Row to Prevent Double-Posting
            $receiving = PurchaseReceiving::where('id', $receiving->id)->lockForUpdate()->firstOrFail();

            if ($receiving->status === PurchaseReceiving::STATUS_POSTED) {
                return $receiving; // Already posted, skip
            }

            if (!$receiving->isDraft()) {
                throw new \RuntimeException("Receiving status changed unexpectedly.");
            }

            $user = auth()->user();
            $postedAt = now();
            $lines = $receiving->lines()->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'receiving' => ['Cannot post an empty receiving voucher.']
                ]);
            }

            // 3. Validate line quantities and costs
            foreach ($lines as $line) {
                if (bccomp($line->received_quantity, '0', 4) <= 0) {
                    throw ValidationException::withMessages([
                        'receiving' => ['Received quantity must be greater than zero for all lines.']
                    ]);
                }
                if (bccomp($line->unit_cost, '0', 4) < 0) {
                    throw ValidationException::withMessages([
                        'receiving' => ['Unit cost cannot be negative for all lines.']
                    ]);
                }
            }

            $totalAmount = '0.0000';

            // 4. Process each receiving line
            foreach ($lines as $line) {
                // Ensure the branch inventory record exists and lock it
                $inventory = BranchInventory::where('tenant_id', $receiving->tenant_id)
                    ->where('branch_id', $receiving->branch_id)
                    ->where('product_id', $line->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    // Create if not exists
                    $inventory = BranchInventory::create([
                        'tenant_id' => $receiving->tenant_id,
                        'branch_id' => $receiving->branch_id,
                        'product_id' => $line->product_id,
                        'current_stock' => '0.0000',
                        'average_cost' => '0.0000',
                        'status' => 'active',
                    ]);

                    // Re-lock for updates
                    $inventory = BranchInventory::where('id', $inventory->id)->lockForUpdate()->firstOrFail();
                }

                $quantityBefore = $inventory->current_stock;
                $currentWac = $inventory->average_cost;

                $receivedQty = $line->received_quantity;
                $receivedCost = $line->unit_cost;

                // Calculate WAC
                $newWac = $this->calculateWac($quantityBefore, $currentWac, $receivedQty, $receivedCost);

                // Increment stock
                $quantityAfter = bcadd($quantityBefore, $receivedQty, 4);

                // Update inventory record
                $inventory->update([
                    'current_stock' => $quantityAfter,
                    'average_cost' => $newWac,
                ]);



                $this->movementRecorder->record($inventory, [
                    'movement_type' => 'supplier_receiving',
                    'quantity_change' => $receivedQty,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'source_type' => PurchaseReceiving::class,
                    'source_id' => $receiving->id,
                    'reference_number' => $receiving->receiving_number,
                    'source_reference' => $receiving->receiving_number,
                    'source_effect_key' => "purchase_receiving:{$receiving->id}:line:{$line->id}",
                    'user_id' => $user->id,
                    'remarks' => $receiving->delivery_ref_number ?: $receiving->notes,
                ]);

                // Expiry Lot creation/updates for perishable products
                $product = Product::where('tenant_id', $receiving->tenant_id)
                    ->where('id', $line->product_id)
                    ->first();

                if ($product && $product->expiry_tracking_enabled) {
                    $batchCode = $line->lot_number;
                    if (empty($batchCode)) {
                        $receivingClean = str_replace('-', '', $receiving->receiving_number);
                        $suffix = substr($line->id, -8);
                        $batchCode = "LOT-{$receivingClean}-{$suffix}";
                    }

                    $lot = ExpiryLot::where('tenant_id', $receiving->tenant_id)
                        ->where('branch_id', $receiving->branch_id)
                        ->where('product_id', $line->product_id)
                        ->where('batch_code', $batchCode)
                        ->lockForUpdate()
                        ->first();

                    if ($lot) {
                        $lot->update([
                            'quantity_received' => bcadd($lot->quantity_received, $receivedQty, 4),
                            'quantity_remaining' => bcadd($lot->quantity_remaining, $receivedQty, 4),
                        ]);
                    } else {
                        ExpiryLot::create([
                            'tenant_id' => $receiving->tenant_id,
                            'branch_id' => $receiving->branch_id,
                            'product_id' => $line->product_id,
                            'purchase_receiving_line_id' => $line->id,
                            'batch_code' => $batchCode,
                            'quantity_received' => $receivedQty,
                            'quantity_remaining' => $receivedQty,
                            'expiry_date' => $line->expiry_date,
                            'status' => 'active',
                        ]);
                    }
                }

                // Update PO line received quantity if applicable
                if (!empty($line->purchase_order_line_id)) {
                    $poLine = PurchaseOrderLine::lockForUpdate()->findOrFail($line->purchase_order_line_id);
                    $poLine->received_quantity = bcadd($poLine->received_quantity, $receivedQty, 4);
                    $poLine->save();
                }

                $lineTotal = bcmul($receivedQty, $receivedCost, 4);
                $totalAmount = bcadd($totalAmount, $lineTotal, 4);
            }

            // Update receiving record
            $receiving->update([
                'status' => PurchaseReceiving::STATUS_POSTED,
                'total_received_amount' => $totalAmount,
                'posted_by' => $user->id,
                'posted_at' => $postedAt,
            ]);

            // Complete matched Purchase Order if all lines are fully received
            if (!empty($receiving->purchase_order_id)) {
                $po = PurchaseOrder::where('id', $receiving->purchase_order_id)->lockForUpdate()->first();
                if ($po && ($po->isApproved() || $po->isSent())) {
                    $allReceived = true;
                    foreach ($po->lines()->get() as $poLine) {
                        if (bccomp($poLine->received_quantity, $poLine->ordered_quantity, 4) < 0) {
                            $allReceived = false;
                            break;
                        }
                    }
                    if ($allReceived) {
                        $po->update([
                            'status' => PurchaseOrder::STATUS_COMPLETED,
                            'completed_at' => $postedAt,
                        ]);
                    }
                }
            }

            // 5. Audit Logging
            $this->auditLogger->log(
                action: 'purchase_receiving_posted',
                auditable: $receiving,
                metadata: [
                    'tenant_id' => $receiving->tenant_id,
                    'branch_id' => $receiving->branch_id,
                    'purchase_receiving_id' => $receiving->id,
                    'receiving_number' => $receiving->receiving_number,
                    'posted_by' => $user->id,
                    'posted_at' => $postedAt->toIso8601String(),
                    'line_count' => $lines->count(),
                    'movement_count' => $lines->count(),
                    'total_received_amount' => $totalAmount,
                ]
            );

            return $receiving;
        });
    }

    /**
     * Calculate Weighted Moving Average Cost using bcmath scale 4.
     */
    public function calculateWac(string $currentQty, string $currentWac, string $receivedQty, string $receivedCost): string
    {
        // Guard 1: if received_quantity <= 0, return current WAC
        if (bccomp($receivedQty, '0', 4) <= 0) {
            return $currentWac;
        }

        // Rule 2: If current stock is <= 0
        if (bccomp($currentQty, '0', 4) <= 0) {
            return $receivedCost;
        }

        // Rule 3: If current_quantity + received_quantity <= 0
        $newQty = bcadd($currentQty, $receivedQty, 4);
        if (bccomp($newQty, '0', 4) <= 0) {
            return $receivedCost;
        }

        // Formula: ((Current Qty * Current WAC) + (Received Qty * Received Unit Cost)) / (Current Qty + Received Qty)
        $currentValue = bcmul($currentQty, $currentWac, 4);
        $receivedValue = bcmul($receivedQty, $receivedCost, 4);
        $totalValue = bcadd($currentValue, $receivedValue, 4);
        
        return bcdiv($totalValue, $newQty, 4);
    }
}
