<?php

namespace App\Services\Procurement;

use App\Models\BranchInventory;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\Product;
use App\Models\ExpiryLot;
use App\Services\AuditLogger;
use App\Services\Inventory\FefoAllocationService;
use App\Services\Inventory\InventoryMovementRecorder;
use App\Exceptions\Inventory\InsufficientStockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierReturnPostingService
{
    public function __construct(
        protected FefoAllocationService $fefoAllocationService,
        protected AuditLogger $auditLogger,
        protected \App\Services\Accounting\AccountingOutboxService $outboxService,
        protected InventoryMovementRecorder $movementRecorder
    ) {}

    /**
     * Post an approved Supplier Return / RMA.
     */
    public function post(SupplierReturn $supplierReturn, string $postedBy): SupplierReturn
    {
        // 1. Initial State Validation (Approved check)
        if (!$supplierReturn->isApproved()) {
            throw new \RuntimeException("Only approved supplier returns can be posted. Current status: {$supplierReturn->status}");
        }

        return DB::transaction(function () use ($supplierReturn, $postedBy) {
            // 2. Pessimistic database lock to prevent race conditions / double posting
            $supplierReturn = SupplierReturn::where('id', $supplierReturn->id)->lockForUpdate()->firstOrFail();

            if ($supplierReturn->isPosted()) {
                return $supplierReturn; // Already posted, skip
            }

            if (!$supplierReturn->isApproved()) {
                throw new \RuntimeException("Supplier return status changed unexpectedly.");
            }

            $lines = $supplierReturn->lines()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['Cannot post an empty supplier return.']
                ]);
            }

            $postedAt = now();

            // 3. Process each return line
            foreach ($lines as $line) {
                // Ensure the branch inventory record exists and lock it
                $inventory = BranchInventory::where('tenant_id', $supplierReturn->tenant_id)
                    ->where('branch_id', $supplierReturn->branch_id)
                    ->where('product_id', $line->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    throw ValidationException::withMessages([
                        'lines' => ["No branch inventory record exists for product {$line->product->name} at this branch."]
                    ]);
                }

                $currentStock = $inventory->current_stock;
                $qtyToReturn = $line->quantity;
                $unitCost = $line->unit_cost;

                // Validate underflow for general stock
                $remainingStock = bcsub($currentStock, $qtyToReturn, 4);
                if (bccomp($remainingStock, '0', 4) < 0) {
                    throw ValidationException::withMessages([
                        'lines' => ["Return quantity for product {$line->product->name} exceeds current branch stock of {$currentStock}."]
                    ]);
                }

                // Process expiry lot deductions for perishable products
                $product = $line->product;
                if ($product && $product->expiry_tracking_enabled) {
                    if ($line->expiry_lot_id) {
                        // Explicit Lot specified
                        $lot = ExpiryLot::where('tenant_id', $supplierReturn->tenant_id)
                            ->where('branch_id', $supplierReturn->branch_id)
                            ->where('product_id', $line->product_id)
                            ->where('id', $line->expiry_lot_id)
                            ->lockForUpdate()
                            ->first();

                        if (!$lot) {
                            throw ValidationException::withMessages([
                                'lines' => ["Specified lot for product {$product->name} was not found in branch inventory."]
                            ]);
                        }

                        $remainingLotQty = bcsub($lot->quantity_remaining, $qtyToReturn, 4);
                        if (bccomp($remainingLotQty, '0', 4) < 0) {
                            throw ValidationException::withMessages([
                                'lines' => ["Specified lot {$lot->batch_code} has insufficient stock ({$lot->quantity_remaining}) for return quantity {$qtyToReturn}."]
                            ]);
                        }

                        $lot->quantity_remaining = $remainingLotQty;
                        if (bccomp($remainingLotQty, '0', 4) === 0) {
                            $lot->status = 'depleted';
                        }
                        $lot->save();
                    } else {
                        // FEFO Fallback
                        try {
                            $this->fefoAllocationService->allocate(
                                $supplierReturn->tenant_id,
                                $supplierReturn->branch_id,
                                $line->product_id,
                                $qtyToReturn
                            );
                        } catch (InsufficientStockException $e) {
                            throw ValidationException::withMessages([
                                'lines' => ["Product {$product->name} has insufficient unexpired stock for FEFO return."]
                            ]);
                        }
                    }
                }

                // Calculate WAC
                $newWac = $this->calculateWac($currentStock, $inventory->average_cost, $qtyToReturn, $unitCost);

                // Update inventory
                $inventory->update([
                    'current_stock' => $remainingStock,
                    'average_cost' => $newWac,
                ]);

                $this->movementRecorder->record($inventory, [
                    'movement_type' => 'supplier_return',
                    'quantity_change' => bcmul($qtyToReturn, '-1', 4),
                    'quantity_before' => $currentStock,
                    'quantity_after' => $remainingStock,
                    'source_type' => SupplierReturn::class,
                    'source_id' => $supplierReturn->id,
                    'reference_number' => $supplierReturn->document_number,
                    'source_reference' => $supplierReturn->document_number,
                    'source_effect_key' => "supplier_return:{$supplierReturn->id}:line:{$line->id}",
                    'user_id' => $postedBy,
                    'remarks' => $supplierReturn->notes,
                ]);
            }

            // Update SupplierReturn status to posted
            $supplierReturn->update([
                'status' => SupplierReturn::STATUS_POSTED,
                'posted_by' => $postedBy,
                'posted_at' => $postedAt,
            ]);

            // Audit Logging
            $this->auditLogger->log(
                action: 'supplier_return_posted',
                auditable: $supplierReturn,
                metadata: [
                    'tenant_id' => $supplierReturn->tenant_id,
                    'branch_id' => $supplierReturn->branch_id,
                    'supplier_return_id' => $supplierReturn->id,
                    'document_number' => $supplierReturn->document_number,
                    'posted_by' => $postedBy,
                    'posted_at' => $postedAt->toIso8601String(),
                    'line_count' => $lines->count(),
                    'total_amount' => $supplierReturn->total_amount,
                ]
            );

            // Record to Accounting Outbox inside the transaction
            $this->outboxService->recordEvent('supplier_return_posted', $supplierReturn, [
                'supplier_return_id' => $supplierReturn->id,
                'document_number' => $supplierReturn->document_number,
                'supplier_id' => $supplierReturn->supplier_id,
                'total_amount' => (string) $supplierReturn->total_amount,
                'posted_at' => (string) $postedAt,
                'notes' => $supplierReturn->notes,
                'lines' => $lines->map(fn($line) => [
                    'supplier_return_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product->name,
                    'quantity' => (string) $line->quantity,
                    'unit_cost' => (string) $line->unit_cost,
                    'line_total' => (string) $line->line_total,
                ])->toArray(),
            ]);

            return $supplierReturn;
        });
    }

    /**
     * Calculate inverse WAC (Weighted Average Cost) using high-precision bcmath.
     */
    public function calculateWac(string $currentQty, string $currentWac, string $returnQty, string $returnCost): string
    {
        // Guard 1: If return quantity <= 0, return current WAC
        if (bccomp($returnQty, '0', 4) <= 0) {
            return $currentWac;
        }

        // Guard 2: Calculate remaining stock after return
        $remainingQty = bcsub($currentQty, $returnQty, 4);
        if (bccomp($remainingQty, '0', 4) < 0) {
            throw new \RuntimeException("Negative stock underflow during WAC calculation.");
        }

        // Guard 3: If remaining stock is exactly 0, WAC is reset to 0.0000
        if (bccomp($remainingQty, '0', 4) === 0) {
            return '0.0000';
        }

        // Standard inverse WAC logic:
        // Remaining Value = (Current Qty * Current WAC) - (Returned Qty * Returned Unit Cost)
        // New WAC = Remaining Value / Remaining Qty
        $currentValue = bcmul($currentQty, $currentWac, 4);
        $returnValue = bcmul($returnQty, $returnCost, 4);
        $remainingValue = bcsub($currentValue, $returnValue, 4);

        if (bccomp($remainingValue, '0', 4) <= 0) {
            return '0.0000';
        }

        return bcdiv($remainingValue, $remainingQty, 4);
    }
}
