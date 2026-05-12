<?php

namespace App\Jobs;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Accounting\AccountingOutboxProcessorService;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAccountingOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $outboxId)
    {
        $this->onQueue('accounting');
    }

    public function handle(
        AccountingOutboxProcessorService $processor,
        TenantContext $tenantContext,
        BranchContext $branchContext
    ): void {
        $record = AccountingOutbox::withoutGlobalScope('tenant')->findOrFail($this->outboxId);
        $tenant = Tenant::findOrFail($record->tenant_id);

        $tenantContext->setTenant($tenant);
        $branch = Branch::findOrFail($record->branch_id);
        $branchContext->setBranch($branch);

        try {
            $processor->process($record);
        } finally {
            $branchContext->clear();
            $tenantContext->clear();
        }
    }
}
