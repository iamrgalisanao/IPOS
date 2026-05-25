<?php

namespace Tests\Feature\SystemAdmin;

use App\Models\Branch;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_platform_admin_can_view_tenant_provisioning_index(): void
    {
        $platformAdmin = User::factory()->platformSupport()->create();
        Tenant::factory()->count(2)->create();

        $response = $this->actingAs($platformAdmin)->get('/system-admin/tenants');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SystemAdmin/Tenants/Index')
            ->has('tenants.data')
            ->has('plans')
            ->has('featureCoverage')
        );
    }

    public function test_tenant_user_cannot_access_system_admin_tenant_provisioning_index(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($tenant);
        app(RbacSeeder::class)->seedForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $tenantUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($tenantUser)->get('/system-admin/tenants');

        $response->assertForbidden();
    }

    public function test_platform_admin_can_create_tenant_with_plan_and_feature_overrides(): void
    {
        $platformAdmin = User::factory()->platformSupport()->create();

        $response = $this->actingAs($platformAdmin)->post('/system-admin/tenants', [
            'name' => 'Pilot Tenant',
            'status' => 'trial',
            'plan' => 'professional',
            'feature_overrides' => [
                'quickbooks.sync' => true,
                'layout.custom' => true,
            ],
        ]);

        $response->assertRedirect('/system-admin/tenants');

        $tenant = Tenant::where('name', 'Pilot Tenant')->firstOrFail();
        $this->assertEquals('trial', $tenant->status);
        $this->assertEquals('professional', $tenant->subscription_metadata['plan']);
        $this->assertTrue($tenant->subscription_metadata['features']['quickbooks.sync']);
        $this->assertTrue($tenant->subscription_metadata['features']['layout.custom']);
    }

    public function test_platform_admin_can_update_tenant_profile_status_and_plan(): void
    {
        $platformAdmin = User::factory()->platformSupport()->create();
        $tenant = Tenant::factory()->create([
            'name' => 'Legacy Tenant',
            'status' => 'trial',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        $response = $this->actingAs($platformAdmin)->put("/system-admin/tenants/{$tenant->id}", [
            'name' => 'Legacy Tenant Updated',
            'status' => 'active',
            'plan' => 'enterprise',
            'feature_overrides' => [
                'quickbooks.sync' => true,
            ],
        ]);

        $response->assertRedirect('/system-admin/tenants');

        $tenant->refresh();
        $this->assertEquals('Legacy Tenant Updated', $tenant->name);
        $this->assertEquals('active', $tenant->status);
        $this->assertEquals('enterprise', $tenant->subscription_metadata['plan']);
        $this->assertTrue($tenant->subscription_metadata['features']['quickbooks.sync']);
    }

    public function test_tenant_user_cannot_self_escalate_via_system_admin_provisioning_update(): void
    {
        $tenant = Tenant::factory()->create([
            'subscription_metadata' => ['plan' => 'basic', 'features' => []],
        ]);

        app(TenantContext::class)->setTenant($tenant);
        app(RbacSeeder::class)->seedForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $tenantUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($tenantUser)->put("/system-admin/tenants/{$tenant->id}", [
            'name' => 'Escalation Attempt',
            'status' => 'active',
            'plan' => 'enterprise',
            'feature_overrides' => [
                'quickbooks.sync' => true,
            ],
        ]);

        $response->assertForbidden();

        $tenant->refresh();
        $this->assertEquals('basic', $tenant->subscription_metadata['plan']);
        $this->assertFalse($tenant->subscription_metadata['features']['quickbooks.sync'] ?? false);
    }

    public function test_index_includes_readiness_and_feature_gate_coverage_summary(): void
    {
        $platformAdmin = User::factory()->platformSupport()->create();
        $tenant = Tenant::factory()->create([
            'status' => 'trial',
            'subscription_metadata' => ['plan' => 'basic'],
        ]);

        app(TenantContext::class)->setTenant($tenant);
        Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($platformAdmin)->get('/system-admin/tenants');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('tenants.data', 1)
            ->where('tenants.data.0.id', $tenant->id)
            ->has('tenants.data.0.readiness.missing')
            ->has('featureCoverage')
            ->where('featureCoverage.0.config_exists', true)
            ->has('featureCoverage.0.middleware_enforced')
        );
    }

    public function test_feature_coverage_marks_sales_pos_as_enforced_with_non_zero_routes(): void
    {
        $platformAdmin = User::factory()->platformSupport()->create();

        $response = $this->actingAs($platformAdmin)->get('/system-admin/tenants');

        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $coverage = collect($props['featureCoverage'] ?? []);
        $salesPos = $coverage->firstWhere('feature_flag', 'sales.pos');

        $this->assertNotNull($salesPos, 'Expected sales.pos feature coverage row to be present.');
        $this->assertTrue((bool) ($salesPos['middleware_enforced'] ?? false), 'Expected sales.pos to be middleware-enforced.');
        $this->assertGreaterThan(0, (int) ($salesPos['route_count'] ?? 0), 'Expected sales.pos to have non-zero gated route count.');
    }

    public function test_readiness_marks_machine_profile_missing_when_not_registered(): void
    {
        $platformAdmin = User::factory()->platformSupport()->create();
        $tenant = Tenant::factory()->create([
            'subscription_metadata' => ['plan' => 'professional'],
        ]);
        app(TenantContext::class)->setTenant($tenant);
        Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($platformAdmin)->get('/system-admin/tenants');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('tenants.data.0.id', $tenant->id)
            ->has('tenants.data.0.readiness.missing')
        );

        app(TenantContext::class)->setTenant($tenant);
        SalesMachineProfile::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $tenant->branches()->first()->id,
            'profile_code' => 'PILOT-01',
        ]);
        app(TenantContext::class)->clear();

        $response2 = $this->actingAs($platformAdmin)->get('/system-admin/tenants');
        $response2->assertOk();
    }

    public function test_readiness_marks_machine_profile_compliance_incomplete_until_required_fields_present(): void
    {
        $platformAdmin = User::factory()->platformSupport()->create();
        $tenant = Tenant::factory()->create([
            'subscription_metadata' => ['plan' => 'professional'],
        ]);

        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        SalesMachineProfile::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'profile_code' => 'PILOT-01',
        ]);
        app(TenantContext::class)->clear();

        $response = $this->actingAs($platformAdmin)->get('/system-admin/tenants');
        $response->assertInertia(fn (Assert $page) => $page
            ->where('tenants.data.0.id', $tenant->id)
            ->where('tenants.data.0.readiness.ready', false)
            ->where('tenants.data.0.readiness.missing.0', 'machine_profile_compliance_incomplete')
        );

        app(TenantContext::class)->setTenant($tenant);
        $profile = SalesMachineProfile::where('tenant_id', $tenant->id)->firstOrFail();
        $profile->update([
            'machine_identification_number' => 'MIN-123456',
            'machine_serial_number' => 'SN-123456',
            'permit_to_use_number' => 'PTU-123456',
            'authority_to_generate_control_number' => 'ATCN-123456',
            'supplier_accreditation_number' => 'ACC-123456',
        ]);
        app(TenantContext::class)->clear();

        $response2 = $this->actingAs($platformAdmin)->get('/system-admin/tenants');
        $response2->assertInertia(fn (Assert $page) => $page
            ->where('tenants.data.0.id', $tenant->id)
            ->where('tenants.data.0.readiness.ready', true)
        );
    }
}
