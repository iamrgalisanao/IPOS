<?php

use App\Jobs\ProcessAccountingOutboxJob;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('it blocks outbox job and marks record as failed when tenant has basic subscription', function () {
    // 1. Create a basic plan tenant
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => ['plan' => 'basic']
    ]);

    // Set TenantContext for model creation safety
    app(TenantContext::class)->setTenant($tenant);

    // 2. Create a branch and a pending outbox record
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $outbox = AccountingOutbox::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'event_type' => 'sale_paid',
        'source_type' => 'sale',
        'source_id' => \Illuminate\Support\Str::uuid(),
        'payload' => [
            'sale_number' => 'POS-TEST',
            'total' => '100.00'
        ],
        'sync_status' => 'pending',
        'attempt_count' => 0
    ]);

    // 3. Spy on log warning
    Log::shouldReceive('shareContext')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('withoutContext')->andReturnNull();
    Log::shouldReceive('warning')
        ->once()
        ->with('accounting.outbox_job.blocked_by_subscription', \Mockery::any());

    // 4. Dispatch the job synchronously (the job handles clearing/setting context itself)
    $job = new ProcessAccountingOutboxJob($outbox->id);
    dispatch_sync($job);

    // 5. Assert the record is marked as failed with subscription error
    $outbox->refresh();
    expect($outbox->sync_status)->toBe('failed');
    expect($outbox->sync_error)->toBe('Tenant does not have subscription to quickbooks.sync.');
    expect($outbox->sync_error_category)->toBe('subscription');
});

test('it passes the subscription gating check when tenant has enterprise subscription', function () {
    // 1. Create an enterprise plan tenant
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => ['plan' => 'enterprise']
    ]);

    // Set TenantContext for model creation safety
    app(TenantContext::class)->setTenant($tenant);

    // 2. Create a branch and a pending outbox record
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $outbox = AccountingOutbox::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'event_type' => 'sale_paid',
        'source_type' => 'sale',
        'source_id' => \Illuminate\Support\Str::uuid(),
        'payload' => [
            'sale_number' => 'POS-TEST',
            'total' => '100.00'
        ],
        'sync_status' => 'pending',
        'attempt_count' => 0
    ]);

    // We expect the job to proceed PAST the subscription check and eventually fail because of missing QuickBooks connection or mappings, NOT subscription blocked.
    $job = new ProcessAccountingOutboxJob($outbox->id);
    
    try {
        dispatch_sync($job);
    } catch (\Throwable $e) {
        // Catch mapping or connection exceptions as they mean it passed the subscription gate!
    }

    $outbox->refresh();
    expect($outbox->sync_status)->toBe('failed');
    // Ensure it failed on QuickBooks connection, NOT subscription gating!
    expect($outbox->sync_error)->not->toContain('subscription');
    expect($outbox->sync_error_category)->not->toBe('subscription');
});
