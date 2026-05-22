<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it legacy-grandfatheres existing tenants to professional plan', function () {
    // 1. Create a tenant before running our migration manually
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => null
    ]);

    // 2. Load the migration and run the up() logic
    $migration = require base_path('database/migrations/2026_05_17_150000_migrate_existing_tenants_to_professional.php');
    $migration->up();

    // 3. Assert the tenant is updated successfully with professional plan and timestamp
    $tenant->refresh();
    expect($tenant->subscription_metadata)->toBeArray();
    expect($tenant->subscription_metadata['plan'])->toBe('professional');
    expect($tenant->subscription_metadata)->toHaveKey('grandfathered_at');
});

test('it does not overwrite existing tenants that already have a plan set', function () {
    // 1. Create a tenant with an enterprise plan already set
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => ['plan' => 'enterprise']
    ]);

    // 2. Run the migration
    $migration = require base_path('database/migrations/2026_05_17_150000_migrate_existing_tenants_to_professional.php');
    $migration->up();

    // 3. Assert that it remains enterprise and is not overwritten
    $tenant->refresh();
    expect($tenant->subscription_metadata['plan'])->toBe('enterprise');
    expect($tenant->subscription_metadata)->not->toHaveKey('grandfathered_at');
});
