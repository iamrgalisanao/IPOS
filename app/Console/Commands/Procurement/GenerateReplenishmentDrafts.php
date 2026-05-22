<?php

namespace App\Console\Commands\Procurement;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\Procurement\DraftPurchaseOrderGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateReplenishmentDrafts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ipos:generate-replenishment-drafts {--tenant= : Run calculations for a specific tenant ID only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automate procurement replenishment recommendation calculations and generate draft purchase orders';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantIdOption = $this->option('tenant');

        if ($tenantIdOption) {
            $tenant = Tenant::where('id', $tenantIdOption)->first();
            if (!$tenant) {
                $this->error("Tenant ID '{$tenantIdOption}' not found.");
                Log::error("Replenishment scheduler triggered with invalid tenant ID: {$tenantIdOption}");
                return self::FAILURE;
            }

            if ($tenant->status !== 'active') {
                $this->error("Tenant '{$tenant->name}' ({$tenant->id}) is not active.");
                Log::error("Replenishment scheduler skipped because tenant status is inactive: {$tenant->id}");
                return self::FAILURE;
            }

            if (!$tenant->hasFeature('procurement.advanced')) {
                $this->error("Tenant '{$tenant->name}' does not have the 'procurement.advanced' feature entitlement enabled.");
                Log::error("Replenishment scheduler skipped because tenant lacks feature entitlement: {$tenant->id}");
                return self::FAILURE;
            }

            $this->info("Starting replenishment draft generation for single tenant: {$tenant->name} ({$tenant->id})");
            $this->processTenant($tenant);
        } else {
            $this->info("Starting bulk replenishment draft generation for all active, entitled tenants.");

            // Use cursor/LazyCollection to optimize memory usage across bulk datasets
            $tenants = Tenant::where('status', 'active')->cursor();

            $processedCount = 0;
            $skippedCount = 0;

            foreach ($tenants as $tenant) {
                if (!$tenant->hasFeature('procurement.advanced')) {
                    $skippedCount++;
                    continue;
                }

                $this->info("Processing tenant: {$tenant->name} ({$tenant->id})");
                $this->processTenant($tenant);
                $processedCount++;
            }

            $this->info("Replenishment scheduler bulk execution finished. Processed: {$processedCount}, Skipped: {$skippedCount}");
        }

        return self::SUCCESS;
    }

    /**
     * Process replenishment and draft PO generation for a single tenant under strict safety isolation.
     *
     * @param Tenant $tenant
     * @return void
     */
    protected function processTenant(Tenant $tenant): void
    {
        // Enforce per-tenant isolation within database transactions
        DB::beginTransaction();

        try {
            // Establish the active tenant sandbox context
            app(TenantContext::class)->setTenant($tenant);

            // Fetch active branches belonging to this tenant
            $branches = Branch::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->get();

            if ($branches->isEmpty()) {
                $this->line(" - No active branches found. Skipping.");
                DB::commit();
                return;
            }

            // Fetch an active user for this tenant to act as PO creator (required column constraint)
            $creator = User::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->orderBy('created_at')
                ->first();

            if (!$creator) {
                throw new \RuntimeException("No active user found for tenant '{$tenant->name}' ({$tenant->id}) to assign as PO creator.");
            }

            $generator = app(DraftPurchaseOrderGenerator::class);
            $generatedCount = 0;

            foreach ($branches as $branch) {
                $pos = $generator->generateForBranch($branch, $creator);
                $generatedCount += count($pos);
            }

            // Commit atomic changes for this tenant
            DB::commit();

            $successMsg = "Successfully completed replenishment generation for tenant '{$tenant->name}' ({$tenant->id}). Generated {$generatedCount} draft PO(s).";
            $this->info(" - " . $successMsg);
            Log::info($successMsg);

            // Log activity to database audit logs since tenant context is valid and active
            app(AuditLogger::class)->log(
                action: 'auto_replenishment_scheduler_completed',
                remarks: "Automated replenishment scheduler finished for tenant '{$tenant->name}'. Generated {$generatedCount} draft POs."
            );

        } catch (\Throwable $e) {
            // If any execution error occurs, roll back this tenant's transaction completely
            DB::rollBack();

            $errorMsg = "Failed to run replenishment for tenant '{$tenant->name}' ({$tenant->id}): " . $e->getMessage();
            $this->error(" - " . $errorMsg);
            Log::error($errorMsg, ['exception' => $e]);

            // Attempt to write a failure log to the tenant's audit trail if context remains active
            if (app(TenantContext::class)->hasTenant()) {
                try {
                    app(AuditLogger::class)->log(
                        action: 'auto_replenishment_scheduler_failed',
                        remarks: "Replenishment scheduler failed: " . $e->getMessage()
                    );
                } catch (\Throwable $auditError) {
                    Log::error("Failed to write to database audit log: " . $auditError->getMessage());
                }
            }
        } finally {
            // Guarantee that tenant context is cleared to prevent leakage into subsequent loops or execution threads
            app(TenantContext::class)->clear();
        }
    }
}
