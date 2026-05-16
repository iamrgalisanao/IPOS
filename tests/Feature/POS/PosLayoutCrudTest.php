<?php

namespace Tests\Feature\POS;

use App\Models\PosLayout;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosLayoutCrudTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $adminUser;
    protected $viewerUser;
    protected $cashierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        
        // Seed RBAC
        $seeder = new RbacSeeder();
        $seeder->seedForTenant($this->tenant);

        // Set tenant context
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        // Create Users
        $this->adminUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $ownerRole = \App\Models\Role::where('tenant_id', $this->tenant->id)->where('name', 'Owner/Admin')->first();
        $this->adminUser->assignRole($ownerRole); // Owners have manage permission

        $this->viewerUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $managerRole = \App\Models\Role::where('tenant_id', $this->tenant->id)->where('name', 'Branch Manager')->first();
        $this->viewerUser->assignRole($managerRole); // Managers have view permission

        $this->cashierUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cashierRole = \App\Models\Role::where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->first();
        $this->cashierUser->assignRole($cashierRole); // Cashiers have no layout permissions
    }

    protected function tearDown(): void
    {
        app(\App\Services\TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_user_with_view_permission_can_list_layouts()
    {
        PosLayout::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->viewerUser)
            ->get(route('admin.pos-layouts.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('layouts', 3));
    }

    public function test_user_without_view_permission_cannot_list_layouts()
    {
        $response = $this->actingAs($this->cashierUser)
            ->get(route('admin.pos-layouts.index'));

        $response->assertStatus(403);
    }

    public function test_user_with_manage_permission_can_create_draft_layout()
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('admin.pos-layouts.store'), [
                'name' => 'New Layout',
            ]);

        $this->assertDatabaseHas('pos_layouts', [
            'name' => 'New Layout',
            'status' => 'draft',
            'tenant_id' => $this->tenant->id,
        ]);

        $layout = PosLayout::where('name', 'New Layout')->first();
        $response->assertRedirect(route('admin.pos-layouts.show', $layout));
        $this->assertEquals(4, $layout->schema['grid']['rows']);
    }

    public function test_store_defaults_to_valid_schema_when_omitted()
    {
        $this->actingAs($this->adminUser)
            ->post(route('admin.pos-layouts.store'), [
                'name' => 'Default Schema Layout',
            ]);

        $layout = PosLayout::where('name', 'Default Schema Layout')->first();
        $this->assertNotNull($layout->schema);
        $this->assertEquals(4, $layout->schema['grid']['rows']);
        $this->assertEmpty($layout->schema['tiles']);
    }

    public function test_invalid_schema_is_rejected()
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('admin.pos-layouts.store'), [
                'name' => 'Invalid Layout',
                'schema' => ['invalid' => 'data']
            ]);

        $response->assertSessionHasErrors('schema');
        $this->assertDatabaseMissing('pos_layouts', ['name' => 'Invalid Layout']);
    }

    public function test_unsafe_schema_fields_are_rejected()
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('admin.pos-layouts.store'), [
                'name' => 'Malicious Layout',
                'schema' => [
                    'grid' => ['rows' => 4, 'columns' => 4],
                    'tiles' => [
                        ['x' => 0, 'y' => 0, 'price' => 0.01] // Forbidden key
                    ]
                ]
            ]);

        $response->assertSessionHasErrors('schema');
    }

    public function test_tenant_isolation_view()
    {
        $otherTenant = Tenant::factory()->create();
        
        // Switch context to create other tenant's data
        app(\App\Services\TenantContext::class)->setTenant($otherTenant);
        $otherLayout = PosLayout::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Tenant Layout',
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
        ]);
        
        // Switch back to test tenant
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        // Attempt to view from Tenant context
        $response = $this->actingAs($this->adminUser)
            ->get(route('admin.pos-layouts.show', $otherLayout));

        $response->assertStatus(404);
    }

    public function test_tenant_isolation_update()
    {
        $otherTenant = Tenant::factory()->create();
        
        app(\App\Services\TenantContext::class)->setTenant($otherTenant);
        $otherLayout = PosLayout::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Tenant Layout',
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
        ]);
        
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        $response = $this->actingAs($this->adminUser)
            ->put(route('admin.pos-layouts.update', $otherLayout), [
                'name' => 'Hacked Name',
                'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
            ]);

        $response->assertStatus(404);
    }

    public function test_draft_layout_can_be_updated()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'draft',
            'name' => 'Old Name'
        ]);

        $response = $this->actingAs($this->adminUser)
            ->put(route('admin.pos-layouts.update', $layout), [
                'name' => 'Updated Name',
                'schema' => ['grid' => ['rows' => 5, 'columns' => 5], 'tiles' => []],
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertEquals('Updated Name', $layout->fresh()->name);
        $this->assertEquals(5, $layout->fresh()->schema['grid']['rows']);
    }

    public function test_published_layout_cannot_be_directly_mutated()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'published',
            'name' => 'Published Layout'
        ]);

        $response = $this->actingAs($this->adminUser)
            ->put(route('admin.pos-layouts.update', $layout), [
                'name' => 'Mutated Name',
                'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
            ]);

        $response->assertSessionHasErrors('status');
        $this->assertEquals('Published Layout', $layout->fresh()->name);
    }

    public function test_archived_layout_cannot_be_updated()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'archived',
            'name' => 'Archived Layout'
        ]);

        $response = $this->actingAs($this->adminUser)
            ->put(route('admin.pos-layouts.update', $layout), [
                'name' => 'Mutated Name',
                'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
            ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_archive_action_changes_status_to_archived()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('admin.pos-layouts.archive', $layout));

        $response->assertSessionHasNoErrors();
        $this->assertEquals('archived', $layout->fresh()->status);
    }

    public function test_archive_action_does_not_delete_the_record()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->adminUser)
            ->post(route('admin.pos-layouts.archive', $layout));

        $this->assertDatabaseHas('pos_layouts', ['id' => $layout->id]);
    }

    public function test_show_route_provides_registry_data_for_editor()
    {
        \App\Models\Product::factory()->count(2)->create(['tenant_id' => $this->tenant->id]);
        \App\Models\ProductCategory::factory()->count(2)->create(['tenant_id' => $this->tenant->id]);
        
        $layout = PosLayout::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('admin.pos-layouts.show', $layout));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('layout')
            ->has('registry.products')
            ->has('registry.categories')
        );
    }
}
