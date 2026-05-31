<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class SubmissionLookupController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * GET /api/pos/submissions/{submission_uuid}
     *
     * Retrieves the synchronization status and summary for a given batch ID or reference.
     */
    public function show(string $submission_uuid): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $branch = $this->branchContext->getBranch();

        if (!$tenant || !$branch) {
            return response()->json([
                'error'   => 'MISSING_CONTEXT',
                'message' => 'Tenant and Branch contexts are required.',
            ], 403);
        }

        $profile = SalesMachineProfile::where('branch_id', $branch->id)
            ->where('status', 'active')
            ->first();

        if (!$profile) {
            return response()->json([
                'error'   => 'NO_ACTIVE_TERMINAL',
                'message' => 'No active terminal profile found for this branch.',
            ], 422);
        }

        // Enforce boundary constraints: query batch scoped to tenant and terminal
        $batch = OfflineSyncBatch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('sales_machine_profile_id', $profile->id)
            ->where(function ($query) use ($submission_uuid) {
                $query->where('id', $submission_uuid)
                      ->orWhere('batch_reference', $submission_uuid);
            })
            ->first();

        if (!$batch) {
            // Fail-closed to prevent ID verification
            return response()->json([
                'error'   => 'SUBMISSION_NOT_FOUND',
                'message' => 'The sync submission was not found.',
            ], 404);
        }

        // Gather safe errors details without exposing raw database IDs or payloads
        $errors = $batch->imports()
            ->withoutGlobalScopes()
            ->whereIn('status', [OfflineSalesImport::STATUS_REJECTED, OfflineSalesImport::STATUS_CONFLICT])
            ->get()
            ->map(fn ($imp) => [
                'sequence_number' => $imp->offline_sequence_number,
                'code'            => $imp->status === OfflineSalesImport::STATUS_REJECTED ? 'REJECTED' : 'CONFLICT',
                'message'         => $imp->rejection_reason ?: ($imp->conflict_notes ?: 'Validation mismatch'),
            ])
            ->values()
            ->toArray();

        $statusMap = [
            OfflineSyncBatch::STATUS_RECEIVED   => 'pending',
            OfflineSyncBatch::STATUS_PROCESSING => 'pending',
            OfflineSyncBatch::STATUS_COMPLETED  => 'posted',
            OfflineSyncBatch::STATUS_FAILED     => 'failed',
        ];

        $status = $statusMap[$batch->status] ?? 'failed';

        // Duplicate calculation
        $duplicateCount = $batch->imports()
            ->withoutGlobalScopes()
            ->where('status', OfflineSalesImport::STATUS_DUPLICATE)
            ->count();

        return response()->json([
            'submission_uuid' => $batch->id,
            'status'          => $status,
            'terminal'        => [
                'machine_number'  => $profile->terminal_identifier ?: $profile->profile_code,
                'sequence_prefix' => $profile->offline_sequence_prefix,
            ],
            'summary'         => [
                'submitted_count' => $batch->submitted_import_count,
                'accepted_count'  => $batch->processed_count,
                'rejected_count'  => $batch->failed_count,
                'duplicate_count' => $duplicateCount,
            ],
            'timing'          => [
                'submitted_at'       => $batch->sync_started_at ? $batch->sync_started_at->toIso8601String() : null,
                'server_verified_at' => $batch->sync_completed_at ? $batch->sync_completed_at->toIso8601String() : null,
                'posted_at'          => $batch->sync_completed_at ? $batch->sync_completed_at->toIso8601String() : null,
            ],
            'errors'          => $errors,
        ]);
    }

    /**
     * GET /api/pos/submissions/sequence/{offline_sequence_number}
     *
     * Retrieves the status of an individual sales import by its terminal sequence.
     */
    public function bySequence(string $offline_sequence_number): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $branch = $this->branchContext->getBranch();

        if (!$tenant || !$branch) {
            return response()->json([
                'error'   => 'MISSING_CONTEXT',
                'message' => 'Tenant and Branch contexts are required.',
            ], 403);
        }

        $profile = SalesMachineProfile::where('branch_id', $branch->id)
            ->where('status', 'active')
            ->first();

        if (!$profile) {
            return response()->json([
                'error'   => 'NO_ACTIVE_TERMINAL',
                'message' => 'No active terminal profile found for this branch.',
            ], 422);
        }

        // Security boundary: sequence number must start with terminal's sequence prefix
        if (!empty($profile->offline_sequence_prefix)) {
            if (!str_starts_with($offline_sequence_number, $profile->offline_sequence_prefix)) {
                return response()->json([
                    'error'   => 'SUBMISSION_NOT_FOUND',
                    'message' => 'The sequence number is outside the allowed range.',
                ], 404);
            }
        }

        // Query the import record
        $import = OfflineSalesImport::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('sales_machine_profile_id', $profile->id)
            ->where('offline_sequence_number', $offline_sequence_number)
            ->latest()
            ->first();

        if (!$import) {
            return response()->json([
                'error'   => 'SUBMISSION_NOT_FOUND',
                'message' => 'The sync submission was not found.',
            ], 404);
        }

        // Safe status mapping
        $statusMap = [
            OfflineSalesImport::STATUS_PENDING            => 'pending',
            OfflineSalesImport::STATUS_VALIDATED          => 'validated',
            OfflineSalesImport::STATUS_POSTED             => 'posted',
            OfflineSalesImport::STATUS_REJECTED           => 'rejected',
            OfflineSalesImport::STATUS_DUPLICATE          => 'duplicate',
            OfflineSalesImport::STATUS_SERVER_VERIFIED    => 'validated',
            OfflineSalesImport::STATUS_CONFLICT           => 'conflict',
            OfflineSalesImport::STATUS_HOLD               => 'pending',
            OfflineSalesImport::STATUS_OVERRIDE_APPROVED  => 'validated',
        ];

        $status = $statusMap[$import->status] ?? 'failed';

        return response()->json([
            'offline_sequence_number' => $import->offline_sequence_number,
            'status'                  => $status,
            'rejection_reason'        => $import->rejection_reason ?: ($import->conflict_notes ?: null),
            'timing'                  => [
                'submitted_at'  => $import->submitted_at ? $import->submitted_at->toIso8601String() : null,
                'reconciled_at' => $import->reconciled_at ? $import->reconciled_at->toIso8601String() : null,
            ],
        ]);
    }
}
