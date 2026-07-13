<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosLayout;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use App\Services\POS\TerminalLayoutResolver;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Shared helpers (accessible via $this in Pest closure tests)
// ─────────────────────────────────────────────────────────────────────────────

function makeTenantBranchProfile(): array
{
    $tenant  = Tenant::factory()->create(['status' => 'active']);
    app(TenantContext::class)->setTenant($tenant);
    app(RbacSeeder::class)->seedForTenant($tenant);
    app(TenantContext::class)->setTenant($tenant);

    $branch  = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $profile = SalesMachineProfile::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'profile_code' => 'TERM-01',
        'machine_identification_number' => 'MIN-TERM-01',
        'machine_serial_number' => 'SER-TERM-01',
        'software_license_number' => 'LIC-TERM-01',
        'permit_to_use_number' => 'PTU-TERM-01',
        'authority_to_generate_control_number' => 'ATG-TERM-01',
        'supplier_name' => 'Supplier',
        'supplier_tin' => '123-456-789-000',
        'supplier_branch_code' => '00001',
        'supplier_accreditation_number' => 'ACC-TERM-01',
        'status' => SalesMachineProfile::STATUS_ACTIVE,
        'terminal_identifier' => 'TERM-01',
    ]);
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole(Role::where('tenant_id', $tenant->id)->where('name', 'Owner/Admin')->firstOrFail());

    return compact('tenant', 'branch', 'profile', 'admin');
}

function publishLayoutToBranch(PosLayout $layout, Branch $branch, Tenant $tenant): void
{
    $layout->update(['status' => PosLayout::STATUS_PUBLISHED]);

    DB::table('branch_pos_layout')
        ->where('branch_id', $branch->id)
        ->update(['is_active' => false]);

    DB::table('branch_pos_layout')->insert([
        'id'            => Str::uuid(),
        'tenant_id'     => $tenant->id,
        'branch_id'     => $branch->id,
        'pos_layout_id' => $layout->id,
        'is_active'     => true,
        'active_from'   => now(),
        'published_at'  => now(),
        'published_by'  => null,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
}

function attachLayoutToBranch(PosLayout $layout, Branch $branch, Tenant $tenant): void
{
    DB::table('branch_pos_layout')->insert([
        'id'            => Str::uuid(),
        'tenant_id'     => $tenant->id,
        'branch_id'     => $branch->id,
        'pos_layout_id' => $layout->id,
        'is_active'     => true,
        'active_from'   => now(),
        'published_at'  => now(),
        'published_by'  => null,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Layout Resolution
// ─────────────────────────────────────────────────────────────────────────────

it('resolves branch layout when terminal has no override', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile] = makeTenantBranchProfile();

    $layout = PosLayout::factory()->create(['tenant_id' => $tenant->id]);
    publishLayoutToBranch($layout, $branch, $tenant);

    $resolver = app(TerminalLayoutResolver::class);
    $resolved = $resolver->resolveForProfile($profile);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($layout->id)
        ->and($resolver->getResolutionSource($profile))->toBe(TerminalLayoutResolver::SOURCE_BRANCH_DEFAULT);
});

it('uses terminal override layout when pos_layout_id is set and published', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile] = makeTenantBranchProfile();

    $branchLayout   = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    $overrideLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($branchLayout, $branch, $tenant);

    $profile->update(['pos_layout_id' => $overrideLayout->id]);

    $resolver = app(TerminalLayoutResolver::class);
    $resolved = $resolver->resolveForProfile($profile);

    expect($resolved->id)->toBe($overrideLayout->id)
        ->and($resolver->getResolutionSource($profile))->toBe(TerminalLayoutResolver::SOURCE_TERMINAL_OVERRIDE);
});

it('falls back to branch layout when override layout is draft', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile] = makeTenantBranchProfile();

    $branchLayout  = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    $draftOverride = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_DRAFT]);
    publishLayoutToBranch($branchLayout, $branch, $tenant);

    $profile->update(['pos_layout_id' => $draftOverride->id]);

    $resolver = app(TerminalLayoutResolver::class);
    $resolved = $resolver->resolveForProfile($profile);

    expect($resolved->id)->toBe($branchLayout->id)
        ->and($resolver->getResolutionSource($profile))->toBe(TerminalLayoutResolver::SOURCE_BRANCH_DEFAULT);
});

