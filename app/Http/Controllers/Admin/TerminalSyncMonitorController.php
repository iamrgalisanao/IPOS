<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesMachineProfile;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

use App\Services\POS\OfflineReadiness\TerminalConfigDriftService;

class TerminalSyncMonitorController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected TerminalConfigDriftService $driftService
    ) {}

    /**
     * Renders the Terminal Sync Monitor dashboard view.
     */
    public function index(Request $request)
    {
        $branches = Branch::orderBy('name')->get(['id', 'name', 'branch_code']);

        return Inertia::render('Admin/TerminalSyncMonitor/Index', [
            'branches' => $branches,
            'filters'  => $request->only(['branch_id']),
        ]);
    }

    /**
     * API endpoint returning monitor statistics.
     */
    public function getMonitorData(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant context missing.'], 403);
        }

        $branchQuery = SalesMachineProfile::query()
            ->with(['branch:id,name,branch_code']);

        if ($this->branchContext->hasBranch()) {
            $branchQuery->where('branch_id', $this->branchContext->getBranchId());
        } elseif ($request->filled('branch_id')) {
            $branchQuery->where('branch_id', $request->branch_id);
        }

        $terminals = $branchQuery->orderBy('profile_code')->get();

        $terminalMetrics = $terminals->map(function ($profile) {
            $pendingCount = OfflineSalesImport::withoutGlobalScopes()
                ->where('tenant_id', $profile->tenant_id)
                ->where('sales_machine_profile_id', $profile->id)
                ->whereIn('status', [OfflineSalesImport::STATUS_PENDING, OfflineSalesImport::STATUS_HOLD])
                ->count();

            $failedCount = OfflineSalesImport::withoutGlobalScopes()
                ->where('tenant_id', $profile->tenant_id)
                ->where('sales_machine_profile_id', $profile->id)
                ->whereIn('status', [OfflineSalesImport::STATUS_REJECTED, OfflineSalesImport::STATUS_CONFLICT])
                ->count();

            $duplicateCount = OfflineSalesImport::withoutGlobalScopes()
                ->where('tenant_id', $profile->tenant_id)
                ->where('sales_machine_profile_id', $profile->id)
                ->where('status', OfflineSalesImport::STATUS_DUPLICATE)
                ->count();

            $postedCount = OfflineSalesImport::withoutGlobalScopes()
                ->where('tenant_id', $profile->tenant_id)
                ->where('sales_machine_profile_id', $profile->id)
                ->where('status', OfflineSalesImport::STATUS_POSTED)
                ->count();

            $lastBatch = OfflineSyncBatch::withoutGlobalScopes()
                ->where('tenant_id', $profile->tenant_id)
                ->where('sales_machine_profile_id', $profile->id)
                ->latest()
                ->first();

            $status = 'synced';
            if ($failedCount > 0) {
                $status = 'failed';
            } elseif ($pendingCount > 0) {
                $status = 'pending';
            }

            // Query the latest recorded heartbeat for this terminal profile
            $heartbeat = \App\Models\TerminalConfigHeartbeat::withoutGlobalScopes()
                ->where('sales_machine_profile_id', $profile->id)
                ->first();

            $latestImport = null;
            if (!$heartbeat) {
                // Derive client reported configuration snapshot from the latest offline sales import
                $latestImport = OfflineSalesImport::withoutGlobalScopes()
                    ->where('tenant_id', $profile->tenant_id)
                    ->where('sales_machine_profile_id', $profile->id)
                    ->whereNotNull('raw_payload')
                    ->latest('submitted_at')
                    ->first();
            }

            $serverSnapshot = $this->driftService->buildServerSnapshot($profile);

            if ($heartbeat) {
                $clientSnapshot = $heartbeat->config_snapshot ? $this->driftService->extractClientSnapshot($heartbeat->config_snapshot) : null;
                $configAudit = $this->driftService->compare($serverSnapshot, $clientSnapshot, $heartbeat->reported_at);
            } else {
                $clientSnapshot = $latestImport ? $this->driftService->extractClientSnapshot($latestImport->raw_payload) : null;
                $configAudit = $this->driftService->compare($serverSnapshot, $clientSnapshot, $latestImport?->submitted_at);
            }

            return [
                'id' => $profile->id,
                'profile_code' => $profile->profile_code,
                'terminal_identifier' => $profile->terminal_identifier ?: $profile->profile_code,
                'branch' => [
                    'id' => $profile->branch->id,
                    'name' => $profile->branch->name,
                    'branch_code' => $profile->branch->branch_code,
                ],
                'last_sync_at' => $profile->last_offline_sync_at ? $profile->last_offline_sync_at->toIso8601String() : null,
                'status' => $status,
                'pending_count' => $pendingCount,
                'failed_count' => $failedCount,
                'duplicate_count' => $duplicateCount,
                'posted_count' => $postedCount,
                'last_batch' => $lastBatch ? [
                    'id' => $lastBatch->id,
                    'batch_reference' => $lastBatch->batch_reference,
                    'status' => $lastBatch->status,
                    'submitted_import_count' => $lastBatch->submitted_import_count,
                    'processed_count' => $lastBatch->processed_count,
                    'failed_count' => $lastBatch->failed_count,
                    'sync_started_at' => $lastBatch->sync_started_at ? $lastBatch->sync_started_at->toIso8601String() : null,
                    'sync_completed_at' => $lastBatch->sync_completed_at ? $lastBatch->sync_completed_at->toIso8601String() : null,
                ] : null,
                'config_audit' => $configAudit,
                'heartbeat' => $heartbeat ? [
                    'app_version' => $heartbeat->app_version,
                    'device_id' => $heartbeat->device_id,
                    'last_snapshot_downloaded_at' => $heartbeat->last_snapshot_downloaded_at ? $heartbeat->last_snapshot_downloaded_at->toIso8601String() : null,
                    'last_successful_sync_at' => $heartbeat->last_successful_sync_at ? $heartbeat->last_successful_sync_at->toIso8601String() : null,
                    'queue_count' => $heartbeat->queue_count,
                    'connection_state' => $heartbeat->connection_state,
                    'reported_at' => $heartbeat->reported_at ? $heartbeat->reported_at->toIso8601String() : null,
                ] : null,
            ];
        });

        $recentSyncsQuery = OfflineSyncBatch::withoutGlobalScopes()
            ->with(['salesMachineProfile:id,profile_code,terminal_identifier', 'branch:id,name,branch_code'])
            ->where('tenant_id', $tenant->id);

        if ($this->branchContext->hasBranch()) {
            $recentSyncsQuery->where('branch_id', $this->branchContext->getBranchId());
        } elseif ($request->filled('branch_id')) {
            $recentSyncsQuery->where('branch_id', $request->branch_id);
        }

        $recentSyncs = $recentSyncsQuery->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'batch_reference' => $batch->batch_reference,
                    'terminal_identifier' => $batch->salesMachineProfile->terminal_identifier ?? $batch->salesMachineProfile->profile_code ?? 'Unknown',
                    'branch_name' => $batch->branch->name ?? 'Unknown',
                    'status' => $batch->status,
                    'submitted_import_count' => $batch->submitted_import_count,
                    'processed_count' => $batch->processed_count,
                    'failed_count' => $batch->failed_count,
                    'sync_completed_at' => $batch->sync_completed_at ? $batch->sync_completed_at->toIso8601String() : null,
                ];
            });

        return response()->json([
            'terminals' => $terminalMetrics,
            'recent_syncs' => $recentSyncs,
        ]);
    }
}
