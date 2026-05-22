<?php

namespace App\Console\Commands\Rbac;

use App\Models\Tenant;
use App\Services\RbacSeeder;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rbac:sync-permissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync RBAC permissions across all tenants based on RbacSeeder templates';

    /**
     * Execute the console command.
     */
    public function handle(RbacSeeder $seeder)
    {
        $tenants = Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return;
        }

        $this->info("Syncing permissions for {$tenants->count()} tenant(s)...");

        foreach ($tenants as $tenant) {
            $this->line("Processing tenant: {$tenant->name} ({$tenant->id})");
            $seeder->seedForTenant($tenant);
        }

        $this->info('RBAC synchronization complete.');
    }
}
