<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnerPulseDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $owner;
    protected User $cashier;
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

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user']);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
    }

    public function test_owner_can_view_tenant_wide_dashboard(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('pulse', fn (Assert $pulse) => $pulse
                ->where('scope.mode', 'tenant')
                ->has('window')
                ->has('sales')
                ->has('payments')
                ->has('accounting_sync')
                ->has('inventory')
                ->has('settlement')
                ->has('shift')
                ->has('freshness')
            )
        );
    }

    public function test_user_without_view_reports_receives_403(): void
    {
        // A user without any roles
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);

        $response = $this->get('/dashboard');

        $response->assertStatus(403);
    }

    public function test_cashier_cannot_view_dashboard(): void
    {
        $this->actingAs($this->cashier);

        $response = $this->get('/dashboard');

        $response->assertRedirect(route('pos.index'));
    }

    public function test_dashboard_renders_metrics_correctly(): void
    {
        $this->actingAs($this->owner);

        $response = $this->get('/dashboard');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('pulse.sales.gross_sales_total')
            ->has('pulse.sales.net_sales_total')
            ->has('pulse.sales.refund_total')
            ->has('pulse.sales.void_total')
            ->has('pulse.payments.by_method')
            ->has('pulse.accounting_sync.failed')
            ->has('pulse.inventory.low_stock_count')
            ->has('pulse.settlement.yesterday_status')
        );
    }

    public function test_dashboard_view_is_strictly_read_only(): void
    {
        $this->actingAs($this->owner);

        $this->get('/dashboard');

        // No new records should be created
        $this->assertDatabaseCount('settlement_periods', 0);
        $this->assertDatabaseCount('accounting_outbox', 0);
        $this->assertDatabaseCount('audit_logs', 0); 
    }
}
