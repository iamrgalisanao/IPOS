<?php

namespace App\Services\Accounting;

use App\Models\AccountingOutbox;
use App\Models\AccountingSyncAttempt;
use App\Models\Branch;
use App\Services\BranchContext;
use Illuminate\Support\Collection;

class AccountingOutboxProcessorService
{
    public function __construct(
        protected AccountingOutboxSyncStateService $stateService,
        protected NormalizedPayloadService $normalizationService,
        protected BranchContext $branchContext,
        protected QuickBooksSyncService $quickBooksSyncService
    ) {}

    /**
     * Process a single outbox record using a simulated local handler.
     */
    public function process(AccountingOutbox $record, callable $handler = null): void
    {
        $startedAt = now();
        $previousBranch = $this->branchContext->getBranch();

        // 1. Claim the record
        $this->stateService->markAsProcessing($record);
        $record->refresh();
        $this->activateBranchContext($record);

        try {
            // 2. Normalize the payload
            $normalizedPayload = $this->normalizationService->normalize($record);

            // 3. Process against QuickBooks by default, or use the provided handler in tests.
            $result = $handler
                ? $handler($normalizedPayload, $record)
                : $this->quickBooksSyncService->sync($record);
            $externalReference = is_array($result) ? $result : [];

            // 4. Mark as synced on success
            $this->stateService->markAsSynced($record, $externalReference);
            $record->refresh();
            $this->recordAttempt($record, 'synced', $startedAt);

        } catch (\Exception $e) {
            $category = $this->classifyError($e);
            $nextAttemptAt = $this->nextAttemptAt($record);

            // 5. Mark as failed on error
            $this->stateService->markAsFailed($record, $e->getMessage(), $category, $nextAttemptAt);
            $record->refresh();
            $this->recordAttempt($record, 'failed', $startedAt, $category, $e->getMessage());
        } finally {
            $previousBranch
                ? $this->branchContext->setBranch($previousBranch)
                : $this->branchContext->clear();
        }
    }

    /**
     * Get eligible records for processing (pending or failed).
     */
    public function getEligibleRecords(int $limit = 50, bool $includeFailed = true): Collection
    {
        $query = AccountingOutbox::whereIn('sync_status', $includeFailed ? ['pending', 'failed'] : ['pending'])
            ->where(function ($query) {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->orderBy('created_at', 'asc')
            ->limit($limit);

        return $query->get();
    }

    protected function recordAttempt(
        AccountingOutbox $record,
        string $status,
        \DateTimeInterface $startedAt,
        ?string $errorCategory = null,
        ?string $errorMessage = null
    ): void {
        $finishedAt = now();

        AccountingSyncAttempt::create([
            'tenant_id' => $record->tenant_id,
            'branch_id' => $record->branch_id,
            'accounting_outbox_id' => $record->id,
            'attempt_number' => $record->attempt_count,
            'status' => $status,
            'error_category' => $errorCategory,
            'error_message' => $errorMessage ? substr($errorMessage, 0, 1000) : null,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, (int) $finishedAt->diffInMilliseconds($startedAt, true)),
        ]);
    }

    protected function classifyError(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, '401'),
            str_contains($message, '403'),
            str_contains($message, 'auth'),
            str_contains($message, 'oauth'),
            str_contains($message, 'token') => 'auth',

            str_contains($message, 'map'),
            str_contains($message, 'mapping'),
            str_contains($message, 'unknown event'),
            str_contains($message, 'undefined array key') => 'mapping',

            str_contains($message, '429'),
            str_contains($message, 'rate limit'),
            str_contains($message, 'too many requests') => 'rate_limit',

            str_contains($message, 'timeout'),
            str_contains($message, 'connection'),
            str_contains($message, 'network'),
            str_contains($message, 'curl') => 'network',

            default => 'system',
        };
    }

    protected function nextAttemptAt(AccountingOutbox $record): \Illuminate\Support\Carbon
    {
        $minutes = min(60, 2 ** min(max($record->attempt_count, 1), 6));

        return now()->addMinutes($minutes);
    }

    protected function activateBranchContext(AccountingOutbox $record): void
    {
        if (blank($record->branch_id)) {
            $this->branchContext->clear();

            return;
        }

        $branch = Branch::find($record->branch_id);

        if ($branch) {
            $this->branchContext->setBranch($branch);
        }
    }
}
