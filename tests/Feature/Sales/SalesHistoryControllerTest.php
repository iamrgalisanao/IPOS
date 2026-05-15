<?php

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesHistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $manager;
    protected User $unauthorized;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);

        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->manager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->manager->assignToBranch($this->branch);

        $this->unauthorized = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        // No roles assigned

        app(TenantContext::class)->clear();
    }

    public function test_authorized_user_can_access_index(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        Sale::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->manager->id,
            'status' => 'paid',
        ]);

        $response = $this->actingAs($this->manager)
            ->get(route('sales.history.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sales/History/Index')
            ->has('sales.data', 3)
            ->has('meta', fn (Assert $meta) => $meta
                ->where('can_view_details', true)
                ->where('can_export', false) // Branch Manager does not have export_sales_history
            )
        );
    }

    public function test_unauthorized_user_cannot_access_index(): void
    {
        $response = $this->actingAs($this->unauthorized)
            ->get(route('sales.history.index'));

        $response->assertStatus(403);
    }

    public function test_authorized_user_can_access_show(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->manager->id,
            'status' => 'paid',
        ]);

        $response = $this->actingAs($this->manager)
            ->get(route('sales.history.show', $sale->id));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sales/History/Show')
            ->has('sale', fn (Assert $s) => $s
                ->where('id', $sale->id)
                ->where('status', 'paid')
                ->etc()
            )
        );
    }

    public function test_unauthorized_user_cannot_access_show(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->unauthorized)
            ->get(route('sales.history.show', $sale->id));

        $response->assertStatus(403);
    }

    public function test_export_is_gated_by_permission(): void
    {
        $response = $this->actingAs($this->unauthorized)
            ->get(route('sales.history.export'));

        $response->assertStatus(403);
    }
}
