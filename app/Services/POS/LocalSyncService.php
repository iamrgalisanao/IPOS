<?php

namespace App\Services\POS;

use App\Models\LocalSyncBroker;
use App\Models\LocalTableLock;
use App\Models\SalesMachineProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LocalSyncService
{
    /**
     * Register or update the master broker for a branch.
     */
    public function registerBroker(SalesMachineProfile $profile, string $ipAddress, int $port = 8000): LocalSyncBroker
    {
        return DB::transaction(function () use ($profile, $ipAddress, $port) {
            // Deactivate other brokers in the same branch
            LocalSyncBroker::where('branch_id', $profile->branch_id)
                ->where('master_profile_id', '!=', $profile->id)
                ->update(['status' => 'inactive']);

            return LocalSyncBroker::updateOrCreate(
                [
                    'tenant_id' => $profile->tenant_id,
                    'branch_id' => $profile->branch_id,
                    'master_profile_id' => $profile->id,
                ],
                [
                    'local_ip_address' => $ipAddress,
                    'local_port' => $port,
                    'last_heartbeat_at' => Carbon::now(),
                    'status' => 'active',
                ]
            );
        });
    }

    /**
     * Discover the active master broker in a branch.
     */
    public function discoverBroker(string $tenantId, string $branchId): ?LocalSyncBroker
    {
        // Auto-cleanup expired heartbeats (older than 5 minutes)
        LocalSyncBroker::where('branch_id', $branchId)
            ->where('status', 'active')
            ->where('last_heartbeat_at', '<', Carbon::now()->subMinutes(5))
            ->update(['status' => 'inactive']);

        return LocalSyncBroker::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Acquire a table/cart lock.
     * Throws an exception on conflict.
     */
    public function acquireTableLock(string $tenantId, string $branchId, string $tableId, string $lockedByProfileId): LocalTableLock
    {
        return DB::transaction(function () use ($tenantId, $branchId, $tableId, $lockedByProfileId) {
            // 1. Clean up expired locks first
            LocalTableLock::where('branch_id', $branchId)
                ->where('expires_at', '<', Carbon::now())
                ->delete();

            // 2. Query active lock
            $activeLock = LocalTableLock::where('branch_id', $branchId)
                ->where('table_id', $tableId)
                ->first();

            if ($activeLock) {
                if ($activeLock->locked_by_profile_id !== $lockedByProfileId) {
                    throw new \RuntimeException("Table is locked by another register.");
                }

                // Renew existing lock
                $activeLock->update([
                    'locked_at' => Carbon::now(),
                    'expires_at' => Carbon::now()->addMinutes(15),
                ]);

                return $activeLock;
            }

            // Create new lock
            return LocalTableLock::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'table_id' => $tableId,
                'locked_by_profile_id' => $lockedByProfileId,
                'locked_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addMinutes(15),
            ]);
        });
    }

    /**
     * Release a table/cart lock.
     */
    public function releaseTableLock(string $tenantId, string $branchId, string $tableId, string $lockedByProfileId): bool
    {
        $lock = LocalTableLock::where('branch_id', $branchId)
            ->where('table_id', $tableId)
            ->first();

        if (!$lock) {
            return true;
        }

        if ($lock->locked_by_profile_id !== $lockedByProfileId) {
            throw new \RuntimeException("Cannot unlock a table locked by another register.");
        }

        return (bool) $lock->delete();
    }
}
