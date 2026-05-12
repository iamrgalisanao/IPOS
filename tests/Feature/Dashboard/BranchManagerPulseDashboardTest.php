<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\PaymentMethod;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchManagerPulseDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $managerA;
    protected User $multiManager;
    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-12 14:00:00', 'Asia/Manila'));

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Branch B']);

        $this->cash = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'code' => 'CASH', 'status' => 'active']);

        // Manager assigned to A only
        $this->managerA = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user']);
        $this->managerA->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->managerA->assignToBranch($this->branchA);

        // Manager assigned to both A and B
        $this->multiManager = User::factory()->create(['tenant_id' => $this->tenant->id, 'actor_type' => 'tenant_user']);
        $this->multiManager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->multiManager->assignToBranch($this->branchA);
        $this->multiManager->assignToBranch($this->branchB);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_branch_manager_defaults_to_assigned_branch_dashboard(): void
    {
        $this->actingAs($this->managerA);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('pulse', fn (Assert $pulse) => $pulse
                ->where('scope.mode', 'branch')
                ->where('scope.branch_id', $this->branchA->id)
                ->where('scope.label', 'Branch Pulse: Branch A')
                ->has('window')
                ->has('sales')
                ->has('payments')
                ->has('accounting_sync')
                ->has('inventory')
                ->has('settlement')
                ->has('shift')
                ->has('freshness')
            )
            ->has('branches', 1)
        );
    }

    public function test_branch_manager_with_multiple_branches_defaults_to_first_assigned(): void
    {
        $this->actingAs($this->multiManager);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->has('pulse.scope.branch_id') // Should be one of A or B
            ->has('branches', 2)
        );
    }

    public function test_branch_manager_cannot_view_unassigned_branch(): void
    {
        $this->actingAs($this->managerA);

        $response = $this->get('/dashboard?branch_id=' . $this->branchB->id);

        $response->assertStatus(403);
    }

    public function test_branch_manager_cannot_view_tenant_wide_dashboard_without_permission(): void
    {
        $this->actingAs($this->managerA);

        $response = $this->get('/dashboard'); // No branch_id

        // Defaulted to branch A, mode is branch
        $response->assertInertia(fn (Assert $page) => $page
            ->where('pulse.scope.mode', 'branch')
        );
    }

    public function test_manager_with_multi_branch_permission_can_view_tenant_wide(): void
    {
        $role = Role::where('name', 'Branch Manager')->firstOrFail();
        $role->permissions()->attach(\App\Models\Permission::where('name', 'view_multi_branch_dashboard')->firstOrFail()->id);

        $this->actingAs($this->managerA);

        $response = $this->get('/dashboard'); // No branch_id

        $response->assertInertia(fn (Assert $page) => $page
            ->where('pulse.scope.mode', 'tenant')
        );
    }

    public function test_dashboard_metrics_are_strictly_isolated_to_branch(): void
    {
        $this->createSale($this->branchA, '100.00');
        $this->createSale($this->branchB, '500.00');

        $this->actingAs($this->managerA);

        $response = $this->get('/dashboard');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('pulse.sales.gross_sales_total', '100.0000')
        );
    }

    public function test_dashboard_is_read_only_for_branch_manager(): void
    {
        $this->actingAs($this->managerA);

        $this->get('/dashboard');

        $this->assertDatabaseCount('settlement_periods', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    protected function createSale(Branch $branch, string $total): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'user_id' => $this->managerA->id,
            'client_request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sale_number' => 'SALE-' . rand(1000, 9999),
            'status' => 'confirmed',
            'total' => $total,
            'confirmed_at' => now(),
        ]);

        SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $this->cash->id,
            'payment_type' => 'full',
            'provider' => 'cashier',
            'amount' => $total,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
