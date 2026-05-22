<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it shares active tenant subscription details inside auth.tenant.subscription Inertia context', function () {
    // Create a tenant with professional tier and custom overrides
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => [
            'plan' => 'professional',
            'features' => [
                'quickbooks.sync' => true // explicitly override to add quickbooks
            ],
            'limits' => [
                'max_branches' => 10 // override standard 5
            ]
        ]
    ]);

    // Set TenantContext for model creation safety
    app(TenantContext::class)->setTenant($tenant);

    // Create a user for this tenant
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'active'
    ]);

    // Perform an authenticated request
    $response = $this->actingAs($user)
        ->withHeader('X-Tenant-ID', $tenant->id)
        ->get('/'); // hit any web route that boots Inertia

    // Since we are making a standard Inertia request or landing page hit,
    // let's verify that the shared subscription details are injected into shared view context.
    $shared = session()->get('errors') ? [] : $response->original->getData()['page']['props'];

    expect($shared['auth']['tenant']['subscription']['plan'])->toBe('professional');
    expect($shared['auth']['tenant']['subscription']['features'])->toContain('quickbooks.sync');
    expect($shared['auth']['tenant']['subscription']['features'])->toContain('layout.custom');
    expect($shared['auth']['tenant']['subscription']['limits']['max_branches'])->toBe(10);
});
