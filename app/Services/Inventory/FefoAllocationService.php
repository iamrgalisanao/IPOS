<?php

namespace App\Services\Inventory;

use App\Models\ExpiryLot;
use App\Models\Tenant;
use App\Services\TenantContext;
use App\Exceptions\Inventory\InsufficientStockException;
use Illuminate\Support\Facades\DB;

class FefoAllocationService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Allocate and deduct inventory from expiring lots based on FEFO rules.
     *
     * @param string $tenantId
     * @param string $branchId
     * @param string $productId
     * @param string $requestedQty
     * @return array
     * @throws InsufficientStockException
     */
    public function allocate(string $tenantId, string $branchId, string $productId, string $requestedQty): array
    {
        // 1. Enforce strict tenant isolation context
        if (!$this->tenantContext->hasTenant()) {
            $tenant = Tenant::findOrFail($tenantId);
            $this->tenantContext->setTenant($tenant);
        } else {
            $contextTenantId = $this->tenantContext->getTenantId();
            if ($contextTenantId !== $tenantId) {
                throw new \RuntimeException("Tenant context mismatch: expected {$tenantId}, but context is {$contextTenantId}.");
            }
        }

        // 2. Perform allocation recursively within a database transaction with pessimistic locking
        return DB::transaction(function () use ($branchId, $productId, $requestedQty) {
            // Fetch active, unexpired lots sorted by expiry_date ASC (earliest first), then created_at ASC (tie-breaker)
            $lots = ExpiryLot::where('branch_id', $branchId)
                ->where('product_id', $productId)
                ->where('status', 'active')
                ->where('quantity_remaining', '>', 0)
                ->where('expiry_date', '>', now()->toDateString())
                ->orderBy('expiry_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->lockForUpdate() // Pessimistic row lock
                ->get();

            // Calculate total available unexpired stock using high-precision bcmath (scale 4)
            $totalAvailable = '0.0000';
            foreach ($lots as $lot) {
                $totalAvailable = bcadd($totalAvailable, $lot->quantity_remaining, 4);
            }

            // If total available stock is less than requested, fail immediately
            if (bccomp($totalAvailable, $requestedQty, 4) === -1) {
                throw new InsufficientStockException("Insufficient unexpired stock available.");
            }

            $remainingToDeduct = $requestedQty;
            $allocations = [];

            foreach ($lots as $lot) {
                if (bccomp($remainingToDeduct, '0.0000', 4) === 0) {
                    break;
                }

                $lotRemaining = $lot->quantity_remaining;

                if (bccomp($lotRemaining, $remainingToDeduct, 4) >= 0) {
                    // Lot has enough or exact quantity to fulfill the remaining requested amount
                    $newRemaining = bcsub($lotRemaining, $remainingToDeduct, 4);
                    $lot->quantity_remaining = $newRemaining;

                    if (bccomp($newRemaining, '0.0000', 4) === 0) {
                        $lot->status = 'depleted';
                    }

                    $allocations[] = [
                        'expiry_lot_id' => $lot->id,
                        'batch_code' => $lot->batch_code,
                        'quantity_allocated' => $remainingToDeduct,
                    ];

                    $lot->save();
                    $remainingToDeduct = '0.0000';
                } else {
                    // Lot is partially depleted; exhaust this lot and continue to the next one
                    $allocations[] = [
                        'expiry_lot_id' => $lot->id,
                        'batch_code' => $lot->batch_code,
                        'quantity_allocated' => $lotRemaining,
                    ];

                    $lot->quantity_remaining = '0.0000';
                    $lot->status = 'depleted';
                    $lot->save();

                    $remainingToDeduct = bcsub($remainingToDeduct, $lotRemaining, 4);
                }
            }

            return $allocations;
        });
    }
}
