<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\SalesMachineProfile;
use App\Services\POS\LocalSyncService;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LocalSyncController extends Controller
{
    public function __construct(
        protected LocalSyncService $localSyncService,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Register a register/terminal machine profile as the active master LAN broker.
     */
    public function registerBroker(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenant()?->id;
        $branchId = $this->branchContext->getBranch()?->id;

        if (!$tenantId || !$branchId) {
            return response()->json([
                'success' => false,
                'code' => 'CONTEXT_MISSING',
                'message' => 'Active Tenant and Branch contexts are required.'
            ], 403);
        }

        $request->validate([
            'sales_machine_profile_id' => 'required|uuid',
            'local_ip_address' => 'required|ip',
            'local_port' => 'nullable|integer|min:1|max:65535',
        ]);

        $profile = SalesMachineProfile::where('id', $request->input('sales_machine_profile_id'))
            ->where('branch_id', $branchId)
            ->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'code' => 'PROFILE_NOT_FOUND',
                'message' => 'Target Sales Machine Profile not found in this branch.'
            ], 404);
        }

        try {
            $port = $request->input('local_port', 8000);
            $broker = $this->localSyncService->registerBroker($profile, $request->input('local_ip_address'), $port);

            return response()->json([
                'success' => true,
                'message' => 'Local sync broker registered successfully.',
                'data' => [
                    'broker_id' => $broker->id,
                    'master_profile_id' => $broker->master_profile_id,
                    'local_ip_address' => $broker->local_ip_address,
                    'local_port' => $broker->local_port,
                    'status' => $broker->status,
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'REGISTRATION_FAILED',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Discover the active master broker inside the current branch.
     */
    public function discoverBroker(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenant()?->id;
        $branchId = $this->branchContext->getBranch()?->id;

        if (!$tenantId || !$branchId) {
            return response()->json([
                'success' => false,
                'code' => 'CONTEXT_MISSING',
                'message' => 'Active Tenant and Branch contexts are required.'
            ], 403);
        }

        $broker = $this->localSyncService->discoverBroker($tenantId, $branchId);

        if (!$broker) {
            return response()->json([
                'success' => false,
                'code' => 'BROKER_NOT_FOUND',
                'message' => 'No active local sync broker found for this branch.'
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'broker_id' => $broker->id,
                'master_profile_id' => $broker->master_profile_id,
                'local_ip_address' => $broker->local_ip_address,
                'local_port' => $broker->local_port,
                'last_heartbeat_at' => $broker->last_heartbeat_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * Lock a dining table/cart state.
     */
    public function lockTable(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenant()?->id;
        $branchId = $this->branchContext->getBranch()?->id;

        if (!$tenantId || !$branchId) {
            return response()->json([
                'success' => false,
                'code' => 'CONTEXT_MISSING',
                'message' => 'Active Tenant and Branch contexts are required.'
            ], 403);
        }

        $request->validate([
            'table_id' => 'required|string',
            'sales_machine_profile_id' => 'required|uuid',
        ]);

        $profile = SalesMachineProfile::where('id', $request->input('sales_machine_profile_id'))
            ->where('branch_id', $branchId)
            ->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'code' => 'PROFILE_NOT_FOUND',
                'message' => 'Target Sales Machine Profile not found in this branch.'
            ], 404);
        }

        try {
            $lock = $this->localSyncService->acquireTableLock(
                $tenantId,
                $branchId,
                $request->input('table_id'),
                $profile->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Table lock acquired successfully.',
                'data' => [
                    'lock_id' => $lock->id,
                    'table_id' => $lock->table_id,
                    'expires_at' => $lock->expires_at->toIso8601String(),
                ]
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'code' => 'TABLE_LOCKED',
                'message' => $e->getMessage()
            ], 409);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'LOCK_FAILED',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Unlock a dining table/cart state.
     */
    public function unlockTable(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenant()?->id;
        $branchId = $this->branchContext->getBranch()?->id;

        if (!$tenantId || !$branchId) {
            return response()->json([
                'success' => false,
                'code' => 'CONTEXT_MISSING',
                'message' => 'Active Tenant and Branch contexts are required.'
            ], 403);
        }

        $request->validate([
            'table_id' => 'required|string',
            'sales_machine_profile_id' => 'required|uuid',
        ]);

        $profile = SalesMachineProfile::where('id', $request->input('sales_machine_profile_id'))
            ->where('branch_id', $branchId)
            ->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'code' => 'PROFILE_NOT_FOUND',
                'message' => 'Target Sales Machine Profile not found in this branch.'
            ], 404);
        }

        try {
            $this->localSyncService->releaseTableLock(
                $tenantId,
                $branchId,
                $request->input('table_id'),
                $profile->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Table lock released successfully.'
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'code' => 'UNLOCK_BLOCKED',
                'message' => $e->getMessage()
            ], 409);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'UNLOCK_FAILED',
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
