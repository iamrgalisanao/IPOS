<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\SyncBatchRequest;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\POS\OfflineSync\OfflineReconciliationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

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

        // Resolve the terminal profile for this branch
        $profile = SalesMachineProfile::where('branch_id', $branch->id)
            ->where('status', 'active')
            ->first();

        if (!$profile) {
            return response()->json([
                'error'   => 'NO_ACTIVE_TERMINAL',
                'message' => 'No active terminal profile found for this branch.',
            ], 422);
        }

        try {
            $batch = $this->reconciliationService->receiveImportBatch($profile, $request->validated());
        } catch (\RuntimeException $e) {
            // Offline not enabled — return 422 with machine-readable error
            return response()->json([
                'error'   => 'OFFLINE_NOT_ENABLED',
                'message' => 'Offline sales intake is not permitted for this terminal.',
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
