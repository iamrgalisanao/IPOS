<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\SyncBatchRequest;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\POS\OfflineSync\OfflineReconciliationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * OfflineSyncController (Story 28.7 — Validation Layer)
 *
 * Replaces the 503 stub from Story 28.6 with real batch intake behaviour.
 * This endpoint validates incoming offline sync payloads and records their
 * import/dedup status. It does NOT create official Sale records, update GCT,
 * or finalize any official financial ledger entries.
 *
 * Official posting is deferred to Story 28.8+.
 */
class OfflineSyncController extends Controller
{
    public function __construct(
        protected OfflineReconciliationService $reconciliationService,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
    ) {}

    /**
     * POST /api/pos/offline-sync
     *
     * Accepts a batch of offline transaction imports from a POS terminal.
     * Returns per-import classification results (pending / duplicate / rejected).
     *
     * Responses:
     *  202 Accepted          — Batch received and processed (new batch).
     *  200 OK                — Idempotent replay of an already-processed batch.
     *  422 Unprocessable     — Offline not enabled for this terminal, or validation error.
     */
    public function sync(SyncBatchRequest $request): JsonResponse
    {
        $tenant  = $this->tenantContext->getTenant();
        $branch  = $this->branchContext->getBranch();

        if (!$tenant || !$branch) {
            return response()->json([
                'error'   => 'MISSING_CONTEXT',
                'message' => 'Tenant and Branch contexts are required.',
            ], 403);
        }

        $requestedTerminalId = $request->header('X-Terminal-ID')
            ?: data_get($request->input('imports.0'), 'terminal_id')
            ?: data_get($request->input('imports.0'), 'sales_machine_profile_id');

        // Resolve the exact terminal that captured the offline sale. Branches can
        // have multiple active profiles; using the first active profile can reject
        // a valid queue with a wrong sequence prefix/hash chain.
        $profileQuery = SalesMachineProfile::where('branch_id', $branch->id)
            ->where('status', 'active');

        if ($requestedTerminalId) {
            $profileQuery->where('id', $requestedTerminalId);
        }

        $profile = $profileQuery->first();

        if (!$profile) {
            return response()->json([
                'error'   => 'NO_ACTIVE_TERMINAL',
                'message' => $requestedTerminalId
                    ? 'The requested terminal profile is not active for this branch.'
                    : 'No active terminal profile found for this branch.',
            ], 422);
        }

        try {
            $batch = $this->reconciliationService->receiveImportBatch($profile, $request->validated());
        } catch (\RuntimeException $e) {
            $errCode = 'OFFLINE_NOT_ENABLED';
            if (str_contains($e->getMessage(), 'SEQUENCE_OUT_OF_ORDER') || str_contains($e->getMessage(), 'HASH_CHAIN_BROKEN')) {
                $errCode = 'CHAIN_VALIDATION_FAILED';
            }
            Log::warning('pos.offline_sync.rejected', [
                'error' => $errCode,
                'message' => $e->getMessage(),
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'sales_machine_profile_id' => $profile->id,
                'batch_reference' => $request->input('batch_reference'),
                'offline_sequences' => collect($request->input('imports', []))
                    ->pluck('offline_sequence_number')
                    ->values()
                    ->all(),
            ]);

            return response()->json([
                'error'   => $errCode,
                'message' => $e->getMessage(),
                'detail'  => $e->getMessage(),
            ], 422);
        }

        $isReplay = (bool) ($batch->getAttribute('_replayed') ?? false);

        $importsResult = $batch->imports()
            ->withoutGlobalScopes()
            ->get(['offline_sequence_number', 'status', 'rejection_reason', 'server_recalculation', 'conflict_notes'])
            ->map(fn ($import) => array_filter([
                'offline_sequence_number' => $import->offline_sequence_number,
                'status'                  => $import->status,
                'server_total'            => $import->server_recalculation['server_total'] ?? null,
                'conflict_notes'          => $import->conflict_notes,
                'reason'                  => $import->rejection_reason ?: null,
            ], fn ($v) => $v !== null))
            ->values()
            ->toArray();

        return response()->json([
            'batch_id'        => $batch->id,
            'batch_reference' => $batch->batch_reference,
            'status'          => $batch->status,
            'submitted'       => $batch->submitted_import_count,
            'processed'       => $batch->processed_count,
            'failed'          => $batch->failed_count,
            'imports'         => $importsResult,
        ], $isReplay ? 200 : 202);
    }
}
