<?php

use App\Models\Branch;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('it allows branch creation up to subscription tier limit', function () {
    // 1. Create a tenant with basic plan (limit = 1 branch)
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => ['plan' => 'basic']
    ]);

    // Set TenantContext for model creation safety
    app(TenantContext::class)->setTenant($tenant);

    // 2. Create the first branch (should succeed)
    $branch1 = Branch::create([
        'tenant_id' => $tenant->id,
        'name' => 'Main Branch',
        'branch_code' => 'B001',
        'status' => 'active'
    ]);

    expect($branch1)->toBeInstanceOf(Branch::class);

    // 3. Attempt to create a second branch (should fail due to limit)
    expect(fn () => Branch::create([
        'tenant_id' => $tenant->id,
        'name' => 'Second Branch',
        'branch_code' => 'B002',
        'status' => 'active'
    ]))->toThrow(ValidationException::class);
});

test('it preserves existing branches upon downgrade but blocks new branch creations', function () {
    // 1. Create a professional tenant (limit = 5 branches)
    $tenant = Tenant::factory()->create([
        'subscription_metadata' => ['plan' => 'professional']
    ]);

    // Set TenantContext for model creation safety
    app(TenantContext::class)->setTenant($tenant);

    // 2. Create 3 branches
    for ($i = 1; $i <= 3; $i++) {
        Branch::create([
            'tenant_id' => $tenant->id,
            'name' => "Branch {$i}",
            'branch_code' => "B00{$i}",
            'status' => 'active'
        ]);
    }

    expect($tenant->branches()->count())->toBe(3);

    // 3. Downgrade tenant to basic plan (limit = 1 branch)
    $tenant->update([
        'subscription_metadata' => ['plan' => 'basic']
    ]);

    // 4. Assert that existing branches are fully preserved and readable (read-only grace)
    $tenant->refresh();
    expect($tenant->branches()->count())->toBe(3);

    // 5. Assert that attempting to create a new branch is strictly blocked
    expect(fn () => Branch::create([
        'tenant_id' => $tenant->id,
        'name' => 'New Banned Branch',
        'branch_code' => 'B004',
        'status' => 'active'
    ]))->toThrow(ValidationException::class);
});
