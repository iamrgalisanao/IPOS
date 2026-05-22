<?php

namespace App\Services\Procurement;

use App\Models\InterBranchTransfer;
use App\Models\InterBranchTransferLine;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ExpiryLot;
use App\Models\User;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\Inventory\FefoAllocationService;
use App\Exceptions\Inventory\InsufficientStockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IbtStockMovementService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected AuditLogger $auditLogger,
        protected FefoAllocationService $fefoAllocationService
    ) {}

    /**
     * Dispatch an approved Inter-Branch Transfer (IBT) from the source branch.
     * Enforces RBAC, locks stock records, freezes source WAC, handles expiry lots / FEFO,
     * and transitions status to in_transit.
     */
    public function dispatch(InterBranchTransfer $ibt, User $user): InterBranchTransfer
    {
        // 1. RBAC Guard: Only Tenant Owner, Owner/Admin, and Procurement Manager are authorized
        $authorized = $user->roles()->whereIn('name', ['Owner/Admin', 'Tenant Owner', 'Procurement Manager'])->exists()
            || ($user->actor_type === 'tenant_user' && $user->roles()->where('name', 'Owner/Admin')->exists());

        if (!$authorized) {
            throw new \RuntimeException(
                "User is not authorized to perform Inter-Branch Transfer operations. Minimum role: Procurement Manager.",
                403
            );
        }

        return DB::transaction(function () use ($ibt, $user) {
            // 2. Lock the IBT row to prevent race conditions
            $ibt = InterBranchTransfer::where('id', $ibt->id)->lockForUpdate()->firstOrFail();

            // 3. Idempotency Guard: if already in_transit or received, return immediately
            if ($ibt->isInTransit()) {
                return $ibt;
            }

            if ($ibt->isReceived()) {
                throw new \RuntimeException("Cannot dispatch an already received Inter-Branch Transfer.");
            }

            if ($ibt->isCancelled()) {
                throw new \RuntimeException("Cannot dispatch a cancelled Inter-Branch Transfer.");
            }

            // 4. Precondition Guard: Must be approved
            if (!$ibt->isApproved()) {
                throw new \RuntimeException(
                    "Only approved Inter-Branch Transfers can be dispatched. Current status: {$ibt->status}"
                );
            }

            // 5. Cross-Tenant Verification: Source and destination branches must belong to the same tenant as the IBT
            $sourceBranch = DB::table('branches')->where('id', $ibt->source_branch_id)->first();
            $destBranch = DB::table('branches')->where('id', $ibt->destination_branch_id)->first();

            if (!$sourceBranch || !$destBranch || $sourceBranch->tenant_id !== $ibt->tenant_id || $destBranch->tenant_id !== $ibt->tenant_id) {
                throw new \RuntimeException("Cross-tenant allocation or transfer is strictly forbidden.");
            }

            $lines = $ibt->lines()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['Cannot dispatch an empty Inter-Branch Transfer.']
                ]);
            }

            // Set Tenant & Source Branch Context for Audit Logging
            $tenant = Tenant::findOrFail($ibt->tenant_id);
            $this->tenantContext->setTenant($tenant);
            $branchObj = Branch::findOrFail($ibt->source_branch_id);
            $this->branchContext->setBranch($branchObj);

            // 6. Process each line for stock mutation
            foreach ($lines as $line) {
                // Find and lock source branch inventory record
                $inventory = BranchInventory::where('tenant_id', $ibt->tenant_id)
                    ->where('branch_id', $ibt->source_branch_id)
                    ->where('product_id', $line->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    throw ValidationException::withMessages([
                        'lines' => ["No branch inventory record exists for product {$line->product->name} at the source branch."]
                    ]);
                }

                $currentStock = $inventory->current_stock;
                $qtyToTransfer = $line->quantity_transferred;

                // Validate underflow
                if (bccomp($currentStock, $qtyToTransfer, 4) < 0) {
                    throw ValidationException::withMessages([
                        'lines' => ["Transfer quantity for product {$line->product->name} ({$qtyToTransfer}) exceeds current source branch stock of {$currentStock}."]
                    ]);
                }

                // Freeze current source WAC as unit_cost
                $frozenWac = $inventory->average_cost;

                // Deduct from expiry lots for perishable products
                $product = $line->product;
                if ($product && $product->expiry_tracking_enabled) {
                    if ($line->expiry_lot_id) {
                        // Explicit Lot Selection
                        $lot = ExpiryLot::where('tenant_id', $ibt->tenant_id)
                            ->where('branch_id', $ibt->source_branch_id)
                            ->where('product_id', $line->product_id)
                            ->where('id', $line->expiry_lot_id)
                            ->lockForUpdate()
                            ->first();

                        if (!$lot) {
                            throw ValidationException::withMessages([
                                'lines' => ["Specified lot for product {$product->name} was not found in source branch inventory."]
                            ]);
                        }

                        $remainingLotQty = bcsub($lot->quantity_remaining, $qtyToTransfer, 4);
                        if (bccomp($remainingLotQty, '0', 4) < 0) {
                            throw ValidationException::withMessages([
                                'lines' => ["Specified lot {$lot->batch_code} has insufficient stock ({$lot->quantity_remaining}) for transfer quantity {$qtyToTransfer}."]
                            ]);
                        }

                        $lot->quantity_remaining = $remainingLotQty;
                        if (bccomp($remainingLotQty, '0', 4) === 0) {
                            $lot->status = 'depleted';
                        }
                        $lot->save();
                    } else {
                        // FEFO Fallback allocation
                        try {
                            $allocations = $this->fefoAllocationService->allocate(
                                $ibt->tenant_id,
                                $ibt->source_branch_id,
                                $line->product_id,
                                $qtyToTransfer
                            );

                            // Associate the first allocated lot back to the transfer line for receiving traceability
                            if (!empty($allocations)) {
                                $line->expiry_lot_id = $allocations[0]['expiry_lot_id'];
                            }
                        } catch (InsufficientStockException $e) {
                            throw ValidationException::withMessages([
                                'lines' => ["Product {$product->name} has insufficient unexpired stock for FEFO transfer."]
                            ]);
                        }
                    }
                }

                // Deduct source branch stock
                $newStock = bcsub($currentStock, $qtyToTransfer, 4);
                $inventory->update([
                    'current_stock' => $newStock
                ]);

                // Update line details
                $line->unit_cost = $frozenWac;
                $line->line_total = bcmul($qtyToTransfer, $frozenWac, 4);
                $line->save();

                // Create source inventory movement record
                InventoryMovement::create([
                    'tenant_id' => $ibt->tenant_id,
                    'branch_id' => $ibt->source_branch_id,
                    'product_id' => $line->product_id,
                    'branch_inventory_id' => $inventory->id,
                    'movement_type' => 'ibt_dispatch',
                    'quantity_change' => bcmul($qtyToTransfer, '-1', 4),
                    'quantity_before' => $currentStock,
                    'quantity_after' => $newStock,
                    'source_type' => InterBranchTransfer::class,
                    'source_id' => $ibt->id,
                    'reference_number' => $ibt->reference_number,
                    'user_id' => $user->id,
                    'remarks' => $ibt->notes ?: "IBT dispatch to branch " . $destBranch->branch_code,
                ]);
            }

            // Update IBT status
            $ibt->update([
                'status' => InterBranchTransfer::STATUS_IN_TRANSIT,
                'dispatched_by' => $user->id,
                'dispatched_at' => now(),
            ]);

            // Audit Logging
            $this->auditLogger->log(
                action: 'ibt_dispatched',
                auditable: $ibt,
                metadata: [
                    'tenant_id' => $ibt->tenant_id,
                    'source_branch_id' => $ibt->source_branch_id,
                    'destination_branch_id' => $ibt->destination_branch_id,
                    'reference_number' => $ibt->reference_number,
                    'dispatched_by' => $user->id,
                    'dispatched_at' => now()->toIso8601String(),
                    'line_count' => $lines->count(),
                ]
            );

            return $ibt;
        });
    }

    /**
     * Receive an in-transit Inter-Branch Transfer (IBT) at the destination branch.
     * Enforces RBAC, locks stock records, recalculates destination WAC, handles destination expiry lots,
     * and transitions status to received.
     */
    public function receive(InterBranchTransfer $ibt, User $user): InterBranchTransfer
    {
        // 1. RBAC Guard: Only Tenant Owner, Owner/Admin, and Procurement Manager are authorized
        $authorized = $user->roles()->whereIn('name', ['Owner/Admin', 'Tenant Owner', 'Procurement Manager'])->exists()
            || ($user->actor_type === 'tenant_user' && $user->roles()->where('name', 'Owner/Admin')->exists());

        if (!$authorized) {
            throw new \RuntimeException(
                "User is not authorized to perform Inter-Branch Transfer operations. Minimum role: Procurement Manager.",
                403
            );
        }

        return DB::transaction(function () use ($ibt, $user) {
            // 2. Lock the IBT row to prevent race conditions
            $ibt = InterBranchTransfer::where('id', $ibt->id)->lockForUpdate()->firstOrFail();

            // 3. Idempotency Guard: if already received, return immediately
            if ($ibt->isReceived()) {
                return $ibt;
            }

            if ($ibt->isCancelled()) {
                throw new \RuntimeException("Cannot receive a cancelled Inter-Branch Transfer.");
            }

            // 4. Precondition Guard: Must be in_transit
            if (!$ibt->isInTransit()) {
                throw new \RuntimeException(
                    "Only in-transit Inter-Branch Transfers can be received. Current status: {$ibt->status}"
                );
            }

            // Set Tenant & Destination Branch Context for Audit Logging
            $tenant = Tenant::findOrFail($ibt->tenant_id);
            $this->tenantContext->setTenant($tenant);
            $branchObj = Branch::findOrFail($ibt->destination_branch_id);
            $this->branchContext->setBranch($branchObj);

            $lines = $ibt->lines()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['Cannot receive an empty Inter-Branch Transfer.']
                ]);
            }

            // 5. Process each line for stock mutation at destination branch
            foreach ($lines as $line) {
                // Find or create destination branch inventory record
                $inventory = BranchInventory::where('tenant_id', $ibt->tenant_id)
                    ->where('branch_id', $ibt->destination_branch_id)
                    ->where('product_id', $line->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    $inventory = BranchInventory::create([
                        'tenant_id' => $ibt->tenant_id,
                        'branch_id' => $ibt->destination_branch_id,
                        'product_id' => $line->product_id,
                        'current_stock' => '0.0000',
                        'average_cost' => '0.0000',
                        'status' => 'active',
                    ]);

                    $inventory = BranchInventory::where('id', $inventory->id)->lockForUpdate()->firstOrFail();
                }

                $qtyBefore = $inventory->current_stock;
                $currentWac = $inventory->average_cost;

                $receivedQty = $line->quantity_transferred;
                $receivedCost = $line->unit_cost;

                // Recalculate WAC at destination branch
                $newWac = $this->calculateWac($qtyBefore, $currentWac, $receivedQty, $receivedCost);

                // Increment destination stock
                $qtyAfter = bcadd($qtyBefore, $receivedQty, 4);

                // Update destination inventory record
                $inventory->update([
                    'current_stock' => $qtyAfter,
                    'average_cost' => $newWac,
                ]);

                // Create destination inventory movement record
                InventoryMovement::create([
                    'tenant_id' => $ibt->tenant_id,
                    'branch_id' => $ibt->destination_branch_id,
                    'product_id' => $line->product_id,
                    'branch_inventory_id' => $inventory->id,
                    'movement_type' => 'ibt_receipt',
                    'quantity_change' => $receivedQty,
                    'quantity_before' => $qtyBefore,
                    'quantity_after' => $qtyAfter,
                    'source_type' => InterBranchTransfer::class,
                    'source_id' => $ibt->id,
                    'reference_number' => $ibt->reference_number,
                    'user_id' => $user->id,
                    'remarks' => $ibt->notes ?: "IBT receipt from branch " . $ibt->sourceBranch->branch_code,
                ]);

                // Expiry Lot creation/updates at destination branch for perishable products
                $product = $line->product;
                if ($product && $product->expiry_tracking_enabled) {
                    $sourceLot = null;
                    if ($line->expiry_lot_id) {
                        $sourceLot = ExpiryLot::find($line->expiry_lot_id);
                    }

                    if ($sourceLot) {
                        $batchCode = $sourceLot->batch_code;
                        $expiryDate = $sourceLot->expiry_date;
                    } else {
                        // Fallback if no lot info is found (e.g. non-lot line backfill)
                        $cleanRef = str_replace('-', '', $ibt->reference_number);
                        $suffix = substr($line->id, -8);
                        $batchCode = "LOT-IBT-{$cleanRef}-{$suffix}";
                        $expiryDate = now()->addMonths(6)->toDateString();
                    }

                    $destLot = ExpiryLot::where('tenant_id', $ibt->tenant_id)
                        ->where('branch_id', $ibt->destination_branch_id)
                        ->where('product_id', $line->product_id)
                        ->where('batch_code', $batchCode)
                        ->lockForUpdate()
                        ->first();

                    if ($destLot) {
                        $destLot->update([
                            'quantity_received' => bcadd($destLot->quantity_received, $receivedQty, 4),
                            'quantity_remaining' => bcadd($destLot->quantity_remaining, $receivedQty, 4),
                        ]);
                    } else {
                        ExpiryLot::create([
                            'tenant_id' => $ibt->tenant_id,
                            'branch_id' => $ibt->destination_branch_id,
                            'product_id' => $line->product_id,
                            'batch_code' => $batchCode,
                            'quantity_received' => $receivedQty,
                            'quantity_remaining' => $receivedQty,
                            'expiry_date' => $expiryDate,
                            'status' => 'active',
                        ]);
                    }
                }
            }

            // Update IBT status
            $ibt->update([
                'status' => InterBranchTransfer::STATUS_RECEIVED,
                'received_by' => $user->id,
                'received_at' => now(),
            ]);

            // Audit Logging
            $this->auditLogger->log(
                action: 'ibt_received',
                auditable: $ibt,
                metadata: [
                    'tenant_id' => $ibt->tenant_id,
                    'source_branch_id' => $ibt->source_branch_id,
                    'destination_branch_id' => $ibt->destination_branch_id,
                    'reference_number' => $ibt->reference_number,
                    'received_by' => $user->id,
                    'received_at' => now()->toIso8601String(),
                    'line_count' => $lines->count(),
                ]
            );

            return $ibt;
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
