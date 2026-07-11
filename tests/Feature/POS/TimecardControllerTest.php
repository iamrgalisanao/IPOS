<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\EmployeeTimecard;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TimecardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected SalesMachineProfile $terminal;

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

        $this->terminal = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'TERM-01',
            'terminal_identifier' => 'TERM-01',
            'status' => 'active'
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
        $this->cashier->assignToBranch($this->branch);
        $this->cashier->setPosPin('4567');
        $this->cashier->save();

        $this->giveUserPermission($this->cashier, 'create_sale');
        $this->giveUserPermission($this->cashier, 'open_shift');

        Cache::flush();
        config(['app.enforce_timecards' => true]);
        $this->actingAs($this->cashier);
    }

    protected function giveUserPermission(User $user, string $permissionName): void
    {
        $permission = \App\Models\Permission::firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'name' => $permissionName
        ]);

        $role = \App\Models\Role::firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'name' => 'Test Role for ' . $permissionName
        ]);

        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    protected function postWithContext(string $route, array $payload = [], array $headers = [])
    {
        $defaultHeaders = [
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $this->terminal->id,
        ];

        return $this->postJson($route, $payload, array_merge($defaultHeaders, $headers));
    }

    protected function getWithContext(string $route, array $payload = [], array $headers = [])
    {
        $defaultHeaders = [
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => $this->terminal->id,
        ];

        return $this->getJson($route . '?' . http_build_query($payload), array_merge($defaultHeaders, $headers));
    }

    public function test_employee_can_clock_in_with_valid_pin()
    {
        $response = $this->postWithContext(route('pos.timecard.toggle'), [
            'pin' => '4567'
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['action' => 'clock_in']);

        $this->assertDatabaseHas('employee_timecards', [
            'user_id' => $this->cashier->id,
            'clocked_out_at' => null
        ]);
    }

    public function test_employee_cannot_clock_in_twice_concurrently()
    {
        // First clock in
        $this->postWithContext(route('pos.timecard.toggle'), ['pin' => '4567'])->assertStatus(200);

        // Try second clock in manually via service to test unique constraint / model validation
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Employee is already clocked in.');

        $service = app(\App\Services\POS\TimecardService::class);
        $service->clockIn($this->tenant->id, $this->branch->id, $this->terminal->id, $this->cashier->id);
    }

    public function test_employee_can_clock_out_with_active_timecard()
    {
        // 1. Clock In
        $this->postWithContext(route('pos.timecard.toggle'), ['pin' => '4567'])->assertStatus(200);

        // 2. Clock Out
        $response = $this->postWithContext(route('pos.timecard.toggle'), [
            'pin' => '4567'
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['action' => 'clock_out']);

        $this->assertDatabaseMissing('employee_timecards', [
            'user_id' => $this->cashier->id,
            'clocked_out_at' => null
        ]);
    }

    public function test_employee_cannot_clock_out_without_active_timecard()
    {
        // Try clocking out immediately
        $service = app(\App\Services\POS\TimecardService::class);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Employee is not clocked in.');

        $service->clockOut($this->tenant->id, $this->branch->id, $this->terminal->id, $this->cashier->id);
    }

    public function test_invalid_pin_fails_with_generic_response()
    {
        $response = $this->postWithContext(route('pos.timecard.toggle'), [
            'pin' => '9999'
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'code' => 'INVALID_PIN',
            'message' => 'Invalid PIN or employee is not allowed to clock in on this terminal.'
        ]);
    }

    public function test_duplicate_pin_in_same_tenant_is_rejected_during_pin_update()
    {
        $otherCashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Proposed PIN collides with another user PIN in the tenant.');

        $otherCashier->setPosPin('4567'); // Same PIN as cashier
    }

    public function test_pin_attempts_are_rate_limited_after_repeated_failures()
    {
        // Submit 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->postWithContext(route('pos.timecard.toggle'), ['pin' => '9999'])->assertStatus(403);
        }

        // 6th attempt should be blocked with rate limit error
        $response = $this->postWithContext(route('pos.timecard.toggle'), ['pin' => '4567']);
        $response->assertStatus(429);
        $response->assertJsonFragment([
            'code' => 'PIN_RATE_LIMITED',
            'message' => 'PIN verification is temporarily unavailable. Please try again later or contact a supervisor.'
        ]);
    }

    public function test_clock_in_derives_branch_terminal_from_session_not_request_body()
    {
        $response = $this->postWithContext(route('pos.timecard.toggle'), [
            'pin' => '4567',
            'branch_id' => 'malicious-branch-uuid', // Should be ignored
            'tenant_id' => 'malicious-tenant-uuid' // Should be ignored
        ], [
            'X-Terminal-ID' => $this->terminal->id
        ]);

        $response->assertStatus(200);

        $timecard = EmployeeTimecard::where('user_id', $this->cashier->id)->first();
        $this->assertEquals($this->branch->id, $timecard->branch_id);
        $this->assertEquals($this->tenant->id, $timecard->tenant_id);
        $this->assertEquals($this->terminal->id, $timecard->terminal_id);
    }

    public function test_cashier_cannot_clock_out_while_drawer_shift_is_open()
    {
        // 1. Clock In
        $this->postWithContext(route('pos.timecard.toggle'), ['pin' => '4567'])->assertStatus(200);

        // 2. Open Cashier shift
        Shift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opening_cash_amount' => 100.00,
            'expected_cash_amount' => 100.00,
            'opened_at' => now(),
        ]);

        // 3. Attempt clock out (should fail)
        $response = $this->postWithContext(route('pos.timecard.toggle'), [
            'pin' => '4567'
        ]);

        $response->assertStatus(409);
        $response->assertJsonFragment([
            'code' => 'OPEN_SHIFT_BLOCKS_CLOCK_OUT',
            'message' => 'Please close your cashier shift before clocking out.'
        ]);
    }

    public function test_lock_screen_toggle_works_without_cashier_session()
    {
        // Logout first
        auth()->logout();

        $response = $this->postWithContext(route('pos.timecard.toggle'), [
            'pin' => '4567'
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['action' => 'clock_in']);
    }

    public function test_lock_screen_toggle_fails_if_terminal_context_is_invalid()
    {
        auth()->logout();

        $response = $this->postJson(route('pos.timecard.toggle'), [
            'pin' => '4567'
        ], [
            'X-Tenant-ID' => $this->tenant->id,
            'X-Branch-ID' => $this->branch->id,
            'X-Terminal-ID' => 'invalid-terminal-uuid'
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'code' => 'TERMINAL_CONTEXT_INVALID',
            'message' => 'Invalid terminal context.'
        ]);
    }

    public function test_opening_shift_blocked_when_authenticated_user_has_no_active_timecard()
    {
        // No active timecard exists
        $response = $this->postWithContext(route('shifts.store'), [
            'opening_cash' => 100.00,
            'opening_denominations' => [
                '100' => 1
            ],
            'notes' => 'test'
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'code' => 'TIMECARD_REQUIRED',
            'message' => 'You must be clocked in before performing this action.'
        ]);
    }

    public function test_checkout_validation_blocked_when_not_clocked_in()
    {
        $response = $this->postWithContext(route('pos.checkout.validate'), [
            'client_request_uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'items' => [],
            'tax_config_hash' => md5('dummy')
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'code' => 'TIMECARD_REQUIRED'
        ]);
    }

    public function test_successful_pin_clears_failed_attempt_counter()
    {
        // 4 failed attempts
        for ($i = 0; $i < 4; $i++) {
            $this->postWithContext(route('pos.timecard.toggle'), ['pin' => '9999'])->assertStatus(403);
        }

        // 1 successful attempt
        $this->postWithContext(route('pos.timecard.toggle'), ['pin' => '4567'])->assertStatus(200);

        // Try failed attempt again — counter should be reset, so 4 more failed attempts shouldn't block
        for ($i = 0; $i < 4; $i++) {
            $this->postWithContext(route('pos.timecard.toggle'), ['pin' => '9999'])->assertStatus(403);
        }

        // Clock out with success PIN
        $response = $this->postWithContext(route('pos.timecard.toggle'), ['pin' => '4567']);
        $response->assertStatus(200);
    }
}
