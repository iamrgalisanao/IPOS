<?php

use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Route;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Setup temporary test routes protected by our middleware alias
    Route::get('/_test/feature-gated', function () {
        return response('Success', 200);
    })->middleware(['tenant', 'subscription.feature:quickbooks.sync']);

    Route::get('/_test/api/feature-gated', function () {
        return response()->json(['status' => 'success']);
    })->middleware(['tenant', 'subscription.feature:quickbooks.sync']);
});

test('it blocks web requests with 403 when active tenant lacks feature entitlement', function () {
    // Create a basic plan tenant
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => ['plan' => 'basic']
    ]);

    // Request the route (IdentifyTenantContext expects X-Tenant-ID header)
    $response = $this->withHeader('X-Tenant-ID', $tenant->id)
        ->get('/_test/feature-gated');

    $response->assertStatus(403);
    $response->assertSee('This feature requires a premium subscription upgrade.');
});

test('it permits web requests when active tenant is entitled to feature', function () {
    // Create an enterprise plan tenant (entitled to quickbooks.sync)
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => ['plan' => 'enterprise']
    ]);

    $response = $this->withHeader('X-Tenant-ID', $tenant->id)
        ->get('/_test/feature-gated');

    $response->assertStatus(200);
    $response->assertSee('Success');
});

test('it blocks api requests returning standardized JSON with TSMS_SUB_001 code', function () {
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => ['plan' => 'basic']
    ]);

    $response = $this->withHeader('X-Tenant-ID', $tenant->id)
        ->withHeader('Accept', 'application/json')
        ->get('/_test/api/feature-gated');

    $response->assertStatus(403);
    $response->assertJson([
        'status' => 'error',
        'code' => 'TSMS_SUB_001',
        'message' => 'This feature requires a premium subscription upgrade.'
    ]);
});

test('it permits api requests when active tenant is entitled to feature', function () {
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => ['plan' => 'enterprise']
    ]);

    $response = $this->withHeader('X-Tenant-ID', $tenant->id)
        ->withHeader('Accept', 'application/json')
        ->get('/_test/api/feature-gated');

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'success'
    ]);
});

test('it fails closed when tenant context is missing', function () {
    // Intentionally omit X-Tenant-ID header (IdentifyTenantContext itself blocks it, but this tests our own gate fallback)
    $tenantContext = app(TenantContext::class);
    $tenantContext->clear();

    $response = $this->withHeader('Accept', 'application/json')
        ->get('/_test/api/feature-gated');

    // The IdentifyTenantContext middleware itself aborts with 403 context missing
    $response->assertStatus(403);
});
