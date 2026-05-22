<?php

namespace Tests\Feature\SystemAdmin;

use App\Models\Branch;
use App\Models\CompanyOnboardingEvent;
use App\Models\CompanyOnboardingState;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;
    protected User $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Create test company/tenant
        $this->company = Tenant::factory()->create();

        // Create system admin user
        $this->systemAdmin = User::factory()->platformSupport()->create();

        app(TenantContext::class)->setTenant($this->company);

        // Seed RBAC roles
        Role::factory()->create(['name' => 'Owner', 'tenant_id' => $this->company->id]);
        Role::factory()->create(['name' => 'Admin', 'tenant_id' => $this->company->id]);

        app(TenantContext::class)->clear();
    }

    // === Initial Branch Creation Tests ===

    public function test_system_admin_can_view_onboarding_state()
    {
        $response = $this->actingAs($this->systemAdmin)
            ->getJson(route('system-admin.onboarding.show', $this->company));

        $response->assertStatus(200);
    }

    public function test_system_admin_can_create_initial_branch()
    {
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
                'location' => 'HQ',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('branch.name', 'Main Branch')
            ->assertJsonPath('branch.branch_code', 'MB-001');

        // Verify database
        $this->assertDatabaseHas('branches', [
            'tenant_id' => $this->company->id,
            'name' => 'Main Branch',
            'branch_code' => 'MB-001',
        ]);

        // Verify onboarding state updated
        $onboardingState = CompanyOnboardingState::where('tenant_id', $this->company->id)->first();
        $this->assertNotNull($onboardingState->initial_branch_id);
        $this->assertEquals('branch_created', $onboardingState->status);
    }

    public function test_prevent_duplicate_branch_creation()
    {
        // Create first branch
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
            ]);

        // Try to create second branch
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Second Branch',
                'branch_code' => 'MB-002',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_prevent_invalid_branch_code()
    {
        // Try empty branch code
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => '',
            ]);

        $response->assertStatus(422);
    }

    public function test_prevent_global_branch_code_duplicate()
    {
        // Create first company and branch
        $company1 = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($company1);
        Branch::factory()->create(['tenant_id' => $company1->id, 'branch_code' => 'MB-001']);
        app(TenantContext::class)->clear();

        // Try to create branch with same code in different company
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001', // Same code as company1
            ]);

        $response->assertStatus(422);
    }

    // === Owner User Creation Tests ===

    public function test_system_admin_can_create_owner_user()
    {
        // Create branch first
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
            ]);

        // Create owner user
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $this->company), [
                'email' => 'owner@company.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+1234567890',
                'send_bootstrap_link' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'owner@company.com')
            ->assertJsonPath('bootstrap_link', '#');

        // Verify database
        $this->assertDatabaseHas('users', [
            'email' => 'owner@company.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        // Verify onboarding state
        $onboardingState = CompanyOnboardingState::where('tenant_id', $this->company->id)->first();
        $this->assertNotNull($onboardingState->owner_user_id);
        $this->assertEquals('owner_assigned', $onboardingState->status);
        $this->assertNotNull($onboardingState->bootstrap_token);
    }

    public function test_prevent_creating_owner_without_branch()
    {
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $this->company), [
                'email' => 'owner@company.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ]);

        $response->assertStatus(422);
    }

    public function test_prevent_duplicate_owner_user()
    {
        // Create branch
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
            ]);

        // Create first owner
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $this->company), [
                'email' => 'owner@company.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ]);

        // Try to create second owner
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $this->company), [
                'email' => 'owner2@company.com',
                'first_name' => 'Jane',
                'last_name' => 'Smith',
            ]);

        $response->assertStatus(422);
    }

    public function test_prevent_global_email_duplicate()
    {
        // Create user with email in system
        User::factory()->create(['email' => 'john@email.com']);

        // Create branch
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
            ]);

        // Try to create owner with existing email
        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $this->company), [
                'email' => 'john@email.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ]);

        $response->assertStatus(422);
    }

    // === Bootstrap Token Tests ===

    public function test_bootstrap_token_is_generated_and_unique()
    {
        // Create branch and owner
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
            ]);

        $response1 = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $this->company), [
                'email' => 'owner1@company.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ]);

        $token1 = $response1->json('bootstrap_token');
    $this->assertNotNull($token1);

        // Create different company and owner
        $company2 = Tenant::factory()->create();
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $company2), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-002',
            ]);

        $response2 = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $company2), [
                'email' => 'owner2@company.com',
                'first_name' => 'Jane',
                'last_name' => 'Smith',
            ]);

        $token2 = $response2->json('bootstrap_token');
    $this->assertNotNull($token2);

        // Verify tokens are different
        $this->assertNotEquals($token1, $token2);
        $this->assertNotNull($token1);
        $this->assertNotNull($token2);
    }

    // === Event Logging Tests ===

    public function test_onboarding_events_are_logged()
    {
        // Create branch
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
            ]);

        // Create owner
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $this->company), [
                'email' => 'owner@company.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ]);

        // Verify events logged
        $events = CompanyOnboardingEvent::where('tenant_id', $this->company->id)->get();
        $this->assertGreaterThanOrEqual(2, $events->count());

        // Verify event types
        $eventTypes = $events->pluck('event_type')->toArray();
        $this->assertContains('branch_created', $eventTypes);
        $this->assertContains('owner_created', $eventTypes);
    }

    // === Machine Profile Registration Tests ===

    public function test_system_admin_can_register_sales_machine_profile_after_branch_and_owner(): void
    {
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
            ]);

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $this->company), [
                'email' => 'owner@company.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ]);

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.register-machine-profile', $this->company), [
                'profile_code' => 'T1-M01',
                'machine_identification_number' => 'MIN-123456',
                'machine_serial_number' => 'SN-123456',
                'permit_to_use_number' => 'PTU-123456',
                'authority_to_generate_control_number' => 'ATCN-123456',
                'supplier_accreditation_number' => 'ACC-123456',
                'offline_sales_enabled' => true,
                'offline_sequence_prefix' => 'OFF-T1',
                'offline_sequence_next_value' => 1,
                'offline_sequence_status' => 'active',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('machine_profile.profile_code', 'T1-M01')
            ->assertJsonPath('machine_profile_compliance_ready', true);

        $this->assertDatabaseHas('sales_machine_profiles', [
            'tenant_id' => $this->company->id,
            'profile_code' => 'T1-M01',
            'machine_identification_number' => 'MIN-123456',
            'machine_serial_number' => 'SN-123456',
            'permit_to_use_number' => 'PTU-123456',
            'authority_to_generate_control_number' => 'ATCN-123456',
            'supplier_accreditation_number' => 'ACC-123456',
        ]);

        $this->assertDatabaseHas('company_onboarding_events', [
            'tenant_id' => $this->company->id,
            'event_type' => 'machine_profile_registered',
        ]);
    }

    public function test_prevent_registering_machine_profile_without_owner_assignment(): void
    {
        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
            ]);

        $response = $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.register-machine-profile', $this->company), [
                'profile_code' => 'T1-M01',
                'machine_identification_number' => 'MIN-123456',
                'machine_serial_number' => 'SN-123456',
                'permit_to_use_number' => 'PTU-123456',
                'authority_to_generate_control_number' => 'ATCN-123456',
                'supplier_accreditation_number' => 'ACC-123456',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_machine_profile_registration_is_tenant_scoped(): void
    {
        $otherCompany = Tenant::factory()->create();

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-branch', $this->company), [
                'branch_name' => 'Main Branch',
                'branch_code' => 'MB-001',
            ]);

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.create-owner', $this->company), [
                'email' => 'owner@company.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ]);

        $this->actingAs($this->systemAdmin)
            ->postJson(route('system-admin.onboarding.register-machine-profile', $this->company), [
                'profile_code' => 'T1-M01',
                'machine_identification_number' => 'MIN-123456',
                'machine_serial_number' => 'SN-123456',
                'permit_to_use_number' => 'PTU-123456',
                'authority_to_generate_control_number' => 'ATCN-123456',
                'supplier_accreditation_number' => 'ACC-123456',
            ])->assertStatus(201);

        app(TenantContext::class)->setTenant($otherCompany);
        $this->assertEquals(0, SalesMachineProfile::count(), 'Other tenant should not see machine profiles');
        app(TenantContext::class)->clear();
    }
}
