<?php

namespace App\Jobs\POS;

use App\Events\SalePaid;
use App\Models\Branch;
use App\Models\OfflineSyncConsequenceAttempt;
use App\Models\Tenant;
use App\Services\BranchContext;
use App\Services\Loyalty\LoyaltyAccrualService;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessOfflineSyncConsequenceAttemptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $attemptId)
    {
        $this->onQueue('loyalty');
    }

    public function handle(
        LoyaltyAccrualService $loyaltyAccrualService,
        TenantContext $tenantContext,
        BranchContext $branchContext
    ): void
    {
        $attempt = DB::transaction(function () {
            $attempt = OfflineSyncConsequenceAttempt::query()
                ->whereKey($this->attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($attempt->status, [
                OfflineSyncConsequenceAttempt::STATUS_COMMITTED,
                OfflineSyncConsequenceAttempt::STATUS_SKIPPED_BY_POLICY,
            ], true)) {
                return null;
            }

            if (!in_array($attempt->status, [
                OfflineSyncConsequenceAttempt::STATUS_PENDING,
                OfflineSyncConsequenceAttempt::STATUS_QUEUED,
                OfflineSyncConsequenceAttempt::STATUS_RETRYABLE_FAILED,
            ], true)) {
                return null;
            }

            $attempt->update([
                'status' => OfflineSyncConsequenceAttempt::STATUS_PROCESSING,
                'attempt_no' => ((int) $attempt->attempt_no) + 1,
                'claimed_at' => now(),
                'started_at' => now(),
                'claim_owner' => static::class,
            ]);

            return $attempt->fresh();
        });

        if (!$attempt) {
            return;
        }

        $previousTenant = $tenantContext->getTenant();
        $previousBranch = $branchContext->getBranch();
        $tenant = Tenant::find($attempt->tenant_id);
        $branch = Branch::find($attempt->branch_id);

        if ($tenant) {
            $tenantContext->setTenant($tenant);
        }
        if ($branch) {
            $branchContext->setBranch($branch);
        }

        try {
            $entry = $loyaltyAccrualService->accrueFromSalePaid(
                new SalePaid($attempt->metadata_json['payload'] ?? [])
            );

            $attempt->update([
                'status' => $entry
                    ? OfflineSyncConsequenceAttempt::STATUS_COMMITTED
                    : OfflineSyncConsequenceAttempt::STATUS_SKIPPED_BY_POLICY,
                'completed_at' => now(),
                'result_reference_type' => $entry ? $entry::class : null,
                'result_reference_id' => $entry?->id,
                'last_error_code' => null,
                'last_error_summary' => null,
            ]);

            $this->refreshImportProjection($attempt->fresh());
        } catch (\Throwable $exception) {
            $attempt = $attempt->fresh();
            $exhausted = ((int) $attempt->attempt_no) >= $this->tries;
            $attempt->update([
                'status' => $exhausted
                    ? OfflineSyncConsequenceAttempt::STATUS_FAILED
                    : OfflineSyncConsequenceAttempt::STATUS_RETRYABLE_FAILED,
                'failed_at' => now(),
                'next_retry_at' => $exhausted ? null : now()->addMinutes(5),
                'last_error_code' => $exhausted ? 'loyalty_retry_exhausted' : 'loyalty_processing_failed',
                'last_error_summary' => $exception->getMessage(),
            ]);

            $this->refreshImportProjection($attempt->fresh());

            throw $exception;
        } finally {
            $previousTenant ? $tenantContext->setTenant($previousTenant) : $tenantContext->clear();
            $previousBranch ? $branchContext->setBranch($previousBranch) : $branchContext->clear();
        }
    }

    private function refreshImportProjection(OfflineSyncConsequenceAttempt $attempt): void
    {
        $import = $attempt->import;
        if (!$import) {
            return;
        }

        $current = $import->current_consequence_status ?: $import->acceptance_consequence_snapshot ?: [];
        $current['loyalty'] = match ($attempt->status) {
            OfflineSyncConsequenceAttempt::STATUS_COMMITTED => 'committed',
            OfflineSyncConsequenceAttempt::STATUS_SKIPPED_BY_POLICY => 'skipped_by_policy',
            OfflineSyncConsequenceAttempt::STATUS_PROCESSING => 'processing',
            OfflineSyncConsequenceAttempt::STATUS_RETRYABLE_FAILED => 'retryable_failed',
            OfflineSyncConsequenceAttempt::STATUS_FAILED => 'failed',
            OfflineSyncConsequenceAttempt::STATUS_REVIEW_REQUIRED => 'review_required',
            OfflineSyncConsequenceAttempt::STATUS_PENDING => 'pending',
            default => 'queued',
        };
        $current['loyalty_details'] = [
            'status' => $current['loyalty'],
            'required' => 'conditional',
            'execution_mode' => 'durable_async',
            'attempt_count' => (int) $attempt->attempt_no,
            'last_error_code' => $attempt->last_error_code,
            'updated_at' => now()->toISOString(),
            'result_reference_type' => $attempt->result_reference_type,
            'result_reference_id' => $attempt->result_reference_id,
        ];

        $import->update(['current_consequence_status' => $current]);
    }
}