it('null_on_delete reverts profile to null pos_layout_id when override is deleted', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile] = makeTenantBranchProfile();

    $branchLayout   = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    $overrideLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($branchLayout, $branch, $tenant);

    $profile->update(['pos_layout_id' => $overrideLayout->id]);
    $overrideLayout->delete();
    $profile->refresh();

    expect($profile->pos_layout_id)->toBeNull();

    $resolved = app(TerminalLayoutResolver::class)->resolveForProfile($profile);
    expect($resolved->id)->toBe($branchLayout->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Layout Hash Correctness
// ─────────────────────────────────────────────────────────────────────────────

it('layout hash differs between branch layout and terminal override layout', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile] = makeTenantBranchProfile();

    $branchLayout   = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    $overrideLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($branchLayout, $branch, $tenant);

    $bootstrap = app(CacheBootstrapService::class);
    $resolver  = app(TerminalLayoutResolver::class);

    $branchHash  = $bootstrap->calculateLayoutVersionHash($tenant->id, $branch->id);
    $termHash    = $resolver->resolveHashForProfile($profile, $bootstrap);
    expect($branchHash)->toBe($termHash); // No override yet — same hash

    $profile->update(['pos_layout_id' => $overrideLayout->id]);
    $termHashAfter = $resolver->resolveHashForProfile($profile, $bootstrap);

    expect($termHashAfter)->not->toBe($branchHash);
});

it('branch layout update does not change hash for terminal with independent override', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile] = makeTenantBranchProfile();

    $branchLayout   = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    $overrideLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($branchLayout, $branch, $tenant);
    $profile->update(['pos_layout_id' => $overrideLayout->id]);

    $bootstrap  = app(CacheBootstrapService::class);
    $resolver   = app(TerminalLayoutResolver::class);
    $hashBefore = $resolver->resolveHashForProfile($profile, $bootstrap);

    // Publish a new layout to the branch
    $newBranchLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    DB::table('branch_pos_layout')->where('branch_id', $branch->id)->update(['is_active' => false]);
    publishLayoutToBranch($newBranchLayout, $branch, $tenant);

    $hashAfter = $resolver->resolveHashForProfile($profile, $bootstrap);
    expect($hashAfter)->toBe($hashBefore); // Override is independent; hash unchanged
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Heartbeat Drift Response
// ─────────────────────────────────────────────────────────────────────────────

it('heartbeat reports layout_drift true when client hash is stale', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $layout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($layout, $branch, $tenant);

    $this->actingAs($admin)
        ->withHeaders([
            'X-Tenant-ID'   => $tenant->id,
            'X-Branch-ID'   => $branch->id,
            'X-Terminal-ID' => $profile->id,
        ])
        ->postJson('/api/pos/heartbeat', [
            'app_version'      => '1.0.0',
            'connection_state' => 'online',
            'queue_count'      => 0,
            'reported_at'      => now()->toIso8601String(),
            'config_snapshot'  => ['layout_version_hash' => 'stale-hash-that-does-not-match'],
        ])
        ->assertOk()
        ->assertJsonFragment(['layout_drift' => true])
        ->assertJsonPath('config_drift.drifted_components.0', 'layout');
});

it('heartbeat reports layout_drift false when client hash matches server', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $layout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($layout, $branch, $tenant);

    $hash = app(TerminalLayoutResolver::class)->resolveHashForProfile($profile, app(CacheBootstrapService::class));

    $this->actingAs($admin)
        ->withHeaders([
            'X-Tenant-ID'   => $tenant->id,
            'X-Branch-ID'   => $branch->id,
            'X-Terminal-ID' => $profile->id,
        ])
        ->postJson('/api/pos/heartbeat', [
            'app_version'      => '1.0.0',
            'connection_state' => 'online',
            'queue_count'      => 0,
            'reported_at'      => now()->toIso8601String(),
            'config_snapshot'  => ['layout_version_hash' => $hash],
        ])
        ->assertOk()
        ->assertJsonFragment(['layout_drift' => false]);
});

