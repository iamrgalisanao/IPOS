<?php

namespace Tests\Feature\SystemAdmin;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 29.4 — Slice B: Pilot Enablement Controls (mutation tests)
 *
 * Approval gate confirmed:
 *   1. Mutation boundary: tenant/branch/terminal offline settings for selected pilot scope only.
 *   2. Audit trail: every attempt recorded with actor, target, before/after, outcome, reason.
 *   3. Wide-flag protection: tenant-level enable does not auto-activate all branches/terminals.
 *   4. Race-condition mitigation: runs in transaction; rolled back if post-write outcome ≠ ready.
 */
class PilotProvisioningMutationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;
    protected User $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->company = Tenant::factory()->create(['status' => 'active']);
        $this->systemAdmin = User::factory()->platformSupport()->create();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper: build a fully-ready tenant state (all 11 checks pass)
    // ──────────────────────────────────────────────────────────────────────────

    private function createReadyState(): array
    {
        app(TenantContext::class)->setTenant($this->company);

        $this->company->update(['offline_sales_enabled' => true]);

        $branch = Branch::create([
            'tenant_id'              => $this->company->id,
            'name'                   => 'Main Branch',
            'branch_code'            => 'MB-001',
            'status'                 => 'active',
            'offline_sales_enabled'  => true,
        ]);

        $owner = User::factory()->create([
            'tenant_id'  => $this->company->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);

        $profile = SalesMachineProfile::create([
            'tenant_id'                           => $this->company->id,
            'branch_id'                           => $branch->id,
            'profile_code'                        => 'T1-M01',
            'machine_identification_number'       => 'MIN-001',
            'machine_serial_number'               => 'SN-001',
            'permit_to_use_number'                => 'PTU-001',
            'authority_to_generate_control_number'=> 'ATGCN-001',
            'supplier_accreditation_number'       => 'ACC-001',
            'status'                              => 'active',
            'offline_sales_enabled'               => true,
            'offline_sequence_prefix'             => 'OFF-MB',
            'offline_sequence_next_value'         => 1,
            'offline_sequence_status'             => 'active',
        ]);

        $permission = Permission::create([
            'tenant_id'   => $this->company->id,
            'name'        => 'manage_offline_sales_settings',
            'description' => 'Can manage terminal offline sales settings and sequence registry',
        ]);

        $role = Role::create([
            'tenant_id' => $this->company->id,
            'name'      => 'Owner',
        ]);
        $role->permissions()->attach($permission->id);

        app(TenantContext::class)->clear();

        return compact('branch', 'owner', 'profile', 'role', 'permission');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper: build a pending-state with offline flags OFF at all levels
    // (all structural checks pass; offline flags are the only blockers)
    // ──────────────────────────────────────────────────────────────────────────

    private function createPendingState(array $overrides = []): array
    {
        app(TenantContext::class)->setTenant($this->company);

        $this->company->update(['offline_sales_enabled' => false]);

        $branch = Branch::create([
            'tenant_id'             => $this->company->id,
            'name'                  => 'Main Branch',
            'branch_code'           => 'MB-001',
            'status'                => 'active',
            'offline_sales_enabled' => false,
        ]);

        $owner = User::factory()->create([
            'tenant_id'  => $this->company->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);

        $profileDefaults = [
            'tenant_id'                           => $this->company->id,
            'branch_id'                           => $branch->id,
            'profile_code'                        => 'T1-M01',
            'machine_identification_number'       => 'MIN-001',
            'machine_serial_number'               => 'SN-001',
            'permit_to_use_number'                => 'PTU-001',
            'authority_to_generate_control_number'=> 'ATGCN-001',
            'supplier_accreditation_number'       => 'ACC-001',
            'status'                              => 'active',
            'offline_sales_enabled'               => false,
            'offline_sequence_prefix'             => 'OFF-MB',
            'offline_sequence_next_value'         => 1,
            'offline_sequence_status'             => 'active',
        ];

        $profile = SalesMachineProfile::create(array_merge($profileDefaults, $overrides));

        $permission = Permission::create([
            'tenant_id'   => $this->company->id,
            'name'        => 'manage_offline_sales_settings',
            'description' => 'Can manage terminal offline sales settings and sequence registry',
        ]);

        $role = Role::create([
            'tenant_id' => $this->company->id,
            'name'      => 'Owner',
        ]);
        $role->permissions()->attach($permission->id);

        app(TenantContext::class)->clear();

        return compact('branch', 'owner', 'profile', 'role', 'permission');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 1. Enable succeeds when all checks are ready
    // ──────────────────────────────────────────────────────────────────────────

    public function test_enable_succeeds_when_outcome_is_ready(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.enable', $this->company), [
                'branch_id'  => $branch->id,
                'profile_id' => $profile->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('outcome', 'ready')
            ->assertJsonStructure(['success', 'outcome', 'enabled_at', 'checks']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. Enable rejected when compliance fields are missing
    // ──────────────────────────────────────────────────────────────────────────

    public function test_enable_rejected_when_compliance_incomplete(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createPendingState([
            'machine_identification_number' => null,
            'offline_sales_enabled'         => true, // all flags on, but compliance fails
        ]);

        // Re-enable offline at all levels except compliance is incomplete
        app(TenantContext::class)->setTenant($this->company);
        $this->company->update(['offline_sales_enabled' => true]);
        $branch->update(['offline_sales_enabled' => true]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.enable', $this->company), [
                'branch_id'  => $branch->id,
                'profile_id' => $profile->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'not_ready');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. Enable rejected when prefix is missing (even if flags are enabled)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_enable_rejected_when_prefix_missing(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createPendingState([
            'offline_sequence_prefix' => null,
            'offline_sales_enabled'   => true,
        ]);

        app(TenantContext::class)->setTenant($this->company);
        $this->company->update(['offline_sales_enabled' => true]);
        $branch->update(['offline_sales_enabled' => true]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.enable', $this->company), [
                'branch_id'     => $branch->id,
                'profile_id'    => $profile->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'not_ready');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. Enable with enable_tenant=true promotes tenant flag to true
    // ──────────────────────────────────────────────────────────────────────────

    public function test_enable_applies_requested_tenant_flag(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createReadyState();

        // Reset tenant flag off to test that the request re-enables it
        app(TenantContext::class)->setTenant($this->company);
        $this->company->update(['offline_sales_enabled' => false]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.enable', $this->company), [
                'branch_id'     => $branch->id,
                'profile_id'    => $profile->id,
                'enable_tenant' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('outcome', 'ready');

        $this->assertTrue((bool) $this->company->fresh()->offline_sales_enabled);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. Disable succeeds at tenant level
    // ──────────────────────────────────────────────────────────────────────────

    public function test_disable_succeeds_at_tenant_level(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.disable', $this->company), [
                'branch_id'  => $branch->id,
                'profile_id' => $profile->id,
                'level'      => 'tenant',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('level', 'tenant')
            ->assertJsonStructure(['success', 'level', 'disabled_at']);

        $this->assertFalse((bool) $this->company->fresh()->offline_sales_enabled);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 6. Disable succeeds at branch level
    // ──────────────────────────────────────────────────────────────────────────

    public function test_disable_succeeds_at_branch_level(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.disable', $this->company), [
                'branch_id'  => $branch->id,
                'profile_id' => $profile->id,
                'level'      => 'branch',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('level', 'branch');

        $this->assertFalse((bool) $branch->fresh()->offline_sales_enabled);
        // Tenant flag must remain unchanged
        $this->assertTrue((bool) $this->company->fresh()->offline_sales_enabled);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 7. Disable succeeds at terminal level
    // ──────────────────────────────────────────────────────────────────────────

    public function test_disable_succeeds_at_terminal_level(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.disable', $this->company), [
                'branch_id'  => $branch->id,
                'profile_id' => $profile->id,
                'level'      => 'terminal',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('level', 'terminal');

        $this->assertFalse((bool) $profile->fresh()->offline_sales_enabled);
        // Branch and tenant flags must remain unchanged
        $this->assertTrue((bool) $branch->fresh()->offline_sales_enabled);
        $this->assertTrue((bool) $this->company->fresh()->offline_sales_enabled);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 8. Enable is transactional — no DB changes persist if post-write not ready
    // ──────────────────────────────────────────────────────────────────────────

    public function test_enable_rolled_back_if_post_write_outcome_not_ready(): void
    {
        // State: offline flags are off, compliance incomplete — no combination of flag
        // changes alone can make this ready because compliance fields are missing.
        ['branch' => $branch, 'profile' => $profile] = $this->createPendingState([
            'machine_identification_number' => null,
        ]);

        $tenantBefore = (bool) $this->company->fresh()->offline_sales_enabled;
        $branchBefore = (bool) $branch->fresh()->offline_sales_enabled;

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.enable', $this->company), [
                'branch_id'      => $branch->id,
                'profile_id'     => $profile->id,
                'enable_tenant'  => true,
                'enable_branch'  => true,
                'enable_terminal'=> true,
            ]);

        $response->assertStatus(422);

        // Flags must be rolled back to pre-request state
        $this->assertSame($tenantBefore, (bool) $this->company->fresh()->offline_sales_enabled);
        $this->assertSame($branchBefore, (bool) $branch->fresh()->offline_sales_enabled);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 9. Audit event recorded on successful enable
    // ──────────────────────────────────────────────────────────────────────────

    public function test_audit_event_recorded_on_successful_enable(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createReadyState();

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.enable', $this->company), [
                'branch_id'  => $branch->id,
                'profile_id' => $profile->id,
            ]);

        $log = AuditLog::withoutGlobalScopes()
            ->where('action', 'pilot_enabled')
            ->where('tenant_id', $this->company->id)
            ->first();

        $this->assertNotNull($log, 'pilot_enabled audit event should be recorded');
        $this->assertSame($this->systemAdmin->id, $log->actor_user_id);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 10. Audit event recorded on successful disable
    // ──────────────────────────────────────────────────────────────────────────

    public function test_audit_event_recorded_on_successful_disable(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createReadyState();

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.disable', $this->company), [
                'branch_id'  => $branch->id,
                'profile_id' => $profile->id,
                'level'      => 'terminal',
            ]);

        $log = AuditLog::withoutGlobalScopes()
            ->where('action', 'pilot_disabled')
            ->where('tenant_id', $this->company->id)
            ->first();

        $this->assertNotNull($log, 'pilot_disabled audit event should be recorded');
        $this->assertSame($this->systemAdmin->id, $log->actor_user_id);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 11. Audit event recorded when enable is rejected (post-write not ready)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_audit_event_recorded_when_enable_rejected(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createPendingState([
            'machine_identification_number' => null,
        ]);

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.enable', $this->company), [
                'branch_id'      => $branch->id,
                'profile_id'     => $profile->id,
                'enable_tenant'  => true,
                'enable_branch'  => true,
                'enable_terminal'=> true,
            ]);

        $log = AuditLog::withoutGlobalScopes()
            ->where('action', 'pilot_enable_rejected')
            ->where('tenant_id', $this->company->id)
            ->first();

        $this->assertNotNull($log, 'pilot_enable_rejected audit event should be recorded');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 12. Non-platform-admin receives 403 on both endpoints
    // ──────────────────────────────────────────────────────────────────────────

    public function test_tenant_user_cannot_access_enable_or_disable(): void
    {
        ['branch' => $branch, 'profile' => $profile] = $this->createReadyState();

        app(TenantContext::class)->setTenant($this->company);
        $tenantUser = User::factory()->create([
            'tenant_id'  => $this->company->id,
            'actor_type' => 'tenant_user',
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($tenantUser)
            ->postJson(route('system-admin.pilot.enable', $this->company), [
                'branch_id'  => $branch->id,
                'profile_id' => $profile->id,
            ])
            ->assertStatus(403);

        $this->actingAs($tenantUser)
            ->postJson(route('system-admin.pilot.disable', $this->company), [
                'branch_id'  => $branch->id,
                'profile_id' => $profile->id,
                'level'      => 'tenant',
            ])
            ->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 13. Cross-tenant branch returns 404 on enable
    // ──────────────────────────────────────────────────────────────────────────

    public function test_cross_tenant_branch_returns_404_on_enable(): void
    {
        ['branch' => $_, 'profile' => $profile] = $this->createReadyState();

        $otherTenant = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::create([
            'tenant_id'   => $otherTenant->id,
            'name'        => 'Other Branch',
            'branch_code' => 'OT-001',
            'status'      => 'active',
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.pilot.enable', $this->company), [
                'branch_id'  => $otherBranch->id, // does not belong to $this->company
                'profile_id' => $profile->id,
            ])
            ->assertNotFound();
    }
}
