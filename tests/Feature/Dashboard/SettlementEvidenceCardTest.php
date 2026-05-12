<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SettlementPeriod;
use App\Models\SettlementSnapshot;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettlementEvidenceCardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $owner;
    protected User $managerA;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Branch B']);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user']);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());

        $this->managerA = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user']);
        $this->managerA->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->managerA->assignToBranch($this->branchA);
    }

    public function test_owner_sees_latest_locked_tenant_settlement_evidence(): void
    {
        $period = SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'period_start_at' => now()->subDays(2)->startOfDay(),
            'period_end_at' => now()->subDays(2)->endOfDay(),
            'status' => SettlementPeriod::STATUS_LOCKED,
            'locked_at' => now()->subDay(),
            'locked_by' => $this->owner->id,
        ]);

        SettlementSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'settlement_period_id' => $period->id,
            'snapshot_type' => 'manual',
            'summary_payload' => ['test' => 'data'],
            'variance_payload' => ['test' => 'data'],
            'created_by' => $this->owner->id,
            'created_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner);

        $response = $this->get('/dashboard');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('pulse.settlement', fn (Assert $s) => $s
                ->where('latest_locked_period_id', $period->id)
                ->where('has_snapshot', true)
                ->where('latest_locked_label', $period->period_start_at->format('M d') . ' - ' . $period->period_end_at->format('M d'))
                ->has('locked_at')
                ->etc()
            )
        );
    }

    public function test_branch_manager_sees_only_assigned_branch_locked_settlement(): void
    {
        // Locked period for Branch B (Not assigned to manager A)
        SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchB->id,
            'period_start_at' => now()->subDays(3)->startOfDay(),
            'period_end_at' => now()->subDays(3)->endOfDay(),
            'status' => SettlementPeriod::STATUS_LOCKED,
            'locked_at' => now()->subDays(2),
            'locked_by' => $this->owner->id,
        ]);

        // Locked period for Branch A (Assigned)
        $periodA = SettlementPeriod::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'period_start_at' => now()->subDays(2)->startOfDay(),
            'period_end_at' => now()->subDays(2)->endOfDay(),
            'status' => SettlementPeriod::STATUS_LOCKED,
            'locked_at' => now()->subDay(),
            'locked_by' => $this->owner->id,
        ]);

        $this->actingAs($this->managerA);

        $response = $this->get('/dashboard');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('pulse.settlement.latest_locked_period_id', $periodA->id)
        );
    }

    public function test_dashboard_evidence_is_read_only(): void
    {
        $this->actingAs($this->owner);

        $this->get('/dashboard');

        $this->assertDatabaseCount('settlement_snapshots', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
