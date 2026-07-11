<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\TerminalConfigHeartbeat;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected User $owner;
    protected User $nonAuthorizedUser;
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
            'profile_code'                => 'SM-001',
            'terminal_identifier'         => 'TERM-99',
            'status'                      => 'active',
            'offline_sales_enabled'       => true,
            'offline_sequence_prefix'     => 'OFF-99-',
            'offline_sequence_next_value' => 1,
        ]);

        // Create standard roles & users
        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branch);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branch);

        $this->nonAuthorizedUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->nonAuthorizedUser->assignToBranch($this->branch);
    }

    /** @test */
    public function test_can_submit_valid_heartbeat(): void
    {
        $payload = [
            'app_version' => 'v1.4.2',
            'device_id' => 'device-uuid-999',
            'config_snapshot' => [
                'layout_version_hash' => 'layout-hash-val',
                'catalog_version_hash' => 'catalog-hash-val',
                'tax_configuration_version_hash' => 'tax-hash-val',
            ],
            'last_snapshot_downloaded_at' => now()->toIso8601String(),
            'last_successful_sync_at' => now()->toIso8601String(),
            'queue_count' => 3,
            'connection_state' => 'online',
            'reported_at' => now()->toIso8601String(),
        ];

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->postJson('/api/pos/heartbeat', $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseHas('terminal_config_heartbeats', [
            'sales_machine_profile_id' => $this->profile->id,
            'app_version' => 'v1.4.2',
            'device_id' => 'device-uuid-999',
            'queue_count' => 3,
            'connection_state' => 'online',
        ]);
    }

    /** @test */
    public function test_heartbeat_upsert_behavior(): void
    {
        $payload1 = [
            'app_version' => 'v1.0.0',
            'queue_count' => 5,
        ];

        $response1 = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->postJson('/api/pos/heartbeat', $payload1);

        $response1->assertStatus(200);

        $this->assertEquals(1, TerminalConfigHeartbeat::count());

        $payload2 = [
            'app_version' => 'v1.1.0', // updated app version
            'queue_count' => 2, // updated queue
        ];

        $response2 = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->postJson('/api/pos/heartbeat', $payload2);

        $response2->assertStatus(200);

        // Assert single row exists (upsert occurred)
        $this->assertEquals(1, TerminalConfigHeartbeat::count());

        $heartbeat = TerminalConfigHeartbeat::first();
        $this->assertSame('v1.1.0', $heartbeat->app_version);
        $this->assertSame(2, $heartbeat->queue_count);
    }

    /** @test */
    public function test_heartbeat_data_integration_in_monitor_dashboard(): void
    {
        $driftService = app(\App\Services\POS\OfflineReadiness\TerminalConfigDriftService::class);
        $serverSnapshot = $driftService->buildServerSnapshot($this->profile);

        // Heartbeat with mismatched catalog hash (config drift)
        $clientSnapshot = $serverSnapshot;
        $clientSnapshot['catalog'] = 'mismatched-catalog-hash';

        TerminalConfigHeartbeat::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'app_version' => 'v1.2.0',
            'device_id' => 'device-123',
            'config_snapshot' => [
                'layout_version_hash' => $clientSnapshot['layout'],
                'catalog_version_hash' => $clientSnapshot['catalog'],
                'tax_configuration_version_hash' => $clientSnapshot['tax'],
                'discount_rules_version_hash' => $clientSnapshot['discounts'],
                'payment_methods_version_hash' => $clientSnapshot['payment_methods'],
                'terminal_policy_version_hash' => $clientSnapshot['terminal_policy'],
                'printer_profile_version_hash' => $clientSnapshot['printer_profile'],
            ],
            'queue_count' => 1,
            'connection_state' => 'sync_lagging',
            'reported_at' => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $this->assertNotNull($terminal);
        $this->assertSame('drifted', $terminal['config_audit']['config_status']);
        $this->assertTrue($terminal['config_audit']['has_config_drift']);

        // Assert heartbeat metadata is populated
        $this->assertNotNull($terminal['heartbeat']);
        $this->assertSame('v1.2.0', $terminal['heartbeat']['app_version']);
        $this->assertSame('device-123', $terminal['heartbeat']['device_id']);
        $this->assertSame(1, $terminal['heartbeat']['queue_count']);
        $this->assertSame('sync_lagging', $terminal['heartbeat']['connection_state']);
    }

    /** @test */
    public function test_heartbeat_dashboard_falls_back_to_offline_imports(): void
    {
        $driftService = app(\App\Services\POS\OfflineReadiness\TerminalConfigDriftService::class);
        $serverSnapshot = $driftService->buildServerSnapshot($this->profile);

        // No heartbeat, but latest offline import exists with mismatched tax hash
        $clientSnapshot = $serverSnapshot;
        $clientSnapshot['tax'] = 'mismatched-tax-hash';

        $batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'REF-99',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
        ]);

        OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $batch->id,
            'offline_sequence_number'  => 'OFF-99-1',
            'payload_hash'             => 'hash-99',
            'raw_payload'              => [
                'config_snapshot' => [
                    'layout_version_hash' => $clientSnapshot['layout'],
                    'catalog_version_hash' => $clientSnapshot['catalog'],
                    'tax_configuration_version_hash' => $clientSnapshot['tax'],
                    'discount_rules_version_hash' => $clientSnapshot['discounts'],
                    'payment_methods_version_hash' => $clientSnapshot['payment_methods'],
                    'terminal_policy_version_hash' => $clientSnapshot['terminal_policy'],
                    'printer_profile_version_hash' => $clientSnapshot['printer_profile'],
                ]
            ],
            'status'                   => OfflineSalesImport::STATUS_POSTED,
            'submitted_at'             => now(),
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson('/api/admin/terminal-sync-monitor/data');

        $response->assertStatus(200);
        $terminal = collect($response->json('terminals'))->firstWhere('id', $this->profile->id);

        $this->assertNull($terminal['heartbeat']);
        $this->assertSame('drifted', $terminal['config_audit']['config_status']);
        $this->assertSame(['tax'], $terminal['config_audit']['drifted_components']);
    }

    /** @test */
    public function test_non_cashier_cannot_submit_heartbeat(): void
    {
        $response = $this->actingAs($this->nonAuthorizedUser, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Terminal-ID', $this->profile->id)
            ->postJson('/api/pos/heartbeat', []);

        $response->assertStatus(403);
    }
}
