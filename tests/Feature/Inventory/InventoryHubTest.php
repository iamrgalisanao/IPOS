<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryHubTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        app(TenantContext::class)->clear();
    }

    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        $this->get(route('inventory.hub.index'))
            ->assertRedirect(route('login'));
    }

    public function test_authorized_owner_can_view_inventory_hub(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);

        $user->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $user->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.hub.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Hub/Index')
            ->where('meta.is_read_only_hub', true)
            ->has('sections', 5)
            ->where('sections.0.key', 'inventory_overview')
            ->where('sections.0.items.0.route_name', 'inventory.dashboard.index')
            ->where('sections.2.key', 'reports_audit')
            ->where('sections.2.items.0.route_name', 'inventory.reports.variance-logs.index')
        );
    }

    public function test_user_without_required_permissions_is_forbidden(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);

        $user->branches()->attach($this->branch->id);

        app(TenantContext::class)->clear();

        $this->actingAs($user)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->get(route('inventory.hub.index'))
            ->assertForbidden();
    }

    public function test_procurement_links_are_unavailable_when_procurement_features_are_disabled(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => [
                'plan' => 'basic',
                'features' => [
                    'procurement.basic' => false,
                    'procurement.advanced' => false,
                ],
            ],
        ]);

        app(RbacSeeder::class)->seedForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);

        $user->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $user->branches()->attach($branch->id);

        app(TenantContext::class)->clear();

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Tenant-ID' => $tenant->id,
                'X-Branch-ID' => $branch->id,
            ])
            ->get(route('inventory.hub.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('sections.4.key', 'inbound_procurement')
            ->where('sections.4.items.0.available', false)
            ->where('sections.4.items.1.available', false)
            ->where('sections.4.items.2.available', false)
            ->where('sections.4.items.3.available', false)
        );
    }
}
