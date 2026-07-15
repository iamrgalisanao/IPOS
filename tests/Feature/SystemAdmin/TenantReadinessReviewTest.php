<?php

namespace Tests\Feature\SystemAdmin;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\TenantReadinessSignOff;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantReadinessReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;
    protected User $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->company = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
            'offline_sales_enabled' => false,
        ]);
        $this->systemAdmin = User::factory()->platformSupport()->create();
    }

    public function test_platform_admin_can_view_readiness_summary_payload(): void
    {
        $state = $this->createOperationallyReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.readiness.show', $this->company));

        $response->assertOk()
            ->assertJsonPath('tenant_id', $this->company->id)
            ->assertJsonPath('readiness_state', 'ready_for_operations')
            ->assertJsonPath('checks.branch_count', 1)
            ->assertJsonPath('branches.0.id', $state['branch']->id)
            ->assertJsonPath('branches.0.pilot_outcome', 'ready')
            ->assertJsonPath('checks.pilot_eligibility_ready_branches', 1)
            ->assertJsonPath('compliance_detail.tenant.0.code', 'tenant_profile_complete')
            ->assertJsonPath('compliance_detail.branches.0.branch_id', $state['branch']->id)
            ->assertJsonPath('compliance_detail.branches.0.checks.0.code', 'branch_active');

        $response->assertJsonStructure([
            'compliance_detail' => [
                'tenant' => [
                    '*' => ['code', 'reason_code', 'status', 'severity', 'source', 'entity', 'message', 'remediation'],
                ],
                'branches' => [
                    '*' => [
                        'branch_id',
                        'branch_name',
                        'checks' => [
                            '*' => ['code', 'reason_code', 'status', 'severity', 'source', 'entity', 'message', 'remediation'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_compliance_detail_includes_missing_profile_and_missing_fields_details(): void
    {
        app(TenantContext::class)->setTenant($this->company);

        $missingProfileBranch = Branch::create([
            'tenant_id' => $this->company->id,
            'name' => 'No Profile Branch',
            'branch_code' => 'NPB-001',
            'status' => 'active',
            'offline_sales_enabled' => false,
        ]);

        $incompleteProfileBranch = Branch::create([
            'tenant_id' => $this->company->id,
            'name' => 'Incomplete Profile Branch',
            'branch_code' => 'IPB-001',
            'status' => 'active',
            'offline_sales_enabled' => false,
        ]);

        SalesMachineProfile::create([
            'tenant_id' => $this->company->id,
            'branch_id' => $incompleteProfileBranch->id,
            'profile_code' => 'IPB-T1',
            'machine_identification_number' => 'MIN-IPB',
            'machine_serial_number' => 'SN-IPB',
            'permit_to_use_number' => null,
            'authority_to_generate_control_number' => null,
            'supplier_accreditation_number' => null,
            'offline_sales_enabled' => true,
            'offline_sequence_prefix' => 'IPB-OFF',
            'offline_sequence_status' => 'active',
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.readiness.show', $this->company));

        $response->assertOk();

        $branches = collect($response->json('compliance_detail.branches'));

        $missingProfileChecks = collect($branches->firstWhere('branch_id', $missingProfileBranch->id)['checks'] ?? []);
        $missingProfileCheck = $missingProfileChecks->firstWhere('code', 'machine_profile_present');
        $this->assertNotNull($missingProfileCheck);
        $this->assertSame('failed', $missingProfileCheck['status']);

        $incompleteChecks = collect($branches->firstWhere('branch_id', $incompleteProfileBranch->id)['checks'] ?? []);
        $complianceCheck = $incompleteChecks->firstWhere('code', 'machine_profile_compliance');
        $this->assertNotNull($complianceCheck);
        $this->assertSame('failed', $complianceCheck['status']);
        $this->assertContains('permit_to_use_number', $complianceCheck['missing_fields']);
    }

    public function test_readiness_show_is_derived_only_and_does_not_persist_sign_off_or_audit_mutations(): void
    {
        $this->createOperationallyReadyState();

        $this->assertDatabaseCount('tenant_readiness_sign_offs', 0);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());

        $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.readiness.show', $this->company))
            ->assertOk();

        $this->assertDatabaseCount('tenant_readiness_sign_offs', 0);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_readiness_summary_is_isolated_to_target_tenant_data_only(): void
    {
        $this->createOperationallyReadyState();

        $otherTenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
        ]);

        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Tenant Branch',
            'branch_code' => 'OTB-001',
            'status' => 'active',
            'offline_sales_enabled' => true,
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.readiness.show', $this->company));

        $response->assertOk();

        $branchIds = collect($response->json('branches'))->pluck('id')->all();
        $complianceBranchIds = collect($response->json('compliance_detail.branches'))->pluck('branch_id')->all();

        $this->assertNotContains($otherBranch->id, $branchIds);
        $this->assertNotContains($otherBranch->id, $complianceBranchIds);
    }

    public function test_tenant_user_is_forbidden_from_platform_readiness_endpoint(): void
    {
        app(TenantContext::class)->setTenant($this->company);
        $tenantUser = User::factory()->create([
            'tenant_id' => $this->company->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($tenantUser)
            ->getJson(route('system-admin.readiness.show', $this->company));

        $response->assertForbidden();
    }

    public function test_readiness_is_blocked_without_branches_and_admins(): void
    {
        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.readiness.show', $this->company));

        $response->assertOk()
            ->assertJsonPath('readiness_state', 'blocked')
            ->assertJsonPath('checks.branch_count', 0);

        $this->assertContains('branch_missing', $response->json('blockers'));
    }

    public function test_readiness_is_ready_for_pilot_when_some_branches_are_ready_but_not_all_operational(): void
    {
        $state = $this->createOperationallyReadyState();

        app(TenantContext::class)->setTenant($this->company);
        $branch2 = Branch::create([
            'tenant_id' => $this->company->id,
            'name' => 'Branch Two',
            'branch_code' => 'B2-001',
            'status' => 'active',
            'offline_sales_enabled' => false,
        ]);

        SalesMachineProfile::create([
            'tenant_id' => $this->company->id,
            'branch_id' => $branch2->id,
            'profile_code' => 'B2-T1',
            'machine_identification_number' => 'MIN-2',
            'machine_serial_number' => 'SN-2',
            'permit_to_use_number' => 'PTU-2',
            'authority_to_generate_control_number' => 'ATGCN-2',
            'supplier_accreditation_number' => 'ACC-2',
            'offline_sales_enabled' => true,
            'offline_sequence_prefix' => 'B2-OFF',
            'offline_sequence_status' => 'active',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.readiness.show', $this->company));

        $response->assertOk()
            ->assertJsonPath('checks.branch_count', 2)
            ->assertJsonPath('checks.pilot_eligibility_ready_branches', 1)
            ->assertJsonPath('readiness_state', 'ready_for_pilot');

        $branchPayload = collect($response->json('branches'))
            ->firstWhere('id', $branch2->id);

        $this->assertNotNull($branchPayload);
        $this->assertContains('branch_offline_enabled', $branchPayload['pilot_pending_reasons']);
    }

    public function test_readiness_reports_blocked_when_subscription_feature_overrides_are_misaligned(): void
    {
        $this->createOperationallyReadyState();

        $this->company->update([
            'subscription_metadata' => [
                'plan' => 'professional',
                'features' => [
                    'nonexistent.feature' => true,
                ],
            ],
        ]);

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.readiness.show', $this->company));

        $response->assertOk()
            ->assertJsonPath('checks.feature_gates_aligned', false)
            ->assertJsonPath('readiness_state', 'blocked');

        $this->assertContains('feature_gates_misaligned', $response->json('blockers'));
    }

    public function test_platform_admin_can_sign_off_ready_for_operations(): void
    {
        $this->createOperationallyReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.readiness.sign-off', $this->company), [
                'state' => 'ready_for_operations',
                'notes' => 'Tenant is ready for operations.',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('signed_off_state', 'ready_for_operations')
            ->assertJsonPath('readiness_state_calculated', 'ready_for_operations');

        $signOff = TenantReadinessSignOff::first();
        $this->assertNotNull($signOff);
        $this->assertSame($this->company->id, $signOff->tenant_id);
        $this->assertSame($this->systemAdmin->id, $signOff->signed_off_by);
        $this->assertSame('ready_for_operations', $signOff->readiness_snapshot['readiness_state']);
        $this->assertSame(1, $signOff->readiness_snapshot['checks']['branch_count']);

        $this->assertNotNull(AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $this->company->id)
            ->where('action', 'tenant_readiness_signed_off')
            ->where('actor_user_id', $this->systemAdmin->id)
            ->first());
    }

    public function test_platform_admin_can_sign_off_ready_for_pilot_when_calculated_state_is_pilot(): void
    {
        $this->createPilotReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.readiness.sign-off', $this->company), [
                'state' => 'ready_for_pilot',
                'notes' => 'Pilot readiness accepted.',
            ]);

        $response->assertOk()
            ->assertJsonPath('signed_off_state', 'ready_for_pilot')
            ->assertJsonPath('readiness_state_calculated', 'ready_for_pilot');

        $this->assertDatabaseHas('tenant_readiness_sign_offs', [
            'tenant_id' => $this->company->id,
            'signed_off_state' => 'ready_for_pilot',
            'readiness_state_calculated' => 'ready_for_pilot',
        ]);
    }

    public function test_ready_sign_off_is_rejected_when_blockers_exist_and_attempt_is_audited(): void
    {
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.readiness.sign-off', $this->company), [
                'state' => 'ready_for_pilot',
                'notes' => 'Trying to sign off too early.',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('readiness_state_calculated', 'blocked');

        $this->assertContains('branch_missing', $response->json('blockers'));
        $this->assertDatabaseCount('tenant_readiness_sign_offs', 0);

        $log = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $this->company->id)
            ->where('action', 'tenant_readiness_sign_off_rejected')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('ready_for_pilot', $log->metadata['signed_off_state']);
        $this->assertSame('rejected', $log->metadata['outcome']);
        $this->assertSame('blocked', $log->metadata['readiness_state_calculated']);
        $this->assertContains('branch_missing', $log->metadata['readiness_snapshot']['blockers']);
    }

    public function test_ready_for_operations_is_rejected_when_calculated_state_is_only_pilot(): void
    {
        $this->createPilotReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.readiness.sign-off', $this->company), [
                'state' => 'ready_for_operations',
                'notes' => 'Attempt operations sign-off.',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('readiness_state_calculated', 'ready_for_pilot');

        $this->assertDatabaseCount('tenant_readiness_sign_offs', 0);
    }

    public function test_blocked_decision_succeeds_with_notes_and_stores_blocker_snapshot(): void
    {
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.readiness.sign-off', $this->company), [
                'state' => 'blocked',
                'notes' => 'Missing setup prerequisites.',
            ]);

        $response->assertOk()
            ->assertJsonPath('signed_off_state', 'blocked')
            ->assertJsonPath('readiness_state_calculated', 'blocked');

        $signOff = TenantReadinessSignOff::first();
        $this->assertNotNull($signOff);
        $this->assertSame('blocked', $signOff->signed_off_state);
        $this->assertSame('Missing setup prerequisites.', $signOff->notes);
        $this->assertContains('branch_missing', $signOff->readiness_snapshot['blockers']);
    }

    public function test_blocked_decision_requires_notes_and_attempt_is_audited(): void
    {
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.readiness.sign-off', $this->company), [
                'state' => 'blocked',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Blocked readiness decisions require notes.');

        $this->assertDatabaseCount('tenant_readiness_sign_offs', 0);
        $this->assertNotNull(AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $this->company->id)
            ->where('action', 'tenant_readiness_sign_off_rejected')
            ->first());
    }

    public function test_tenant_user_is_forbidden_from_sign_off_endpoint(): void
    {
        app(TenantContext::class)->setTenant($this->company);
        $tenantUser = User::factory()->create([
            'tenant_id' => $this->company->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($tenantUser)
            ->postJson(route('system-admin.readiness.sign-off', $this->company), [
                'state' => 'blocked',
                'notes' => 'Tenant user should not sign off.',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('tenant_readiness_sign_offs', 0);
    }

    public function test_platform_admin_can_export_readiness_json_with_sign_off_history(): void
    {
        $this->createOperationallyReadyState();

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.readiness.sign-off', $this->company), [
                'state' => 'ready_for_operations',
                'notes' => 'Ready for operator review pack.',
            ])
            ->assertOk();

        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.readiness.export', [
                'company' => $this->company,
                'format' => 'json',
            ]));

        $response->assertOk()
            ->assertJsonPath('summary.tenant_id', $this->company->id)
            ->assertJsonPath('summary.readiness_state', 'ready_for_operations')
            ->assertJsonPath('sign_off_history.0.signed_off_state', 'ready_for_operations')
            ->assertJsonPath('sign_off_history.0.notes', 'Ready for operator review pack.');
    }

    public function test_platform_admin_can_export_readiness_csv(): void
    {
        $state = $this->createOperationallyReadyState();

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.readiness.sign-off', $this->company), [
                'state' => 'ready_for_operations',
                'notes' => 'CSV export evidence.',
            ])
            ->assertOk();

        $response = $this->actingAs($this->systemAdmin)
            ->get(route('system-admin.readiness.export', [
                'company' => $this->company,
                'format' => 'csv',
            ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Tenant Readiness Summary', $content);
        $this->assertStringContainsString($state['branch']->name, $content);
        $this->assertStringContainsString('Sign-Off History', $content);
        $this->assertStringContainsString('ready_for_operations', $content);
        $this->assertStringContainsString('not a BIR/CPA certification', $content);
    }

    public function test_platform_admin_can_export_printable_readiness_html(): void
    {
        $this->createOperationallyReadyState();

        $response = $this->actingAs($this->systemAdmin)
            ->get(route('system-admin.readiness.export', [
                'company' => $this->company,
                'format' => 'html',
            ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $response->assertSee('Tenant Readiness Summary', false)
            ->assertSee($this->company->name)
            ->assertSee('ready_for_operations', false)
            ->assertSee('No sign-off history recorded.', false);
    }

    public function test_tenant_user_is_forbidden_from_readiness_export(): void
    {
        app(TenantContext::class)->setTenant($this->company);
        $tenantUser = User::factory()->create([
            'tenant_id' => $this->company->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($tenantUser)
            ->get(route('system-admin.readiness.export', [
                'company' => $this->company,
                'format' => 'json',
            ]));

        $response->assertForbidden();
    }

    private function createOperationallyReadyState(): array
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

        $ownerRole = Role::create([
            'tenant_id' => $this->company->id,
            'name' => 'Owner/Admin',
            'description' => 'Full tenant administrative control',
        ]);

        $dashboardPermission = Permission::create([
            'tenant_id' => $this->company->id,
            'name' => 'view_multi_branch_dashboard',
            'description' => 'Can view cross-branch data',
        ]);

        $ownerRole->permissions()->sync([$permission->id, $dashboardPermission->id]);
        $owner->assignRole($ownerRole);

        app(TenantContext::class)->clear();

        return compact('branch', 'owner', 'profile');
    }

    private function createPilotReadyState(): array
    {
        $state = $this->createOperationallyReadyState();

        app(TenantContext::class)->setTenant($this->company);
        $branch = Branch::create([
            'tenant_id' => $this->company->id,
            'name' => 'Pilot Pending Branch',
            'branch_code' => 'PPB-001',
            'status' => 'active',
            'offline_sales_enabled' => false,
        ]);

        $profile = SalesMachineProfile::create([
            'tenant_id' => $this->company->id,
            'branch_id' => $branch->id,
            'profile_code' => 'PPB-T1',
            'machine_identification_number' => 'MIN-P',
            'machine_serial_number' => 'SN-P',
            'permit_to_use_number' => 'PTU-P',
            'authority_to_generate_control_number' => 'ATGCN-P',
            'supplier_accreditation_number' => 'ACC-P',
            'offline_sales_enabled' => true,
            'offline_sequence_prefix' => 'PPB-OFF',
            'offline_sequence_status' => 'active',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        return array_merge($state, [
            'pilot_pending_branch' => $branch,
            'pilot_pending_profile' => $profile,
        ]);
    }
}
