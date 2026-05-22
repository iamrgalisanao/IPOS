<?php

namespace Tests\Feature\SystemAdmin;

use App\Models\Tenant;
use App\Models\TenantReadinessSignOff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemAdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $systemAdmin;
    protected User $tenantUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemAdmin = User::factory()->platformSupport()->create();
        
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(\App\Services\TenantContext::class)->setTenant($tenant);
        
        $this->tenantUser = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_system_admin_can_access_dashboard_summary_api(): void
    {
        $response = $this->actingAs($this->systemAdmin, 'sanctum')
            ->getJson(route('api.system-admin.dashboard.summary'));

        $response->assertOk();
        
        $response->assertJsonStructure([
            'readiness_counts' => [
                'ready_for_operations',
                'ready_for_pilot',
                'blocked',
            ],
            'compliance_counts' => [
                'tenants_missing_profile',
                'tenants_missing_plan',
                'tenants_mismatched_features',
                'tenants_no_branches',
                'branches_inactive',
                'branches_missing_admin',
                'branches_missing_profile',
                'branches_incomplete_compliance',
            ],
            'pilot_counts' => [
                'branches_ready',
                'branches_pending',
                'branches_blocked',
            ],
            'urgency_counts' => [
                'low',
                'caution',
                'critical',
            ],
            'tenant_urgency' => [
                '*' => [
                    'tenant_id',
                    'tenant_name',
                    'urgency_band',
                    'score',
                    'reasons',
                    'signals' => [
                        'readiness_state',
                        'blocker_count',
                        'pending_action_count',
                        'days_since_creation',
                        'days_since_last_sign_off',
                    ]
                ]
            ],
            'recent_sign_offs' => [],
        ]);

        $response->assertJsonStructure([
            'tenant_urgency' => [
                '*' => [
                    'reasons',
                    'signals' => [
                        'readiness_state',
                        'blocker_count',
                        'pending_action_count',
                        'days_since_creation',
                        'days_since_last_sign_off',
                    ],
                ],
            ],
        ]);
    }

    public function test_dashboard_summary_includes_urgency_counts_and_tenant_urgency_payload(): void
    {
        $response = $this->actingAs($this->systemAdmin, 'sanctum')
            ->getJson(route('api.system-admin.dashboard.summary'));

        $response->assertOk()
            ->assertJsonPath('urgency_counts.low', 0)
            ->assertJsonPath('urgency_counts.caution', 0)
            ->assertJsonPath('urgency_counts.critical', 1)
            ->assertJsonCount(1, 'tenant_urgency')
            ->assertJsonPath('tenant_urgency.0.urgency_band', 'critical')
            ->assertJsonPath('tenant_urgency.0.signals.readiness_state', 'blocked');

        $reasons = $response->json('tenant_urgency.0.reasons');
        $this->assertIsArray($reasons);
        $this->assertNotEmpty($reasons);
    }

    public function test_dashboard_summary_includes_days_since_last_sign_off_signal_when_sign_off_exists(): void
    {
        Carbon::setTestNow('2026-05-21 12:00:00');

        try {
            TenantReadinessSignOff::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenantUser->tenant_id,
                'signed_off_by' => $this->systemAdmin->id,
                'signed_off_state' => 'blocked',
                'readiness_state_calculated' => 'blocked',
                'notes' => 'Advisory checkpoint',
                'readiness_snapshot' => [
                    'tenant_id' => $this->tenantUser->tenant_id,
                    'readiness_state' => 'blocked',
                    'checks' => [],
                    'blockers' => ['branch_missing'],
                    'pending_actions' => ['Create at least one branch.'],
                ],
                'created_at' => now()->subDays(5),
            ]);

            $response = $this->actingAs($this->systemAdmin, 'sanctum')
                ->getJson(route('api.system-admin.dashboard.summary'));

            $response->assertOk()
                ->assertJsonPath('tenant_urgency.0.signals.days_since_last_sign_off', 5);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_summary_api_is_read_only_and_does_not_mutate_sign_off_records(): void
    {
        $before = TenantReadinessSignOff::withoutGlobalScopes()->count();

        $this->actingAs($this->systemAdmin, 'sanctum')
            ->getJson(route('api.system-admin.dashboard.summary'))
            ->assertOk();

        $after = TenantReadinessSignOff::withoutGlobalScopes()->count();
        $this->assertSame($before, $after);
    }

    public function test_tenant_user_cannot_access_dashboard_summary_api(): void
    {
        $response = $this->actingAs($this->tenantUser, 'sanctum')
            ->getJson(route('api.system-admin.dashboard.summary'));

        // Should be forbidden because of 'platform.admin' middleware
        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_access_dashboard_summary_api(): void
    {
        $response = $this->getJson(route('api.system-admin.dashboard.summary'));

        $response->assertUnauthorized();
    }
}
