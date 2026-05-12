<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileDashboardResponsivenessTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $owner;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user']);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branch);
    }

    public function test_dashboard_still_renders_correctly_after_responsive_updates(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('pulse')
            ->has('branches')
        );
    }

    public function test_dashboard_contract_remains_read_only_after_ui_changes(): void
    {
        $this->actingAs($this->owner);

        $this->get('/dashboard');

        $this->assertDatabaseCount('settlement_periods', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
