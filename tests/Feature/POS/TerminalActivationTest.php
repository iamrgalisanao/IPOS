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

        // 2. Post activation payload
        $response = $this->postJson('/api/pos/activate', [
            'activation_code' => $rawCode,
            'device_id'       => 'test-hardware-uuid-111',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'terminal_auth_token',
            'sales_machine_profile_id',
            'tenant_id',
            'branch_id',
            'profile_code',
            'terminal_identifier',
            'config_snapshot',
            'config_snapshot_hashes' => [
                'catalog',
                'tax',
                'layout',
                'discount_rules',
                'payment_methods',
                'terminal_policy',
                'printer_profile',
                'config_snapshot',
            ],
            'heartbeat_schedule',
            'offline_policy',
        ]);

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
            'message' => 'Invalid or expired activation code.',
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

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Terminal is suspended and cannot be activated.',
        ]);
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
}
