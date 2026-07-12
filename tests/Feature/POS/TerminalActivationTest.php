<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TerminalActivationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $admin;
    protected User $cashier;
    protected SalesMachineProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status'                => 'active',
            'offline_sales_enabled' => true,
        ]);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id'             => $this->tenant->id,
            'status'                => 'active',
            'offline_sales_enabled' => true,
        ]);

        $this->profile = SalesMachineProfile::create([
            'tenant_id'                   => $this->tenant->id,
            'branch_id'                   => $this->branch->id,
            'profile_code'                => 'SM-TEST-ACT',
            'terminal_identifier'         => 'TERM-ACT-01',
            'status'                      => 'active',
            'offline_sales_enabled'       => true,
            'offline_sequence_prefix'     => 'OFF-ACT-',
            'offline_sequence_next_value' => 1,
            'activation_status'           => SalesMachineProfile::STATUS_PENDING_ACTIVATION,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->admin->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->admin->assignToBranch($this->branch);

        $this->cashier = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branch);
    }

    /** @test */
    public function test_admin_can_generate_activation_code(): void
    {
        $this->actingAs($this->admin);

        $response = $this->post("/admin/sales-machine-profiles/{$this->profile->id}/activation-code");

        $response->assertStatus(302);
        $response->assertSessionHas('activation_code_raw');

        $rawCode = session('activation_code_raw');
        $this->assertSame(8, strlen($rawCode));

        $this->profile->refresh();
        $this->assertSame(hash('sha256', $rawCode), $this->profile->activation_token_hash);
        $this->assertNotNull($this->profile->activation_token_expires_at);
        $this->assertSame(SalesMachineProfile::STATUS_PENDING_ACTIVATION, $this->profile->activation_status);

        // Verify audit log entry
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action'    => 'terminal_activation_code_generated',
            'auditable_type' => SalesMachineProfile::class,
            'auditable_id'   => $this->profile->id,
        ]);
        $this->assertFalse(
            AuditLog::where('action', 'terminal_activation_code_generated')
                ->where('description', 'like', "%{$rawCode}%")
                ->exists()
        );

        $this->get(route('admin.sales-machine-profiles.index'))
            ->assertInertia(fn ($page) => $page
                ->where('flash.activation_code_raw', $rawCode)
            );
    }

    /** @test */
    public function test_admin_can_revoke_activation(): void
    {
        $this->profile->update([
            'activation_status'   => SalesMachineProfile::STATUS_ACTIVE,
            'activated_device_id' => 'device-123',
        ]);

        $this->actingAs($this->admin);

        $response = $this->post("/admin/sales-machine-profiles/{$this->profile->id}/revoke-activation");

        $response->assertStatus(302);
        $this->profile->refresh();

        $this->assertSame(SalesMachineProfile::STATUS_REVOKED, $this->profile->activation_status);
        $this->assertNull($this->profile->activated_device_id);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action'    => 'terminal_activation_revoked',
        ]);
    }

    /** @test */
    public function test_terminal_can_activate_successfully(): void
    {
        // 1. Generate code first
        $rawCode = 'ACT-CODE-99';
        $this->profile->update([
            'activation_token_hash'       => hash('sha256', $rawCode),
            'activation_token_expires_at' => now()->addMinutes(30),
            'activation_status'           => SalesMachineProfile::STATUS_PENDING_ACTIVATION,
        ]);

        // Activation is intentionally public and starts without tenant context.
        app(TenantContext::class)->clear();
        app(\App\Services\BranchContext::class)->clear();

        $response = $this->postJson('/api/pos/activate', [
            'activation_code' => $rawCode,
            'device_id'       => 'test-hardware-uuid-111',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'terminal' => [
                'sales_machine_profile_id',
                'tenant_id',
                'branch_id',
                'profile_code',
                'terminal_identifier',
            ],
            'bootstrap_payload' => [
                'config_snapshot',
                'config_snapshot_hash',
                'catalog_version_hash',
                'terminal_policy_version_hash',
            ],
        ]);
        $response->assertJsonMissingPath('terminal_auth_token');

        app(TenantContext::class)->setTenant($this->tenant);
        app(\App\Services\BranchContext::class)->setBranch($this->branch);
        $this->profile->refresh();
        $this->assertSame(SalesMachineProfile::STATUS_ACTIVE, $this->profile->activation_status);
        $this->assertSame('test-hardware-uuid-111', $this->profile->activated_device_id);
        $this->assertNotNull($this->profile->activated_at);
        $this->assertNull($this->profile->activation_token_hash);
    }

    /** @test */
    public function test_cannot_activate_with_expired_code(): void
    {
        $rawCode = 'EXPIRED1';
        $this->profile->update([
            'activation_token_hash'       => hash('sha256', $rawCode),
            'activation_token_expires_at' => now()->subMinute(),
            'activation_status'           => SalesMachineProfile::STATUS_PENDING_ACTIVATION,
        ]);

        $response = $this->postJson('/api/pos/activate', [
            'activation_code' => $rawCode,
            'device_id'       => 'device-id',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid, expired, or unavailable activation code.',
        ]);
    }

    /** @test */
    public function test_cannot_activate_suspended_terminal(): void
    {
        $rawCode = 'SUSPENDED';
        $this->profile->update([
            'activation_token_hash'       => hash('sha256', $rawCode),
            'activation_token_expires_at' => now()->addMinutes(30),
            'activation_status'           => SalesMachineProfile::STATUS_SUSPENDED,
        ]);

        $response = $this->postJson('/api/pos/activate', [
            'activation_code' => $rawCode,
            'device_id'       => 'device-id',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid, expired, or unavailable activation code.',
        ]);
    }

    /** @test */
    public function test_cannot_activate_revoked_terminal_or_disclose_configuration(): void
    {
        $rawCode = 'REVOKED1';
        $this->profile->update([
            'activation_token_hash' => hash('sha256', $rawCode),
            'activation_token_expires_at' => now()->addMinutes(30),
            'activation_status' => SalesMachineProfile::STATUS_REVOKED,
        ]);

        $this->postJson('/api/pos/activate', [
            'activation_code' => $rawCode,
            'device_id' => 'device-id',
        ])->assertStatus(422)
            ->assertJsonMissingPath('terminal')
            ->assertJsonMissingPath('bootstrap_payload');
    }

    /** @test */
    public function test_activation_code_is_consumed_once(): void
    {
        $rawCode = 'ONETIME1';
        $this->profile->update([
            'activation_token_hash' => hash('sha256', $rawCode),
            'activation_token_expires_at' => now()->addMinutes(30),
            'activation_status' => SalesMachineProfile::STATUS_PENDING_ACTIVATION,
        ]);

        app(TenantContext::class)->clear();
        app(\App\Services\BranchContext::class)->clear();

        $payload = ['activation_code' => $rawCode, 'device_id' => 'browser-install-one'];
        $this->postJson('/api/pos/activate', $payload)->assertOk();
        $this->postJson('/api/pos/activate', $payload)->assertStatus(422);
    }

    /** @test */
    public function test_middleware_blocks_inactive_terminal_requests(): void
    {
        // Enforce binding for this test case
        config(['app.enforce_terminal_binding' => true]);

        // Terminal is pending_activation
        $this->profile->update([
            'activation_status' => SalesMachineProfile::STATUS_PENDING_ACTIVATION,
        ]);

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->postJson('/api/pos/heartbeat', [
                'app_version' => '1.0.0',
                'queue_count' => 0,
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'code'    => 'TERMINAL_CONTEXT_INVALID',
            'message' => 'Terminal activation status is pending_activation.',
        ]);
    }

    /** @test */
    public function test_middleware_blocks_device_id_mismatch(): void
    {
        config(['app.enforce_terminal_binding' => true]);

        $this->profile->update([
            'activation_status'   => SalesMachineProfile::STATUS_ACTIVE,
            'activated_device_id' => 'correct-uuid-999',
        ]);

        // Request with mismatched Device ID header
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->withHeader('X-Device-ID', 'mismatched-uuid')
            ->postJson('/api/pos/heartbeat', [
                'app_version' => '1.0.0',
                'queue_count' => 0,
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'code'    => 'TERMINAL_CONTEXT_INVALID',
            'message' => 'Terminal device ID mismatch.',
        ]);
    }

    /** @test */
    public function test_bound_terminal_downloads_its_exact_protected_bootstrap(): void
    {
        config(['app.enforce_terminal_binding' => true]);
        $this->profile->update([
            'activation_status' => SalesMachineProfile::STATUS_ACTIVE,
            'activated_device_id' => 'bound-browser-install',
        ]);

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->withHeader('X-Device-ID', 'bound-browser-install')
            ->getJson(route('pos.bootstrap-cache'));

        $response->assertOk()
            ->assertJsonPath('machine_profile_context.id', $this->profile->id)
            ->assertJsonPath('config_snapshot.sales_machine_profile_id', $this->profile->id);
    }

    /** @test */
    public function test_revoked_or_mismatched_terminal_cannot_download_protected_bootstrap(): void
    {
        config(['app.enforce_terminal_binding' => true]);
        $this->profile->update([
            'activation_status' => SalesMachineProfile::STATUS_ACTIVE,
            'activated_device_id' => 'bound-browser-install',
        ]);

        $request = fn (string $deviceId) => $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->withHeader('X-Device-ID', $deviceId)
            ->getJson(route('pos.bootstrap-cache'));

        $request('wrong-browser-install')->assertForbidden()
            ->assertJsonPath('code', 'TERMINAL_CONTEXT_INVALID');

        $this->profile->update(['activation_status' => SalesMachineProfile::STATUS_REVOKED]);
        $request('bound-browser-install')->assertForbidden()
            ->assertJsonPath('code', 'TERMINAL_CONTEXT_INVALID');
    }

    /** @test */
    public function test_generation_and_revocation_require_permission_and_authorized_branch(): void
    {
        $this->actingAs($this->cashier)
            ->post(route('admin.sales-machine-profiles.activation-code', $this->profile->id))
            ->assertForbidden();

        $otherBranch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $manager->assignToBranch($otherBranch);

        $this->actingAs($manager)
            ->post(route('admin.sales-machine-profiles.activation-code', $this->profile->id))
            ->assertForbidden();
        $this->post(route('admin.sales-machine-profiles.revoke-activation', $this->profile->id))
            ->assertForbidden();
    }

    /** @test */
    public function test_public_activation_endpoint_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
                ->postJson('/api/pos/activate', [
                    'activation_code' => 'INVALID1',
                    'device_id' => 'rate-limit-browser',
                ])
                ->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->postJson('/api/pos/activate', [
                'activation_code' => 'INVALID1',
                'device_id' => 'rate-limit-browser',
            ])
            ->assertTooManyRequests();
    }

    /** @test */
    public function test_admin_can_view_sales_machine_profiles_index(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('admin.sales-machine-profiles.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/SalesMachineProfiles/Index')
            ->has('profiles.data', 1)
            ->where('profiles.data.0.id', $this->profile->id)
        );
    }

    /** @test */
    public function test_admin_can_edit_sales_machine_profile_settings(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('admin.sales-machine-profiles.edit', $this->profile->id));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/SalesMachineProfiles/Edit')
            ->where('profile.id', $this->profile->id)
            ->has('offlineStatus')
        );
    }

    /** @test */
    public function test_admin_can_update_sales_machine_profile_settings(): void
    {
        $this->actingAs($this->admin);

        $response = $this->put(route('admin.sales-machine-profiles.update', $this->profile->id), [
            'offline_sales_enabled' => false,
            'offline_sequence_prefix' => 'NEW-PRE-',
            'offline_sequence_next_value' => 10,
            'offline_sequence_status' => 'suspended',
        ]);

        $response->assertRedirect(route('admin.sales-machine-profiles.index'));

        $this->profile->refresh();
        $this->assertFalse($this->profile->offline_sales_enabled);
        $this->assertSame('NEW-PRE-', $this->profile->offline_sequence_prefix);
        $this->assertSame(10, $this->profile->offline_sequence_next_value);
        $this->assertSame('suspended', $this->profile->offline_sequence_status);
    }

    /** @test */
    public function test_admin_cannot_edit_sequence_if_queue_count_is_greater_than_zero_without_override(): void
    {
        $this->actingAs($this->admin);

        // Record a heartbeat with queue count > 0
        \App\Models\TerminalConfigHeartbeat::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'app_version' => '1.0.0',
            'device_id' => 'dev-uuid-1',
            'queue_count' => 5,
            'connection_state' => 'online',
            'reported_at' => now(),
        ]);

        // Attempting to change prefix
        $response = $this->put(route('admin.sales-machine-profiles.update', $this->profile->id), [
            'offline_sequence_prefix' => 'NEW-PRE-',
        ]);

        $response->assertSessionHasErrors(['offline_sequence_prefix']);

        // Attempting to change next value
        $response = $this->put(route('admin.sales-machine-profiles.update', $this->profile->id), [
            'offline_sequence_next_value' => 20,
        ]);

        $response->assertSessionHasErrors(['offline_sequence_next_value']);
    }

    /** @test */
    public function test_admin_can_edit_sequence_if_queue_count_is_greater_than_zero_with_override(): void
    {
        $this->actingAs($this->admin);

        // Record a heartbeat with queue count > 0
        \App\Models\TerminalConfigHeartbeat::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'app_version' => '1.0.0',
            'device_id' => 'dev-uuid-1',
            'queue_count' => 5,
            'connection_state' => 'online',
            'reported_at' => now(),
        ]);

        // Change next value WITH admin override
        $response = $this->put(route('admin.sales-machine-profiles.update', $this->profile->id), [
            'offline_sequence_next_value' => 20,
            'admin_override' => true,
        ]);

        $response->assertRedirect(route('admin.sales-machine-profiles.index'));
        $this->profile->refresh();
        $this->assertSame(20, $this->profile->offline_sequence_next_value);
    }
}
