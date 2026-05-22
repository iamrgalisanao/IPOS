<?php

namespace App\Services\Procurement;

use App\Models\MasterPurchaseOrder;
use App\Models\MasterPurchaseOrderAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Branch;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterPoSplitService
{
    public function __construct(
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Split an approved Master Purchase Order into branch-specific child Purchase Orders atomically.
     *
     * Preconditions:
     * 1. Master PO must be in `approved` status.
     * 2. If already `split`, exits cleanly (idempotency).
     * 3. Total allocated quantity per product line must be <= total ordered quantity.
     * 4. Allocation quantities must be > 0.
     * 5. Allocation branches must belong to the same tenant as the Master PO.
     * 6. User performing the split must have the proper authority (Owner/Admin, Tenant Owner, or Procurement Manager).
     */
    public function split(MasterPurchaseOrder $masterPo, User $user): MasterPurchaseOrder
    {
        // 1. RBAC Guard: User must be Tenant Owner, Owner/Admin, or Procurement Manager
        $authorized = $user->roles()->whereIn('name', ['Owner/Admin', 'Tenant Owner', 'Procurement Manager'])->exists()
            || ($user->actor_type === 'tenant_user' && $user->roles()->where('name', 'Owner/Admin')->exists()); // fallback

        if (!$authorized) {
            throw new \RuntimeException(
                "User is not authorized to split Master Purchase Orders. Minimum role: Procurement Manager.",
                403
            );
        }

        // 2. Idempotency Guard: If already split, return cleanly
        if ($masterPo->isSplit()) {
            return $masterPo;
        }

        // 3. Precondition Guard: Must be approved
        if (!$masterPo->isApproved()) {
            throw new \RuntimeException(
                "Only approved Master Purchase Orders can be split. Current status: {$masterPo->status}"
            );
        }

        // Ensure lines and allocations exist
        $lines = $masterPo->lines()->with('allocations.branch')->get();
        if ($lines->isEmpty()) {
            throw new \RuntimeException("Cannot split a Master Purchase Order with no product lines.");
        }

        // Validate allocations
        $allAllocations = collect();
        foreach ($lines as $line) {
            $allocations = $line->allocations;
            if ($allocations->isEmpty()) {
                throw new \RuntimeException("Product line {$line->id} has no branch allocations.");
            }

            $totalAllocated = 0.0000;
            foreach ($allocations as $alloc) {
                // Fetch branch directly via DB to bypass global tenant scoping in validation
                $branch = DB::table('branches')->where('id', $alloc->branch_id)->first();

                // Ensure branch exists and belongs to the same tenant
                if (!$branch || $branch->tenant_id !== $masterPo->tenant_id) {
                    throw new \RuntimeException(
                        "Cross-tenant allocation or transfer is strictly forbidden."
                    );
                }

                // Guard: No branch may receive a zero or negative quantity allocation
                if ($alloc->allocated_quantity <= 0) {
                    throw ValidationException::withMessages([
                        'allocations' => ["Allocation quantity for branch {$branch->branch_code} must be greater than zero."]
                    ]);
                }

                $totalAllocated += (float) $alloc->allocated_quantity;
                $allAllocations->push($alloc);
            }

            // Guard: Total allocated quantity across all branches must be <= total ordered quantity
            if ($totalAllocated > (float) $line->total_ordered_quantity) {
                throw ValidationException::withMessages([
                    'allocations' => ["Total allocated quantity for product {$line->product_id} exceeds the total ordered quantity."]
                ]);
            }
        }

        // 4. Atomic split inside DB transaction with pessimistic locking
        return DB::transaction(function () use ($masterPo, $user, $allAllocations, $lines) {
            // Re-fetch with lock for update to prevent race conditions
            $masterPo = MasterPurchaseOrder::where('id', $masterPo->id)->lockForUpdate()->firstOrFail();

            // Double check idempotency
            if ($masterPo->isSplit()) {
                return $masterPo;
            }

            if (!$masterPo->isApproved()) {
                throw new \RuntimeException(
                    "Master Purchase Order status changed unexpectedly during split transaction."
                );
            }

            // Group all allocations by branch_id
            $allocationsByBranch = $allAllocations->groupBy('branch_id');

            $createdPoIds = [];

            foreach ($allocationsByBranch as $branchId => $branchAllocations) {
                // Generate unique PO number for the branch
                $poNumber = PurchaseOrder::generatePoNumber(
                    $masterPo->tenant_id,
                    $branchId,
                    $masterPo->order_date->format('Y-m-d')
                );

                // Create the child PurchaseOrder in APPROVED status (since Master PO was approved)
                $childPo = PurchaseOrder::create([
                    'tenant_id' => $masterPo->tenant_id,
                    'branch_id' => $branchId,
                    'supplier_id' => $masterPo->supplier_id,
                    'master_purchase_order_id' => $masterPo->id,
                    'po_number' => $poNumber,
                    'status' => PurchaseOrder::STATUS_APPROVED,
                    'order_date' => $masterPo->order_date,
                    'expected_delivery_date' => $masterPo->expected_delivery_date,
                    'notes' => "Split from Master Purchase Order {$masterPo->master_po_number}. " . $masterPo->notes,
                    'created_by' => $masterPo->created_by,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ]);

                $totalEstimatedAmount = 0.0000;

                foreach ($branchAllocations as $alloc) {
                    $mpoLine = $alloc->masterPurchaseOrderLine;
                    $lineTotal = (float) $alloc->allocated_quantity * (float) $mpoLine->unit_cost;

                    PurchaseOrderLine::create([
                        'purchase_order_id' => $childPo->id,
                        'product_id' => $mpoLine->product_id,
                        'ordered_quantity' => $alloc->allocated_quantity,
                        'received_quantity' => 0.0000,
                        'unit_cost' => $mpoLine->unit_cost,
                        'line_total' => $lineTotal,
                    ]);

                    // Update allocation to point to this child PO
                    $alloc->update(['child_purchase_order_id' => $childPo->id]);

                    $totalEstimatedAmount += $lineTotal;
                }

                // Update total estimated amount on the child PO
                $childPo->update(['total_estimated_amount' => $totalEstimatedAmount]);

                $createdPoIds[] = $childPo->id;
            }

            // Update Master PO status and split_at timestamp
            $masterPo->forceFill([
                'status' => MasterPurchaseOrder::STATUS_SPLIT,
                'split_at' => now(),
            ])->save();

            // Log Audit event
            $this->auditLogger->log(
                action: 'master_po_split',
                auditable: $masterPo,
                metadata: [
                    'tenant_id' => $masterPo->tenant_id,
                    'master_purchase_order_id' => $masterPo->id,
                    'master_po_number' => $masterPo->master_po_number,
                    'split_at' => $masterPo->split_at->toIso8601String(),
                    'child_po_ids' => $createdPoIds,
                    'triggered_by' => $user->id,
                ]
            );

            return $masterPo;
        });
    }
}
