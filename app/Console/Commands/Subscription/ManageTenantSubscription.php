<?php

namespace App\Console\Commands\Subscription;

use App\Models\Tenant;
use Illuminate\Console\Command;

class ManageTenantSubscription extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:subscription 
                            {tenant_id : The UUID of the target tenant} 
                            {--plan= : Set the active plan (basic, professional, enterprise)} 
                            {--feature=* : Enable specific features (e.g. quickbooks.sync)} 
                            {--disable-feature=* : Disable specific features} 
                            {--limit=* : Overrides numeric limits (e.g. max_branches=10)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display or update the subscription plan and custom overrides for a tenant';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantId = $this->argument('tenant_id');
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            $this->error("Tenant with ID [{$tenantId}] not found.");
            return 1;
        }

        $metadata = $tenant->subscription_metadata ?? [];

        // Check if we are performing an update
        $updating = false;

        if ($plan = $this->option('plan')) {
            $plan = strtolower($plan);
            if (!in_array($plan, ['basic', 'professional', 'enterprise'])) {
                $this->error("Invalid plan tier [{$plan}]. Allowed: basic, professional, enterprise");
                return 1;
            }
            $metadata['plan'] = $plan;
            $updating = true;
        }

        // Handle enabling features
        if ($features = $this->option('feature')) {
            foreach ($features as $feature) {
                $metadata['features'][$feature] = true;
            }
            $updating = true;
        }

        // Handle disabling features
        if ($disabled = $this->option('disable-feature')) {
            foreach ($disabled as $feature) {
                $metadata['features'][$feature] = false;
            }
            $updating = true;
        }

        // Handle limit overrides
        if ($limits = $this->option('limit')) {
            foreach ($limits as $limitPair) {
                if (str_contains($limitPair, '=')) {
                    [$key, $val] = explode('=', $limitPair, 2);
                    $metadata['limits'][$key] = (int) $val;
                    $updating = true;
                } else {
                    $this->warn("Skipping invalid limit pair [{$limitPair}]. Must be format key=value (e.g., max_branches=10).");
                }
            }
        }

        if ($updating) {
            $tenant->subscription_metadata = $metadata;
            $tenant->save();
            $this->info("Subscription metadata updated successfully for tenant [{$tenant->name}].");
            $this->newLine();
        }

        // Present gorgeous status overview
        $currentPlan = $metadata['plan'] ?? config('subscriptions.default_tier', 'basic');
        $tierConfig = config("subscriptions.tiers.{$currentPlan}") ?? config('subscriptions.tiers.' . config('subscriptions.default_tier', 'basic'));
        
        $this->info("=== Tenant Subscription Profile ===");
        $this->line("Tenant Name:    {$tenant->name}");
        $this->line("Tenant UUID:    {$tenant->id}");
        $this->line("Active Plan:    " . strtoupper($currentPlan));
        $this->newLine();

        $this->comment("--- Evaluated Feature Entitlements ---");
        $allFeatures = ['quickbooks.sync', 'layout.custom', 'reports.advanced'];
        $featureRows = [];
        foreach ($allFeatures as $feat) {
            $isOverridden = isset($metadata['features'][$feat]) ? 'YES (Override)' : 'NO (Tier Default)';
            $hasAccess = $tenant->hasFeature($feat) ? 'ENABLED' : 'DISABLED';
            $featureRows[] = [$feat, $hasAccess, $isOverridden];
        }
        $this->table(['Feature Flag', 'Status', 'Overridden?'], $featureRows);

        $this->newLine();
        $this->comment("--- Evaluated Resource Limits ---");
        $allLimits = ['max_branches', 'max_users'];
        $limitRows = [];
        foreach ($allLimits as $lim) {
            $isOverridden = isset($metadata['limits'][$lim]) ? 'YES (Override)' : 'NO (Tier Default)';
            
            $tierConfig = config("subscriptions.tiers.{$currentPlan}") ?? config('subscriptions.tiers.' . config('subscriptions.default_tier', 'basic'));
            $allowed = isset($metadata['limits'][$lim]) ? (int) $metadata['limits'][$lim] : (int) ($tierConfig['limits'][$lim] ?? 0);
            
            $limitRows[] = [$lim, $allowed, $isOverridden];
        }
        $this->table(['Resource Limit', 'Allowed Threshold', 'Overridden?'], $limitRows);

        return 0;
    }
}
