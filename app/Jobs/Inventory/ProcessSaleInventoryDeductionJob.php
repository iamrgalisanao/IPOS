<?php

namespace App\Jobs\Inventory;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\BranchContext;
use App\Services\InventoryService;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSaleInventoryDeductionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public string $saleId)
    {
        $this->onQueue('inventory');
    }

    public function handle(
        InventoryService $inventoryService,
        TenantContext $tenantContext,
        BranchContext $branchContext
    ): void {
        $sale = Sale::withoutGlobalScopes()->find($this->saleId);
        if (!$sale) {
            Log::warning('Sale not found for inventory deduction.', ['sale_id' => $this->saleId]);
            throw new ModelNotFoundException("Sale ID {$this->saleId} not found.");
        }

        $previousTenant = $tenantContext->getTenant();
        $previousBranch = $branchContext->getBranch();

        Log::withContext($this->queueContext());

        try {
            $tenant = Tenant::find($sale->tenant_id);
            $branch = Branch::find($sale->branch_id);

            if ($tenant) {
                $tenantContext->setTenant($tenant);
            }
            if ($branch) {
                $branchContext->setBranch($branch);
            }

            $inventoryService->deductFromSale($sale);

        } catch (\Exception $e) {
            Log::error('Inventory deduction job failed.', [
                'sale_id' => $this->saleId,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            Log::withoutContext(array_keys($this->queueContext()));
            
            if ($previousTenant) {
                $tenantContext->setTenant($previousTenant);
            } else {
                $tenantContext->clear();
            }
            
            if ($previousBranch) {
                $branchContext->setBranch($previousBranch);
            } else {
                $branchContext->clear();
            }
        }
    }

    protected function queueContext(?Sale $sale = null): array
    {
        return [
            'job_class' => static::class,
            'queue' => $this->queue,
            'sale_id' => $this->saleId,
            'tenant_id' => $sale?->tenant_id,
            'branch_id' => $sale?->branch_id,
            'attempt_count' => $this->attempts(),
        ];
    }
}
