<?php

use App\Models\Tenant;

uses(Tests\TestCase::class);


test('it falls back to basic tier when subscription_metadata is null or empty', function () {
    $tenant = new Tenant();
    $tenant->subscription_metadata = null;

    // Default basic tier features from config should be resolved
    expect($tenant->hasFeature('sales.pos'))->toBeTrue();
    expect($tenant->hasFeature('quickbooks.sync'))->toBeFalse();
    expect($tenant->hasFeature('procurement.advanced'))->toBeFalse();

    // Default basic limits
    expect($tenant->withinLimit('max_branches', 0))->toBeTrue();  // 0 < 1 is true
    expect($tenant->withinLimit('max_branches', 1))->toBeFalse(); // 1 < 1 is false
});

test('it falls back to basic tier when active plan is unrecognized', function () {
    $tenant = new Tenant();
    $tenant->subscription_metadata = ['plan' => 'unknown_tier'];

    expect($tenant->hasFeature('sales.pos'))->toBeTrue();
    expect($tenant->hasFeature('quickbooks.sync'))->toBeFalse();
    expect($tenant->withinLimit('max_branches', 0))->toBeTrue();
    expect($tenant->withinLimit('max_branches', 1))->toBeFalse();
});

test('it correctly resolves professional tier features and limits', function () {
    $tenant = new Tenant();
    $tenant->subscription_metadata = ['plan' => 'professional'];

    expect($tenant->hasFeature('sales.pos'))->toBeTrue();
    expect($tenant->hasFeature('layout.custom'))->toBeTrue();
    expect($tenant->hasFeature('quickbooks.sync'))->toBeFalse();

    // Limits
    expect($tenant->withinLimit('max_branches', 4))->toBeTrue();  // 4 < 5
    expect($tenant->withinLimit('max_branches', 5))->toBeFalse(); // 5 < 5
});

test('it correctly resolves enterprise tier features and limits', function () {
    $tenant = new Tenant();
    $tenant->subscription_metadata = ['plan' => 'enterprise'];

    expect($tenant->hasFeature('sales.pos'))->toBeTrue();
    expect($tenant->hasFeature('quickbooks.sync'))->toBeTrue();
    expect($tenant->hasFeature('procurement.advanced'))->toBeTrue();

    // Limits should be PHP_INT_MAX (virtually unlimited)
    expect($tenant->withinLimit('max_branches', 1000))->toBeTrue();
});

test('it respects tenant-specific feature overrides', function () {
    $tenant = new Tenant();
    
    // basic tier but manually enable QuickBooks and disable standard POS sales
    $tenant->subscription_metadata = [
        'plan' => 'basic',
        'features' => [
            'quickbooks.sync' => true,
            'sales.pos' => false,
        ]
    ];

    expect($tenant->hasFeature('quickbooks.sync'))->toBeTrue();
    expect($tenant->hasFeature('sales.pos'))->toBeFalse();
});

test('it respects tenant-specific limit overrides', function () {
    $tenant = new Tenant();
    
    // professional plan (default max_branches is 5) but manually lower to 2
    $tenant->subscription_metadata = [
        'plan' => 'professional',
        'limits' => [
            'max_branches' => 2,
        ]
    ];

    expect($tenant->withinLimit('max_branches', 1))->toBeTrue();  // 1 < 2
    expect($tenant->withinLimit('max_branches', 2))->toBeFalse(); // 2 < 2
});
