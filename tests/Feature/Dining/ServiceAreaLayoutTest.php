<?php

namespace Tests\Feature\Dining;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceArea;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ServiceAreaLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $adminUser;
    private User $cashierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        (new RbacSeeder())->seedForTenant($this->tenant);
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->adminUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $ownerRole = \App\Models\Role::where('tenant_id', $this->tenant->id)->where('name', 'Owner/Admin')->first();
        $this->adminUser->assignRole($ownerRole);
        $this->adminUser->assignToBranch($this->branch);

        $this->cashierUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cashierRole = \App\Models\Role::where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->first();
        $this->cashierUser->assignRole($cashierRole);
        $this->cashierUser->assignToBranch($this->branch);
    }

    protected function tearDown(): void
    {
        app(\App\Services\TenantContext::class)->clear();
        app(\App\Services\BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_admin_can_create_service_area_with_default_layout(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.service-areas.store'), [
                'branch_id' => $this->branch->id,
                'name' => 'Dining Room',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('service_areas', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Dining Room',
            'normalized_name' => 'dining room',
            'layout_revision' => 1,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'SERVICE_AREA_CREATED',
        ]);
    }

    public function test_duplicate_service_area_name_is_rejected_even_when_inactive(): void
    {
        ServiceArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Patio',
            'normalized_name' => 'patio',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.service-areas.store'), [
                'branch_id' => $this->branch->id,
                'name' => '  Patio  ',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('name');
    }

    public function test_cashier_without_layout_permission_cannot_manage_service_areas(): void
    {
        $response = $this->actingAs($this->cashierUser)
            ->postJson(route('admin.service-areas.store'), [
                'branch_id' => $this->branch->id,
                'name' => 'Bar',
            ]);

        $response->assertForbidden();
    }

    public function test_invalid_layout_metadata_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.service-areas.store'), [
                'branch_id' => $this->branch->id,
                'name' => 'Bad Layout',
                'layout_metadata' => [
                    'version' => 2,
                    'canvas_width' => 10,
                    'canvas_height' => 900,
                    'grid_size' => 10,
                    'background' => ['type' => 'image', 'image_url' => 'https://example.test/bg.png'],
                ],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'layout_metadata.version',
            'layout_metadata.canvas_width',
            'layout_metadata.background.type',
            'layout_metadata.background.image_url',
        ]);
    }

    public function test_admin_can_create_table_and_duplicate_number_is_scoped_to_service_area(): void
    {
        $areaA = ServiceArea::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);
        $areaB = ServiceArea::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);

        $payload = [
            'table_number' => 'T1',
            'capacity' => 4,
            'position_metadata' => [
                'x' => 100,
                'y' => 80,
                'width' => 120,
                'height' => 80,
                'rotation' => 0,
                'shape' => 'rectangle',
                'label_position' => 'center',
                'z_index' => 1,
            ],
        ];

        $this->actingAs($this->adminUser)
            ->postJson(route('admin.service-areas.tables.store', $areaA), $payload)
            ->assertCreated();

        $this->actingAs($this->adminUser)
            ->postJson(route('admin.service-areas.tables.store', $areaA), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('table_number');

        $this->actingAs($this->adminUser)
            ->postJson(route('admin.service-areas.tables.store', $areaB), $payload)
            ->assertCreated();
    }

    public function test_table_shape_bounds_and_background_are_validated(): void
    {
        $area = ServiceArea::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.service-areas.tables.store', $area), [
                'table_number' => 'C1',
                'capacity' => 4,
                'position_metadata' => [
                    'x' => 100,
                    'y' => 80,
                    'width' => 120,
                    'height' => 80,
                    'rotation' => 0,
                    'shape' => 'circle',
                    'label_position' => 'center',
                    'z_index' => 1,
                ],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('position_metadata.height');
    }

    public function test_layout_batch_update_is_atomic_and_increments_revision(): void
    {
        $area = ServiceArea::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);
        $table = DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'service_area_id' => $area->id,
            'table_number' => 'T1',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson(route('admin.service-areas.layout.update', $area), [
                'expected_layout_revision' => 1,
                'layout_metadata' => ServiceArea::DEFAULT_LAYOUT_METADATA,
                'tables' => [[
                    'id' => $table->id,
                    'position_metadata' => [
                        'x' => 100,
                        'y' => 80,
                        'width' => 120,
                        'height' => 80,
                        'rotation' => 0,
                        'shape' => 'rectangle',
                        'label_position' => 'center',
                        'z_index' => 2,
                    ],
                ]],
            ]);

        $response->assertOk();
        $this->assertEquals(2, $area->fresh()->layout_revision);
        $this->assertEquals(100, $table->fresh()->position_metadata['x']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DINING_LAYOUT_SAVED',
            'auditable_id' => $area->id,
        ]);
    }

    public function test_stale_layout_revision_returns_conflict_and_does_not_overwrite(): void
    {
        $area = ServiceArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'layout_revision' => 5,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson(route('admin.service-areas.layout.update', $area), [
                'expected_layout_revision' => 4,
                'layout_metadata' => ServiceArea::DEFAULT_LAYOUT_METADATA,
                'tables' => [],
            ]);

        $response->assertStatus(409);
        $response->assertJson([
            'code' => 'LAYOUT_REVISION_CONFLICT',
            'current_layout_revision' => 5,
        ]);
        $this->assertEquals(5, $area->fresh()->layout_revision);
    }

    public function test_table_under_inactive_service_area_cannot_be_reactivated(): void
    {
        $area = ServiceArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'is_active' => false,
        ]);
        $table = DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'service_area_id' => $area->id,
            'is_active' => false,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson(route('admin.service-areas.tables.activation', [$area, $table]), [
                'is_active' => true,
            ])
            ->assertStatus(409);
    }

    public function test_service_area_with_tables_cannot_be_deleted(): void
    {
        $area = ServiceArea::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);
        DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'service_area_id' => $area->id,
        ]);

        $this->actingAs($this->adminUser)
            ->deleteJson(route('admin.service-areas.destroy', $area))
            ->assertStatus(409);

        $this->assertDatabaseHas('service_areas', ['id' => $area->id]);
    }

    public function test_unreferenced_service_area_can_be_deleted(): void
    {
        $area = ServiceArea::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);

        $this->actingAs($this->adminUser)
            ->deleteJson(route('admin.service-areas.destroy', $area))
            ->assertNoContent();

        $this->assertDatabaseMissing('service_areas', ['id' => $area->id]);
    }

    public function test_cross_branch_table_manipulation_through_valid_area_route_is_hidden(): void
    {
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $area = ServiceArea::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id]);
        $otherArea = ServiceArea::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $otherBranch->id]);
        $table = DiningTable::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'service_area_id' => $otherArea->id,
        ]);

        $this->actingAs($this->adminUser)
            ->putJson(route('admin.service-areas.tables.update', [$area, $table]), [
                'capacity' => 3,
            ])
            ->assertNotFound();
    }

    public function test_index_falls_back_when_branch_query_is_not_accessible(): void
    {
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $visibleArea = ServiceArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Main Dining',
            'normalized_name' => 'main dining',
        ]);
        ServiceArea::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'name' => 'Hidden Dining',
            'normalized_name' => 'hidden dining',
        ]);

        $permission = Permission::where('tenant_id', $this->tenant->id)
            ->where('name', 'pos-layouts.manage')
            ->firstOrFail();
        $limitedRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Layout Manager',
            'description' => 'Can manage assigned branch dining layouts',
        ]);
        $limitedRole->permissions()->sync([$permission->id]);

        $layoutManager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $layoutManager->assignRole($limitedRole);
        $layoutManager->assignToBranch($this->branch);

        $response = $this->actingAs($layoutManager)
            ->get(route('admin.service-areas.index', ['branch_id' => $otherBranch->id]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ServiceAreas/Index')
            ->where('selectedBranchId', $this->branch->id)
            ->has('serviceAreas', 1)
            ->where('serviceAreas.0.id', $visibleArea->id)
        );
    }
}