it('heartbeat compares against terminal override hash not branch hash', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $branchLayout   = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    $overrideLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($branchLayout, $branch, $tenant);
    $profile->update(['pos_layout_id' => $overrideLayout->id]);

    $bootstrap    = app(CacheBootstrapService::class);
    $resolver     = app(TerminalLayoutResolver::class);
    $overrideHash = $resolver->resolveHashForProfile($profile, $bootstrap);
    $branchHash   = $bootstrap->calculateLayoutVersionHash($tenant->id, $branch->id);

    // Sending the branch hash for a terminal that has an override → drift
    $this->actingAs($admin)
        ->withHeaders([
            'X-Tenant-ID'   => $tenant->id,
            'X-Branch-ID'   => $branch->id,
            'X-Terminal-ID' => $profile->id,
        ])
        ->postJson('/api/pos/heartbeat', [
            'app_version'      => '1.0.0',
            'connection_state' => 'online',
            'queue_count'      => 0,
            'reported_at'      => now()->toIso8601String(),
            'config_snapshot'  => ['layout_version_hash' => $branchHash],
        ])
        ->assertOk()
        ->assertJsonFragment(['layout_drift' => true]);

    // Sending the correct override hash → no drift
    $this->actingAs($admin)
        ->withHeaders([
            'X-Tenant-ID'   => $tenant->id,
            'X-Branch-ID'   => $branch->id,
            'X-Terminal-ID' => $profile->id,
        ])
        ->postJson('/api/pos/heartbeat', [
            'app_version'      => '1.0.0',
            'connection_state' => 'online',
            'queue_count'      => 0,
            'reported_at'      => now()->toIso8601String(),
            'config_snapshot'  => ['layout_version_hash' => $overrideHash],
        ])
        ->assertOk()
        ->assertJsonFragment(['layout_drift' => false]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Admin Assignment Controller
// ─────────────────────────────────────────────────────────────────────────────

it('admin can assign a published layout to a terminal', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $layout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($layout, $branch, $tenant);

    $this->actingAs($admin)
        ->putJson("/admin/sales-machine-profiles/{$profile->id}/layout-assignment", [
            'pos_layout_id' => $layout->id,
        ])
        ->assertOk()
        ->assertJsonFragment(['success' => true]);

    expect($profile->fresh()->pos_layout_id)->toBe($layout->id);

    $this->assertDatabaseHas('audit_logs', [
        'action'       => 'terminal_layout_override_updated',
        'auditable_id' => $profile->id,
    ]);
});

it('admin can remove a layout override and revert to branch default', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $layout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($layout, $branch, $tenant);
    $profile->update(['pos_layout_id' => $layout->id]);

    $this->actingAs($admin)
        ->deleteJson("/admin/sales-machine-profiles/{$profile->id}/layout-assignment")
        ->assertOk()
        ->assertJsonFragment(['success' => true]);

    expect($profile->fresh()->pos_layout_id)->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action'       => 'terminal_layout_override_removed',
        'auditable_id' => $profile->id,
    ]);
});

it('blocks assigning a draft layout to a terminal', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $draftLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_DRAFT]);
    attachLayoutToBranch($draftLayout, $branch, $tenant);

    $this->actingAs($admin)
        ->putJson("/admin/sales-machine-profiles/{$profile->id}/layout-assignment", [
            'pos_layout_id' => $draftLayout->id,
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'LAYOUT_NOT_PUBLISHED']);
});

it('blocks assigning a layout from a different tenant', function () {
    ['tenant' => $tenant, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $otherTenant   = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($otherTenant);
    $foreignLayout = PosLayout::factory()->create([
        'tenant_id' => $otherTenant->id,
        'status'    => PosLayout::STATUS_PUBLISHED,
    ]);
    app(TenantContext::class)->setTenant($tenant);

    $this->actingAs($admin)
        ->putJson("/admin/sales-machine-profiles/{$profile->id}/layout-assignment", [
            'pos_layout_id' => $foreignLayout->id,
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'CROSS_TENANT_ASSIGNMENT']);
});

it('blocks assigning a layout not published to the terminal branch', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $otherLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    // Publish to the OTHER branch only
    publishLayoutToBranch($otherLayout, $otherBranch, $tenant);

    $this->actingAs($admin)
        ->putJson("/admin/sales-machine-profiles/{$profile->id}/layout-assignment", [
            'pos_layout_id' => $otherLayout->id,
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'CROSS_BRANCH_ASSIGNMENT']);
});

it('returns 403 when user lacks pos-layouts.manage permission', function () {
    ['tenant' => $tenant, 'profile' => $profile] = makeTenantBranchProfile();

    $noPermUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $layout     = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);

    $this->actingAs($noPermUser)
        ->putJson("/admin/sales-machine-profiles/{$profile->id}/layout-assignment", [
            'pos_layout_id' => $layout->id,
        ])
        ->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. POS Layout Endpoint
// ─────────────────────────────────────────────────────────────────────────────

it('pos layout endpoint returns layout_resolution source as branch_default', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $layout = PosLayout::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => PosLayout::STATUS_PUBLISHED,
        'schema'    => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
    ]);
    publishLayoutToBranch($layout, $branch, $tenant);

    $this->actingAs($admin)
        ->withHeaders([
            'X-Tenant-ID'   => $tenant->id,
            'X-Branch-ID'   => $branch->id,
            'X-Terminal-ID' => $profile->id,
        ])
        ->getJson('/pos/layout')
        ->assertOk()
        ->assertJsonFragment(['fallback' => false])
        ->assertJsonPath('layout_resolution.source', TerminalLayoutResolver::SOURCE_BRANCH_DEFAULT)
        ->assertJsonStructure(['layout_version_hash', 'layout_resolution' => ['source', 'pos_layout_id', 'layout_name']]);
});

