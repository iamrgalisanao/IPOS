<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\StocktakeLine;
use App\Models\StocktakeSession;
use App\Services\AuditLogger;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StocktakePostingService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Post a reviewed stocktake session.
     *
     * @param StocktakeSession $session
     * @return StocktakeSession
     * @throws ValidationException
     */
    public function post(StocktakeSession $session): StocktakeSession
    {
        // 1. Initial State Validation
        if (!$session->isInReview()) {
            throw new \RuntimeException("Only sessions in 'review' status can be posted. Current status: {$session->status}");
        }

        // 2. Data Integrity Validation
        $uncountedCount = $session->lines()->whereNull('counted_quantity')->count();
        if ($uncountedCount > 0) {
            throw ValidationException::withMessages([
                'session' => ["Cannot post. There are {$uncountedCount} uncounted items."]
            ]);
        }

        // Validate reason codes for variances
        $missingReasons = $session->lines()
            ->whereRaw('ABS(variance_quantity) > 0.0001')
            ->whereNull('reason_code')
            ->count();
        if ($missingReasons > 0) {
            throw ValidationException::withMessages([
                'session' => ["Cannot post. {$missingReasons} items with variance are missing reason codes."]
            ]);
        }

        // Validate remarks for 'OTHER'
        $missingRemarks = $session->lines()
            ->where('reason_code', StocktakeLine::REASON_OTHER)
            ->whereNull('remarks')
            ->count();
        if ($missingRemarks > 0) {
            throw ValidationException::withMessages([
                'session' => ["Cannot post. Remarks are required for items with 'Other' reason code."]
            ]);
        }

        return DB::transaction(function () use ($session) {
            // 3. Row Locking for Double-Posting Protection
            $session = StocktakeSession::where('id', $session->id)->lockForUpdate()->firstOrFail();
            
            if ($session->status === StocktakeSession::STATUS_POSTED) {
                return $session; // Already posted, skip
            }

            if (!$session->isInReview()) {
                throw new \RuntimeException("Session status changed unexpectedly.");
            }

            $user = auth()->user();
            $postedAt = now();
            $totalPosAdj = 0;
            $totalNegAdj = 0;
            $movementCount = 0;

            // 4. Process Lines
            $lines = $session->lines()->get();
            foreach ($lines as $line) {
                $variance = (float) $line->variance_quantity;
                
                if (abs($variance) < 0.0001) {
                    continue; // Skip zero variance
                }

                // Lock the branch inventory row
                $inventory = BranchInventory::where('tenant_id', $session->tenant_id)
                    ->where('branch_id', $session->branch_id)
                    ->where('product_id', $line->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    // This should theoretically not happen if snapshot was taken correctly
                    continue;
                }

                $quantityBefore = $inventory->current_stock;
                $quantityAfter = $quantityBefore + $variance;

                // Update Inventory
                $inventory->update([
                    'current_stock' => $quantityAfter,
                    'last_counted_at' => $postedAt,
                ]);

                // Create Movement
                InventoryMovement::create([
                    'tenant_id' => $session->tenant_id,
                    'branch_id' => $session->branch_id,
                    'product_id' => $line->product_id,
                    'branch_inventory_id' => $inventory->id,
                    'movement_type' => 'STOCKTAKE_ADJUSTMENT',
                    'quantity_change' => $variance,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'source_type' => 'stocktake_session',
                    'source_id' => $session->id,
                    'reference_number' => $session->stocktake_number,
                    'user_id' => $user->id,
                    'reason_code' => $line->reason_code,
                    'remarks' => $line->remarks,
                ]);

                if ($variance > 0) $totalPosAdj += $variance;
                else $totalNegAdj += abs($variance);
                
                $movementCount++;
            }

            // 5. Finalize Session
            $session->update([
                'status' => StocktakeSession::STATUS_POSTED,
                'posted_by' => $user->id,
                'posted_at' => $postedAt,
            ]);

            // 6. Audit Logging
            $this->auditLogger->log(
                action: 'stocktake_posted',
                auditable: $session,
                metadata: [
                    'stocktake_number' => $session->stocktake_number,
                    'line_count' => $lines->count(),
                    'movement_count' => $movementCount,
                    'total_positive_adjustment' => $totalPosAdj,
                    'total_negative_adjustment' => $totalNegAdj,
                    'posted_by' => $user->name,
                ]
            );

            return $session;
        });
    }
}
