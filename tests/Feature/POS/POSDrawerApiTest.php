<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\Shift\ShiftService;
use App\Services\Shift\CashDropService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class POSDrawerApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected User $manager;
    protected ShiftService $shiftService;
    protected CashDropService $cashDropService;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active', 'default_cash_drawer_limit' => '5000.0000']);
        app(TenantContext::class)->setTenant($this->tenant);

        // Seed RBAC permissions and roles
        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'cash_drawer_limit' => '4000.0000', // Override default
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $this->cashier->assignToBranch($this->branch);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->first());

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'manager@test.com',
            'password' => Hash::make('manager123'),
            'status' => 'active',
        ]);
        $this->manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $this->shiftService = app(ShiftService::class);
        $this->cashDropService = app(CashDropService::class);
    }

    private function getWithContext(string $route, array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route($route, $params));
    }

    private function postWithContext(string $route, array $payload, array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route($route, $params), $payload);
    }

    public function test_drawer_status_endpoint_returns_correct_calculations(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '3500.0000',
            $this->cashier
        );

        $response = $this->getWithContext('pos.drawer-status');

        $response->assertStatus(200)
            ->assertJson([
                'active_shift' => true,
                'shift_id' => $shift->id,
                'current_drawer_cash' => '3500.0000',
                'cash_drawer_limit' => '4000.0000',
                'is_warning_threshold_exceeded' => false,
                'pending_drop_recommendation' => '0.0000',
            ]);

        // Now, record a cash event/sale payment to push it over the 4000.0000 threshold
        $this->shiftService->recordDrawerEvent(
            $shift,
            $this->cashier,
            'cash_top_up',
            '1000.0000',
            'topup',
            'Top up to exceed limit'
        );

        $response = $this->getWithContext('pos.drawer-status');

        $response->assertStatus(200)
            ->assertJson([
                'active_shift' => true,
                'shift_id' => $shift->id,
                'current_drawer_cash' => '4500.0000',
                'cash_drawer_limit' => '4000.0000',
                'is_warning_threshold_exceeded' => true,
                'pending_drop_recommendation' => '500.0000',
            ]);
    }

    public function test_drawer_status_when_no_active_shift(): void
    {
        // No shift is opened
        $response = $this->getWithContext('pos.drawer-status');

        $response->assertStatus(404)
            ->assertJson([
                'active_shift' => false,
                'message' => 'No active shift found.'
            ]);
    }

    public function test_spot_audit_endpoint_creates_spot_audit_on_success(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $payload = [
            'manager_email' => 'manager@test.com',
            'manager_password' => 'manager123',
            'counted_cash_amount' => 1000,
            'denominations' => [
                '1000' => 1
            ],
            'audit_notes' => 'API spot audit note'
        ];

        $response = $this->postWithContext('pos.shifts.spot-audits', $payload, ['shift' => $shift->id]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Spot audit logged successfully.',
            ])
            ->assertJsonStructure(['audit' => ['id', 'variance_amount', 'expected_cash_amount']]);

        $this->assertDatabaseHas('spot_audits', [
            'shift_id' => $shift->id,
            'manager_id' => $this->manager->id,
            'expected_cash_amount' => '1000.0000',
            'counted_cash_amount' => '1000.0000',
            'audit_notes' => 'API spot audit note',
        ]);
    }

    public function test_spot_audit_endpoint_returns_validation_errors(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        $payload = [
            'manager_email' => 'wrong@test.com',
            'manager_password' => 'wrong',
            'counted_cash_amount' => 1000,
            'denominations' => [
                '1000' => 1
            ]
        ];

        $response = $this->postWithContext('pos.shifts.spot-audits', $payload, ['shift' => $shift->id]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid manager credentials.'
            ]);
    }

    public function test_drawer_events_cash_drop_below_threshold_does_not_require_manager(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        // Branch threshold is 4000. Amount is 500 (below threshold)
        $payload = [
            'event_type' => 'cash_drop',
            'amount' => 500,
            'reason_code' => 'mid_day_drop',
            'reason_notes' => 'Routine cash drop',
        ];

        $response = $this->postWithContext('pos.shifts.drawer-events', $payload, ['shift' => $shift->id]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Drawer event logged successfully.'
            ]);

        $this->assertDatabaseHas('cash_drawer_events', [
            'shift_id' => $shift->id,
            'event_type' => 'cash_drop',
            'amount' => '500.0000',
            'created_by' => $this->cashier->id, // Actor is the cashier
        ]);
    }

    public function test_drawer_events_cash_drop_above_threshold_requires_manager_verification(): void
    {
        $shift = $this->shiftService->openShift(
            $this->cashier,
            $this->branch,
            '1000.0000',
            $this->cashier
        );

        // Branch threshold is 4000. Drop amount is 4500 (high-value drop)
        $payload = [
            'event_type' => 'cash_drop',
            'amount' => 4500,
            'reason_code' => 'excess_cash_drop',
            'reason_notes' => 'High value drop',
        ];

        // First attempt without manager details should fail
        $response = $this->postWithContext('pos.shifts.drawer-events', $payload, ['shift' => $shift->id]);
        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized: high-value cash drop requires manager approval.'
            ]);

        // Second attempt with manager details should succeed
        $payload['manager_email'] = 'manager@test.com';
        $payload['manager_password'] = 'manager123';

        $response = $this->postWithContext('pos.shifts.drawer-events', $payload, ['shift' => $shift->id]);
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Drawer event logged successfully.'
            ]);

        $this->assertDatabaseHas('cash_drawer_events', [
            'shift_id' => $shift->id,
            'event_type' => 'cash_drop',
            'amount' => '4500.0000',
            'created_by' => $this->manager->id, // Actor is the verified manager
        ]);
    }

    public function test_drawer_events_cash_drop_self_approval_blocked(): void
    {
        // Shift cashier attempts to approve their own high-value drop
        // Create cashier manager user
        $cashierManager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'cashier_mgr@test.com',
            'password' => Hash::make('manager123'),
            'status' => 'active',
        ]);
        $cashierManager->assignToBranch($this->branch);
        // Assign roles: cashier manager has both Cashier and Branch Manager
        $cashierManager->assignRole(Role::where('name', 'Cashier')->first());
        $cashierManager->assignRole(Role::where('name', 'Branch Manager')->first());

        // Open shift for cashierManager
        $shift = $this->shiftService->openShift(
            $cashierManager,
            $this->branch,
            '1000.0000',
            $cashierManager
        );

        $payload = [
            'event_type' => 'cash_drop',
            'amount' => 4500,
            'reason_code' => 'excess_cash_drop',
            'reason_notes' => 'High value drop self approval',
            'manager_email' => 'cashier_mgr@test.com',
            'manager_password' => 'manager123',
        ];

        // Should fail because cashierManager is the shift owner (cashier)
        $response = $this->actingAs($cashierManager)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.shifts.drawer-events', ['shift' => $shift->id]), $payload);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Security Block: Cashiers cannot approve their own high-value cash drop.'
            ]);
    }
}