it('pos layout endpoint uses terminal override layout when set', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();

    $branchLayout = PosLayout::factory()->create([
        'tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED,
        'schema'    => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
    ]);
    $overrideLayout = PosLayout::factory()->create([
        'tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED,
        'schema'    => ['grid' => ['rows' => 3, 'columns' => 3], 'tiles' => []],
    ]);
    publishLayoutToBranch($branchLayout, $branch, $tenant);
    $profile->update(['pos_layout_id' => $overrideLayout->id]);

    $this->actingAs($admin)
        ->withHeaders([
            'X-Tenant-ID'   => $tenant->id,
            'X-Branch-ID'   => $branch->id,
            'X-Terminal-ID' => $profile->id,
        ])
        ->getJson('/pos/layout')
        ->assertOk()
        ->assertJsonPath('layout.id', $overrideLayout->id)
        ->assertJsonPath('layout_resolution.source', TerminalLayoutResolver::SOURCE_TERMINAL_OVERRIDE);
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Profile Update Layout Assignment
// ─────────────────────────────────────────────────────────────────────────────

it('normalizes empty string pos_layout_id to null on profile update', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();
    $layout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($layout, $branch, $tenant);
    $profile->update(['pos_layout_id' => $layout->id]);

    $this->actingAs($admin)
        ->put("/admin/sales-machine-profiles/{$profile->id}", [
            'pos_layout_id' => '',
            'offline_sales_enabled' => true,
        ])
        ->assertRedirect();

    expect($profile->fresh()->pos_layout_id)->toBeNull();
});

it('does not require pos-layouts.manage permission when layout is not changed', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile] = makeTenantBranchProfile();
    
    // Create user with branch association
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $user->assignToBranch($branch);

    // Create a custom role with only manage_offline_sales_settings permission
    $role = Role::create([
        'tenant_id' => $tenant->id,
        'name' => 'Custom Offline Sales Manager',
        'description' => 'Test Role without layout manage permission',
    ]);
    $permission = Permission::where('name', 'manage_offline_sales_settings')->firstOrFail();
    $role->permissions()->sync([$permission->id]);
    $user->assignRole($role);

    // Update unrelated fields
    $this->actingAs($user)
        ->put("/admin/sales-machine-profiles/{$profile->id}", [
            'offline_sales_enabled' => false,
            'pos_layout_id' => $profile->pos_layout_id, // unchanged
        ])
        ->assertRedirect();
});

it('removes layout override and reverts to branch default via profile update', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();
    $layout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($layout, $branch, $tenant);
    $profile->update(['pos_layout_id' => $layout->id]);

    $this->actingAs($admin)
        ->put("/admin/sales-machine-profiles/{$profile->id}", [
            'pos_layout_id' => null,
        ])
        ->assertRedirect();

    expect($profile->fresh()->pos_layout_id)->toBeNull();
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'terminal_layout_override_removed',
        'auditable_id' => $profile->id,
    ]);
});

it('assigns layout override via profile update and logs it', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();
    $layout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($layout, $branch, $tenant);

    $this->actingAs($admin)
        ->put("/admin/sales-machine-profiles/{$profile->id}", [
            'pos_layout_id' => $layout->id,
        ])
        ->assertRedirect();

    expect($profile->fresh()->pos_layout_id)->toBe($layout->id);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'terminal_layout_override_updated',
        'auditable_id' => $profile->id,
    ]);
});

it('profile update layout assignment change alters layout hash and triggers heartbeat drift', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'profile' => $profile, 'admin' => $admin] = makeTenantBranchProfile();
    $branchLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    $overrideLayout = PosLayout::factory()->create(['tenant_id' => $tenant->id, 'status' => PosLayout::STATUS_PUBLISHED]);
    publishLayoutToBranch($branchLayout, $branch, $tenant);
    publishLayoutToBranch($overrideLayout, $branch, $tenant);

    $bootstrap = app(CacheBootstrapService::class);
    $resolver = app(TerminalLayoutResolver::class);

    $oldHash = $resolver->resolveHashForProfile($profile, $bootstrap);

    // Update layout override
    $this->actingAs($admin)
        ->put("/admin/sales-machine-profiles/{$profile->id}", [
            'pos_layout_id' => $overrideLayout->id,
        ])
        ->assertRedirect();

    $newHash = $resolver->resolveHashForProfile($profile->fresh(), $bootstrap);
    expect($newHash)->not->toBe($oldHash);

    // Heartbeat with old layout hash should now drift
    $this->actingAs($admin)
        ->withHeaders([
            'X-Tenant-ID'   => $tenant->id,
            'X-Branch-ID'   => $branch->id,
            'X-Terminal-ID' => $profile->id,
        ])
        ->postJson('/api/pos/heartbeat', [
            'app_version'      => '1.0.0',
            'connection_state' => 'online',
            'queue_count'      => 0,
            'reported_at'      => now()->toIso8601String(),
            'config_snapshot'  => ['layout_version_hash' => $oldHash],
        ])
        ->assertOk()
        ->assertJsonFragment(['layout_drift' => true]);
});
