<?php

namespace Tests\Feature\SystemAdmin;

use App\Models\Tenant;
use App\Models\TenantReadinessSignOff;
use App\Models\User;
use App\Services\SystemAdminTenantUrgencyService;
use App\Services\TenantReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SystemAdminTenantUrgencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SystemAdminTenantUrgencyService $urgencyService;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
        ]);
        
        // Force > 30 days for testing stagnation logic
        $this->tenant->created_at = now()->subDays(40);
        $this->tenant->saveQuietly();
        $this->tenant->refresh();
        
        $this->urgencyService = app(SystemAdminTenantUrgencyService::class);
    }

    public function test_evaluates_tenant_as_low_urgency_when_fully_ready(): void
    {
        $this->mock(TenantReadinessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getReadinessSummary')->with($this->tenant)->andReturn([
                'readiness_state' => 'ready_for_operations',
                'blockers' => [],
                'pending_actions' => [],
            ]);
        });

        $urgencyService = app(SystemAdminTenantUrgencyService::class);
        $result = $urgencyService->evaluate($this->tenant);

        $this->assertEquals('low', $result['urgency_band']);
        $this->assertContains('Tenant is fully ready for operations with no blockers or pending actions.', $result['reasons']);
        $this->assertEquals(0, $result['signals']['blocker_count']);
        $this->assertEquals(0, $result['signals']['pending_action_count']);
        $this->assertEquals('ready_for_operations', $result['signals']['readiness_state']);
    }

    public function test_evaluates_tenant_as_caution_when_ready_for_pilot(): void
    {
        $this->mock(TenantReadinessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getReadinessSummary')->with($this->tenant)->andReturn([
                'readiness_state' => 'ready_for_pilot',
                'blockers' => [],
                'pending_actions' => [
                    ['code' => 'missing_feature_gate', 'message' => 'Missing flag']
                ],
            ]);
        });

        // Add an old sign-off to trigger the >14 days reason
        $admin = User::factory()->platformSupport()->create();
        $signOff = TenantReadinessSignOff::create([
            'tenant_id' => $this->tenant->id,
            'signed_off_by' => $admin->id,
            'signed_off_state' => 'ready_for_pilot',
            'readiness_state_calculated' => 'ready_for_pilot',
            'readiness_snapshot' => [],
        ]);
        $signOff->created_at = now()->subDays(20);
        $signOff->saveQuietly();

        $urgencyService = app(SystemAdminTenantUrgencyService::class);
        $result = $urgencyService->evaluate($this->tenant);

        $this->assertEquals('caution', $result['urgency_band']);
        $this->assertContains('Tenant is currently ready for pilot and requires monitoring.', $result['reasons']);
        $this->assertContains('Tenant has 1 pending action(s) to address.', $result['reasons']);
        $this->assertContains('More than 14 days have passed since the last readiness sign-off.', $result['reasons']);
        
        $this->assertEquals(0, $result['signals']['blocker_count']);
        $this->assertEquals(1, $result['signals']['pending_action_count']);
        $this->assertGreaterThanOrEqual(20, $result['signals']['days_since_last_sign_off']);
    }

    public function test_evaluates_tenant_as_caution_with_pending_actions_but_not_pilot(): void
    {
        $this->mock(TenantReadinessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getReadinessSummary')->with($this->tenant)->andReturn([
                'readiness_state' => 'ready_for_operations', // Unlikely state, but checking the logic
                'blockers' => [],
                'pending_actions' => [
                    ['code' => 'missing_admin', 'message' => 'Branch missing admin']
                ],
            ]);
        });

        $urgencyService = app(SystemAdminTenantUrgencyService::class);
        $result = $urgencyService->evaluate($this->tenant);

        $this->assertEquals('caution', $result['urgency_band']);
        $this->assertContains('Tenant has 1 pending action(s) to address.', $result['reasons']);
    }

    public function test_evaluates_tenant_as_critical_when_blocked_and_has_blockers(): void
    {
        $this->mock(TenantReadinessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getReadinessSummary')->with($this->tenant)->andReturn([
                'readiness_state' => 'blocked',
                'blockers' => [
                    ['code' => 'missing_tenant_profile', 'message' => 'No profile']
                ],
                'pending_actions' => [],
            ]);
        });

        $urgencyService = app(SystemAdminTenantUrgencyService::class);
        $result = $urgencyService->evaluate($this->tenant);

        $this->assertEquals('critical', $result['urgency_band']);
        $this->assertContains('Tenant is in a blocked readiness state.', $result['reasons']);
        $this->assertContains('Tenant has 1 critical compliance or setup blocker(s).', $result['reasons']);
        $this->assertContains('Tenant has remained blocked for over 30 days since creation.', $result['reasons']);
        
        $this->assertEquals(1, $result['signals']['blocker_count']);
        $this->assertEquals(0, $result['signals']['pending_action_count']);
    }

    public function test_urgency_service_does_not_mutate_tenant_data(): void
    {
        $originalUpdatedAt = clone $this->tenant->updated_at;

        $this->mock(TenantReadinessService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getReadinessSummary')->with($this->tenant)->andReturn([
                'readiness_state' => 'blocked',
                'blockers' => [
                    ['code' => 'test', 'message' => 'test']
                ],
                'pending_actions' => [],
            ]);
        });

        $urgencyService = app(SystemAdminTenantUrgencyService::class);
        $urgencyService->evaluate($this->tenant);
        
        $this->tenant->refresh();
        
        $this->assertEquals($originalUpdatedAt->timestamp, $this->tenant->updated_at->timestamp);
        
        // Ensure no score table got filled. The only way to persist would be to create a model.
        // We assert no exceptions occur when we just read it.
    }
}
