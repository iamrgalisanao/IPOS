<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Run chunked updates to safely update existing active tenants to grandfathered professional subscriptions
        Tenant::chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                $metadata = $tenant->subscription_metadata ?? [];
                if (empty($metadata) || !isset($metadata['plan'])) {
                    $tenant->subscription_metadata = [
                        'plan' => 'professional',
                        'grandfathered_at' => now()->toIso8601String(),
                    ];
                    $tenant->save();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Tenant::chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                $metadata = $tenant->subscription_metadata ?? [];
                if (isset($metadata['grandfathered_at'])) {
                    $tenant->subscription_metadata = null;
                    $tenant->save();
                }
            }
        });
    }
};
