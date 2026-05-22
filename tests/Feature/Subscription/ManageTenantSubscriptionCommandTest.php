<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('it displays tenant subscription profile correctly', function () {
    $tenant = Tenant::factory()->create([
        'name' => 'Acme Corp',
        'subscription_metadata' => ['plan' => 'professional']
    ]);

    $this->artisan("tenant:subscription {$tenant->id}")
        ->assertExitCode(0)
        ->expectsOutputToContain('Tenant Name:    Acme Corp')
        ->expectsOutputToContain('Active Plan:    PROFESSIONAL');
});

test('it updates plan and features/limits overrides successfully', function () {
    $tenant = Tenant::factory()->create([
        'name' => 'Acme Corp',
        'subscription_metadata' => ['plan' => 'basic']
    ]);

    // Update plan to enterprise, enable quickbooks.sync, and set custom branch limit to 25
    $this->artisan("tenant:subscription {$tenant->id} --plan=enterprise --feature=quickbooks.sync --limit=max_branches=25")
        ->assertExitCode(0)
        ->expectsOutputToContain('Subscription metadata updated successfully for tenant [Acme Corp].')
        ->expectsOutputToContain('Active Plan:    ENTERPRISE');

    $tenant->refresh();
    expect($tenant->subscription_metadata['plan'])->toBe('enterprise');
    expect($tenant->subscription_metadata['features']['quickbooks.sync'])->toBeTrue();
    expect($tenant->subscription_metadata['limits']['max_branches'])->toBe(25);
});
