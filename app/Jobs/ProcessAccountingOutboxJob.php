<?php

namespace App\Jobs;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Accounting\AccountingOutboxProcessorService;
use App\Services\BranchContext;
use App\Services\Observability\RequestCorrelation;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAccountingOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public string $correlationId;

    public function __construct(public string $outboxId, ?string $correlationId = null)
    {
        $this->onQueue('accounting');

        $requestCorrelation = app(RequestCorrelation::class);

        $this->correlationId = $requestCorrelation->normalize($correlationId)
            ?? $requestCorrelation->resolveCurrentOrGenerate();
    }

    public function handle(
        AccountingOutboxProcessorService $processor,
        TenantContext $tenantContext,
        BranchContext $branchContext,
        RequestCorrelation $requestCorrelation
    ): void {
        $correlationId = $requestCorrelation->restoreForQueue($this->correlationId);
        $queueContext = $this->queueContext($correlationId);

        Log::shareContext($queueContext);

        try {
            $record = AccountingOutbox::withoutGlobalScope('tenant')->findOrFail($this->outboxId);
            $tenant = Tenant::findOrFail($record->tenant_id);

            $tenantContext->setTenant($tenant);
            $branch = Branch::findOrFail($record->branch_id);
            $branchContext->setBranch($branch);

            $queueContext = $this->queueContext($correlationId, $record);
            Log::shareContext($queueContext);
            Log::info('accounting.outbox_job.started', $queueContext);

            // Gating verification: QuickBooks sync is an Enterprise/Professional feature
            if (!$tenant->hasFeature('quickbooks.sync')) {
                $record->sync_status = 'failed';
                $record->sync_error = 'Tenant does not have subscription to quickbooks.sync.';
                $record->sync_error_category = 'subscription';
                $record->save();

                Log::warning('accounting.outbox_job.blocked_by_subscription', $queueContext);
                return;
            }

            $processor->process($record);


            $record->refresh();
            $queueContext = $this->queueContext($correlationId, $record);
            Log::shareContext($queueContext);

            if ($record->sync_status === 'failed') {
                Log::warning('accounting.outbox_job.failed', $queueContext);

                return;
            }

            Log::info('accounting.outbox_job.completed', $queueContext);
        } catch (Throwable $exception) {
            Log::error('accounting.outbox_job.exception', array_merge($queueContext, [
                'error_category' => 'system',
                'exception_class' => $exception::class,
            ]));

            throw $exception;
        } finally {
            Log::withoutContext(array_keys($this->queueContext(null)));
            $requestCorrelation->clear();
            $branchContext->clear();
            $tenantContext->clear();
        }
    }

    protected function queueContext(?string $correlationId, ?AccountingOutbox $record = null): array
    {
        return [
            'correlation_id' => $correlationId,
            'job_class' => static::class,
            'queue' => $this->queue,
            'outbox_id' => $this->outboxId,
            'tenant_id' => $record?->tenant_id,
            'branch_id' => $record?->branch_id,
            'attempt_count' => $this->attempts(),
        ];
    }
}
