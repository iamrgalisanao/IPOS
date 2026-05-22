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
use App\Services\SystemAdminDashboardService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemAdminDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company1;
    protected Tenant $company2;
    protected User $systemAdmin;
    protected SystemAdminDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutVite();

        $this->service = app(SystemAdminDashboardService::class);
        $this->systemAdmin = User::factory()->platformSupport()->create();
    }

    public function test_dashboard_summary_returns_tenant_counts_by_readiness_state(): void
    {
        // 1. Blocked
        $this->company1 = Tenant::factory()->create(['status' => 'active', 'name' => 'T1']);
        
        // 2. Ready for operations
        $this->company2 = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
            'offline_sales_enabled' => true,
        ]);
        $this->createOperationallyReadyState($this->company2);
        
        $summary = $this->service->getSummary();
        
        $this->assertEquals(1, $summary['readiness_counts']['blocked']);
        $this->assertEquals(1, $summary['readiness_counts']['ready_for_operations']);
        $this->assertEquals(0, $summary['readiness_counts']['ready_for_pilot']);
    }

    public function test_dashboard_summary_returns_compliance_status_counts(): void
    {
        $this->company1 = Tenant::factory()->create([
            'status' => 'active', 
            'subscription_metadata' => ['plan' => null], // missing plan
        ]);
        // no branches -> branch_exists failed
        // feature gates aligned passed (no mismatches if features is empty)
        
        $summary = $this->service->getSummary();
        
        $this->assertEquals(1, $summary['compliance_counts']['tenants_missing_plan']);
        $this->assertEquals(1, $summary['compliance_counts']['tenants_no_branches']);
    }

    public function test_dashboard_summary_returns_pilot_readiness_counts(): void
    {
        $this->company1 = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'professional'],
            'offline_sales_enabled' => false,
        ]);
        $this->createPilotReadyState($this->company1);
        
        $summary = $this->service->getSummary();
        
        // The pilot ready state creates 2 branches: 1 ready, 1 pending
        $this->assertEquals(1, $summary['pilot_counts']['branches_ready']);
        $this->assertEquals(1, $summary['pilot_counts']['branches_pending']);
        $this->assertEquals(0, $summary['pilot_counts']['branches_blocked']);
    }

    public function test_dashboard_summary_includes_recent_readiness_sign_offs(): void
    {
        $this->company1 = Tenant::factory()->create(['status' => 'active', 'name' => 'T1']);
        
        TenantReadinessSignOff::withoutGlobalScopes()->create([
            'tenant_id' => $this->company1->id,
            'signed_off_by' => $this->systemAdmin->id,
            'signed_off_state' => 'blocked',
            'readiness_state_calculated' => 'blocked',
            'notes' => 'Some note',
            'readiness_snapshot' => [],
            'created_at' => now(),
        ]);
        
        $summary = $this->service->getSummary();
        
        $this->assertCount(1, $summary['recent_sign_offs']);
        $this->assertEquals('blocked', $summary['recent_sign_offs'][0]['signed_off_state']);
        $this->assertEquals('Some note', $summary['recent_sign_offs'][0]['notes']);
        $this->assertEquals($this->systemAdmin->name ?? $this->systemAdmin->email, $summary['recent_sign_offs'][0]['signer_name']);
        $this->assertEquals($this->company1->id, $summary['recent_sign_offs'][0]['tenant_id']);
    }

    public function test_service_performs_no_mutations(): void
    {
        $this->company1 = Tenant::factory()->create(['status' => 'active', 'name' => 'T1']);
        
        $beforeCount = TenantReadinessSignOff::withoutGlobalScopes()->count();
        $auditCountBefore = AuditLog::withoutGlobalScopes()->count();
        
        $this->service->getSummary();
        
        $this->assertEquals($beforeCount, TenantReadinessSignOff::withoutGlobalScopes()->count());
        $this->assertEquals($auditCountBefore, AuditLog::withoutGlobalScopes()->count());
    }

    private function createOperationallyReadyState(Tenant $tenant): array
    {
        app(TenantContext::class)->setTenant($tenant);

        $tenant->update(['offline_sales_enabled' => true]);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'branch_code' => 'MB-001',
            'status' => 'active',
            'offline_sales_enabled' => true,
        ]);

        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);

        $profile = SalesMachineProfile::create([
            'tenant_id' => $tenant->id,
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
            'tenant_id' => $tenant->id,
            'name' => 'manage_offline_sales_settings',
            'description' => 'Can manage terminal offline sales settings and sequence registry',
        ]);

        $ownerRole = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner/Admin',
            'description' => 'Full tenant administrative control',
        ]);

        $dashboardPermission = Permission::create([
            'tenant_id' => $tenant->id,
            'name' => 'view_multi_branch_dashboard',
            'description' => 'Can view cross-branch data',
        ]);

        $ownerRole->permissions()->sync([$permission->id, $dashboardPermission->id]);
        $owner->assignRole($ownerRole);

        app(TenantContext::class)->clear();

        return compact('branch', 'owner', 'profile');
    }

    private function createPilotReadyState(Tenant $tenant): array
    {
        $state = $this->createOperationallyReadyState($tenant);

        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pilot Pending Branch',
            'branch_code' => 'PPB-001',
            'status' => 'active',
            'offline_sales_enabled' => false,
        ]);

        $profile = SalesMachineProfile::create([
            'tenant_id' => $tenant->id,
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
