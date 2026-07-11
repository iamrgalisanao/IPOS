<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\SalesMachineProfile;
use App\Models\LocalSyncBroker;
use App\Models\LocalTableLock;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected SalesMachineProfile $masterProfile;
    protected SalesMachineProfile $slaveProfile;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
        $this->cashier->assignToBranch($this->branch);

        $this->masterProfile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'REG-01',
            'terminal_identifier' => 'TERM-01',
            'status' => 'active',
        ]);

        $this->slaveProfile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'REG-02',
            'terminal_identifier' => 'TERM-02',
            'status' => 'active',
        ]);

        $this->actingAs($this->cashier);
    }

    protected function postWithContext(string $route, array $payload = [], array $headers = [])
    {
        $defaultHeaders = [
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
        ];

        return $this->postJson($route, $payload, array_merge($defaultHeaders, $headers));
    }

    protected function getWithContext(string $route, array $payload = [], array $headers = [])
    {
        $defaultHeaders = [
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
        ];

        return $this->getJson($route . '?' . http_build_query($payload), array_merge($defaultHeaders, $headers));
    }

    public function test_register_broker_updates_ip_and_port()
    {
        $response = $this->postWithContext(route('pos.local-sync.broker.register'), [
            'sales_machine_profile_id' => $this->masterProfile->id,
            'local_ip_address' => '192.168.1.100',
            'local_port' => 8080,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['local_ip_address' => '192.168.1.100', 'local_port' => 8080]);

        $this->assertDatabaseHas('local_sync_brokers', [
            'master_profile_id' => $this->masterProfile->id,
            'local_ip_address' => '192.168.1.100',
            'local_port' => 8080,
            'status' => 'active'
        ]);
    }

    public function test_discover_broker_returns_active_master_ip()
    {
        // 1. Discover when none exists
        $response1 = $this->getWithContext(route('pos.local-sync.broker.discover'));
        $response1->assertStatus(200);
        $response1->assertJsonPath('success', false);
        $response1->assertJsonPath('code', 'BROKER_NOT_FOUND');

        // 2. Register master
        LocalSyncBroker::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'master_profile_id' => $this->masterProfile->id,
            'local_ip_address' => '192.168.1.100',
            'local_port' => 8080,
            'last_heartbeat_at' => Carbon::now(),
            'status' => 'active',
        ]);

        // 3. Discover active
        $response2 = $this->getWithContext(route('pos.local-sync.broker.discover'));
        $response2->assertStatus(200);
        $response2->assertJsonFragment(['local_ip_address' => '192.168.1.100', 'local_port' => 8080]);
        $response2->assertJsonPath('data.master_profile_id', $this->masterProfile->id);
    }

    public function test_discover_broker_ignores_stale_heartbeats()
    {
        // Register stale broker (heartbeat 10 minutes ago)
        LocalSyncBroker::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'master_profile_id' => $this->masterProfile->id,
            'local_ip_address' => '192.168.1.100',
            'local_port' => 8080,
            'last_heartbeat_at' => Carbon::now()->subMinutes(10),
            'status' => 'active',
        ]);

        $response = $this->getWithContext(route('pos.local-sync.broker.discover'));
        $response->assertStatus(200); // Should treat stale as missing/inactive, without noisy client errors.
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('code', 'BROKER_NOT_FOUND');
    }

    public function test_acquire_table_lock_succeeds_and_prevents_conflict()
    {
        // 1. Lock table by master profile
        $response1 = $this->postWithContext(route('pos.local-sync.table.lock'), [
            'table_id' => 'Table-05',
            'sales_machine_profile_id' => $this->masterProfile->id,
        ]);
        $response1->assertStatus(200);
        $response1->assertJsonFragment(['table_id' => 'Table-05']);

        // 2. Attempt lock table by slave profile (should trigger conflict)
        $response2 = $this->postWithContext(route('pos.local-sync.table.lock'), [
            'table_id' => 'Table-05',
            'sales_machine_profile_id' => $this->slaveProfile->id,
        ]);
        $response2->assertStatus(409);
        $response2->assertJsonFragment(['code' => 'TABLE_LOCKED']);
    }

    public function test_lock_auto_expiry_allows_new_registration_after_fifteen_minutes()
    {
        // Create lock that expired 5 minutes ago
        LocalTableLock::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'table_id' => 'Table-05',
            'locked_by_profile_id' => $this->masterProfile->id,
            'locked_at' => Carbon::now()->subMinutes(20),
            'expires_at' => Carbon::now()->subMinutes(5),
        ]);

        // Attempt lock table by slave profile (should succeed since previous lock expired)
        $response = $this->postWithContext(route('pos.local-sync.table.lock'), [
            'table_id' => 'Table-05',
            'sales_machine_profile_id' => $this->slaveProfile->id,
        ]);
        
        $response->assertStatus(200);
        $this->assertDatabaseHas('local_table_locks', [
            'table_id' => 'Table-05',
            'locked_by_profile_id' => $this->slaveProfile->id,
        ]);
    }

    public function test_release_table_lock_allows_new_claims()
    {
        // 1. Acquire lock
        $this->postWithContext(route('pos.local-sync.table.lock'), [
            'table_id' => 'Table-05',
            'sales_machine_profile_id' => $this->masterProfile->id,
        ])->assertStatus(200);

        // 2. Release lock
        $this->postWithContext(route('pos.local-sync.table.unlock'), [
            'table_id' => 'Table-05',
            'sales_machine_profile_id' => $this->masterProfile->id,
        ])->assertStatus(200);

        // 3. Slave registers lock (should succeed now)
        $this->postWithContext(route('pos.local-sync.table.lock'), [
            'table_id' => 'Table-05',
            'sales_machine_profile_id' => $this->slaveProfile->id,
        ])->assertStatus(200);
    }
}
