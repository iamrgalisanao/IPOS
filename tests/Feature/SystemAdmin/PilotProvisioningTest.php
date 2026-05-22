<?php

namespace Tests\Feature\SystemAdmin;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotProvisioningTest extends TestCase
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
    // Helper: build a fully-ready tenant state for use in multiple tests
    // ──────────────────────────────────────────────────────────────────────────

    private function createReadyState(): array
    {
        app(TenantContext::class)->setTenant($this->company);

        $this->company->update(['offline_sales_enabled' => true]);

        $branch = Branch::create([
            'tenant_id' => $this->company->id,
            'name' => 'Main Branch',
            'branch_code' => 'MB-001',
            'status' => 'active',
            'offline_sales_enabled' => true,
        ]);

        $owner = User::factory()->create([
            'tenant_id' => $this->company->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);

        $profile = SalesMachineProfile::create([
            'tenant_id' => $this->company->id,
            'branch_id' => $branch->id,
            'profile_code' => 'T1-M01',
            'machine_identification_number' => 'MIN-001',
            'machine_serial_number' => 'SN-001',
            'permit_to_use_number' => 'PTU-001',
            'authority_to_generate_control_number' => 'ATGCN-001',
            'supplier_accreditation_number' => 'ACC-001',
            'status' => 'active',
            'offline_sales_enabled' => true,
            'offline_sequence_prefix' => 'OFF-MB',
            'offline_sequence_next_value' => 1,
            'offline_sequence_status' => 'active',
        ]);

        $permission = Permission::create([
            'tenant_id' => $this->company->id,
            'name' => 'manage_offline_sales_settings',
            'description' => 'Can manage terminal offline sales settings and sequence registry',
        ]);

        $role = Role::create([
            'tenant_id' => $this->company->id,
            'name' => 'Owner',
        ]);
        $role->permissions()->attach($permission->id);

        app(TenantContext::class)->clear();

        return compact('branch', 'owner', 'profile', 'role', 'permission');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 1. Ready — all checks pass
    // ──────────────────────────────────────────────────────────────────────────

    public function test_ready_outcome_when_all_checks_pass(): void
    {
        $this->createReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'ready')
            ->assertJsonPath('blocking_reasons', [])
            ->assertJsonPath('pending_reasons', []);

        $checks = collect($response->json('checks'));
        $this->assertTrue($checks->every(fn ($c) => $c['status'] === 'pass'), 'All checks should pass');
        $this->assertCount(11, $checks);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2–5. Blocked outcomes
    // ──────────────────────────────────────────────────────────────────────────

    public function test_blocked_when_tenant_is_inactive(): void
    {
        $this->company->update(['status' => 'suspended']);

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'blocked');

        $this->assertContains('tenant_active', $response->json('blocking_reasons'));
    }

    public function test_blocked_when_no_branch_exists(): void
    {
        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'blocked');

        $this->assertContains('branch_exists', $response->json('blocking_reasons'));
    }

    public function test_blocked_when_no_active_owner_exists(): void
    {
        app(TenantContext::class)->setTenant($this->company);
        Branch::create([
            'tenant_id' => $this->company->id,
            'name' => 'Main Branch',
            'branch_code' => 'MB-001',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'blocked');

        $this->assertContains('owner_exists', $response->json('blocking_reasons'));
    }

    public function test_blocked_when_no_machine_profile_exists(): void
    {
        app(TenantContext::class)->setTenant($this->company);
        Branch::create([
            'tenant_id' => $this->company->id,
            'name' => 'Main Branch',
            'branch_code' => 'MB-001',
            'status' => 'active',
        ]);
        User::factory()->create([
            'tenant_id' => $this->company->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'blocked');

        $this->assertContains('machine_profile_exists', $response->json('blocking_reasons'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 6–12. Pending outcomes
    // ──────────────────────────────────────────────────────────────────────────

    public function test_pending_when_compliance_fields_are_incomplete(): void
    {
        $state = $this->createReadyState();

        // Remove a required compliance field
        $state['profile']->update(['permit_to_use_number' => null]);

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'pending');

        $this->assertContains('machine_profile_compliance_complete', $response->json('pending_reasons'));
    }

    public function test_pending_when_tenant_offline_disabled(): void
    {
        $this->createReadyState();
        $this->company->update(['offline_sales_enabled' => false]);

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'pending');

        $this->assertContains('tenant_offline_enabled', $response->json('pending_reasons'));
    }

    public function test_pending_when_branch_offline_disabled(): void
    {
        $state = $this->createReadyState();
        $state['branch']->update(['offline_sales_enabled' => false]);

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'pending');

        $this->assertContains('branch_offline_enabled', $response->json('pending_reasons'));
    }

    public function test_pending_when_terminal_offline_explicitly_disabled(): void
    {
        $state = $this->createReadyState();
        $state['profile']->update(['offline_sales_enabled' => false]);

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'pending');

        $this->assertContains('terminal_offline_enabled', $response->json('pending_reasons'));
    }

    public function test_pending_when_offline_prefix_is_missing(): void
    {
        $state = $this->createReadyState();
        $state['profile']->update(['offline_sequence_prefix' => null]);

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'pending');

        $this->assertContains('offline_prefix_assigned', $response->json('pending_reasons'));
    }

    public function test_pending_when_sequence_status_is_suspended(): void
    {
        $state = $this->createReadyState();
        $state['profile']->update(['offline_sequence_status' => 'suspended']);

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'pending');

        $this->assertContains('offline_sequence_active', $response->json('pending_reasons'));
    }

    public function test_pending_when_manage_offline_permission_not_assigned(): void
    {
        $this->createReadyState();

        // Detach permission from all roles
        app(TenantContext::class)->setTenant($this->company);
        Permission::where('tenant_id', $this->company->id)
            ->where('name', 'manage_offline_sales_settings')
            ->get()
            ->each(fn ($p) => $p->roles()->detach());
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk()
            ->assertJsonPath('outcome', 'pending');

        $this->assertContains('manage_offline_permission_assigned', $response->json('pending_reasons'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 13. Cross-tenant profile_id mismatch is rejected
    // ──────────────────────────────────────────────────────────────────────────

    public function test_cross_tenant_profile_id_is_rejected(): void
    {
        $otherTenant = Tenant::factory()->create();

        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Branch',
            'branch_code' => 'OB-001',
            'status' => 'active',
        ]);
        $otherProfile = SalesMachineProfile::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'profile_code' => 'OTHER-01',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        // Attempt to query $this->company's eligibility using a profile from another tenant
        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company) . "?profile_id={$otherProfile->id}");

        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 14. Security — non-platform-admin receives 403
    // ──────────────────────────────────────────────────────────────────────────

    public function test_tenant_user_receives_403(): void
    {
        app(TenantContext::class)->setTenant($this->company);
        $tenantUser = User::factory()->create([
            'tenant_id' => $this->company->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($tenantUser)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 15. Security — unauthenticated request is redirected
    // ──────────────────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_redirected(): void
    {
        $response = $this->get(route('system-admin.pilot.eligibility', $this->company));

        $response->assertRedirect('/login');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 16. Response shape — all 11 check keys are present
    // ──────────────────────────────────────────────────────────────────────────

    public function test_response_includes_all_check_keys(): void
    {
        $this->createReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company));

        $response->assertOk();

        $keys = collect($response->json('checks'))->pluck('key')->sort()->values()->all();

        $expected = collect([
            'tenant_active',
            'branch_exists',
            'owner_exists',
            'machine_profile_exists',
            'machine_profile_compliance_complete',
            'tenant_offline_enabled',
            'branch_offline_enabled',
            'terminal_offline_enabled',
            'offline_prefix_assigned',
            'offline_sequence_active',
            'manage_offline_permission_assigned',
        ])->sort()->values()->all();

        $this->assertEquals($expected, $keys);

        // Each check has the required fields
        foreach ($response->json('checks') as $check) {
            $this->assertArrayHasKey('key', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertContains($check['status'], ['pass', 'fail']);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 17. No mutation occurs during eligibility check
    // ──────────────────────────────────────────────────────────────────────────

    public function test_eligibility_check_does_not_mutate_data(): void
    {
        $state = $this->createReadyState();

        $profileBefore = SalesMachineProfile::withoutGlobalScopes()->find($state['profile']->id)->toArray();
        $tenantBefore = $this->company->fresh()->toArray();

        $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company))
            ->assertOk();

        $profileAfter = SalesMachineProfile::withoutGlobalScopes()->find($state['profile']->id)->toArray();
        $tenantAfter = $this->company->fresh()->toArray();

        $this->assertEquals($profileBefore, $profileAfter, 'Machine profile must not be mutated');
        $this->assertEquals($tenantBefore, $tenantAfter, 'Tenant must not be mutated');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 18. branch_id / profile_id query param selection
    // ──────────────────────────────────────────────────────────────────────────

    public function test_specific_branch_and_profile_selection_via_query_params(): void
    {
        $state = $this->createReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.pilot.eligibility', $this->company) . "?branch_id={$state['branch']->id}&profile_id={$state['profile']->id}");

        $response->assertOk()
            ->assertJsonPath('branch.id', $state['branch']->id)
            ->assertJsonPath('profile.id', $state['profile']->id)
            ->assertJsonPath('outcome', 'ready');
    }
}
