<?php

namespace Tests\Feature\Settlement;

use App\Models\Branch;
use App\Models\Role;
use App\Models\SettlementPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\Settlement\SettlementPeriodService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettlementExportUiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $accountant;
    protected SettlementPeriodService $periodService;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);

        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $this->accountant = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->accountant->assignRole(Role::where('name', 'Accountant')->firstOrFail());
        $this->accountant->assignToBranch($this->branch);

        $this->periodService = app(SettlementPeriodService::class);

        app(TenantContext::class)->clear();
    }

    public function test_show_page_includes_export_permissions(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();

        $response = $this->actingAs($this->accountant)
            ->get(route('settlement.periods.show', $period->id));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settlement/Periods/Show')
            ->has('permissions', fn (Assert $permissions) => $permissions
                ->where('can_export_reports', true)
                ->where('can_export_accounting', true)
            )
        );
    }

    public function test_show_page_hides_export_permissions_for_regular_user(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $period = $this->createPeriod();
        
        $role = \App\Models\Role::create(['name' => 'Limited Reviewer', 'description' => 'Can only view']);
        $role->permissions()->attach(\App\Models\Permission::where('name', 'view_settlement_periods')->firstOrFail()->id);

        $limitedUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $limitedUser->assignRole($role);
        $limitedUser->assignToBranch($this->branch);

        $response = $this->actingAs($limitedUser)
            ->get(route('settlement.periods.show', $period->id));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settlement/Periods/Show')
            ->has('permissions', fn (Assert $permissions) => $permissions
                ->where('can_export_reports', false)
                ->where('can_export_accounting', false)
            )
        );
    }

    protected function createPeriod(array $overrides = []): SettlementPeriod
    {
        return $this->periodService->create(array_merge([
            'branch_id' => $this->branch->id,
            'period_start_at' => now()->subDay()->startOfDay(),
            'period_end_at' => now()->subDay()->endOfDay(),
        ], $overrides), $this->accountant);
    }
}
