<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\PosLayout;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\PosLayoutPublishService;
use App\Services\POS\PosLayoutSchemaValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosLayoutSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_authorized_admin_can_create_a_pos_layout()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);

        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        
        // This simulates what the Controller/Service will do
        $layout = PosLayout::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Layout',
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
            'status' => PosLayout::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('pos_layouts', [
            'id' => $layout->id,
            'tenant_id' => $tenant->id,
            'name' => 'Main Layout',
            'status' => 'draft',
        ]);

        app(\App\Services\TenantContext::class)->clear();
    }

    public function test_pos_layout_is_tenant_scoped()
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        app(\App\Services\TenantContext::class)->setTenant($tenantA);
        PosLayout::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A Layout',
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
        ]);
        app(\App\Services\TenantContext::class)->clear();

        app(\App\Services\TenantContext::class)->setTenant($tenantB);
        PosLayout::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B Layout',
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
        ]);
        app(\App\Services\TenantContext::class)->clear();

        // Mock tenant context A
        app(\App\Services\TenantContext::class)->setTenant($tenantA);
        
        $layouts = PosLayout::all();
        
        $this->assertCount(1, $layouts);
        $this->assertEquals('Tenant A Layout', $layouts->first()->name);

        app(\App\Services\TenantContext::class)->clear();
    }

    public function test_schema_casts_to_array()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);

        $layout = PosLayout::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cast Test',
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
        ]);

        $retrieved = PosLayout::find($layout->id);
        $this->assertIsArray($retrieved->schema);
        $this->assertEquals(4, $retrieved->schema['grid']['rows']);

        app(\App\Services\TenantContext::class)->clear();
    }

    public function test_valid_schema_passes_validator()
    {
        $validSchema = [
            'grid' => ['rows' => 4, 'columns' => 4],
            'tiles' => [
                ['x' => 0, 'y' => 0, 'type' => 'product', 'id' => '123']
            ]
        ];

        $this->assertTrue(PosLayoutSchemaValidator::validate($validSchema));
    }

    public function test_invalid_schema_fails_validator()
    {
        $invalidSchemas = [
            [], // Empty
            ['grid' => []], // Missing rows/columns
            ['grid' => ['rows' => -1, 'columns' => 4]], // Negative rows
            ['grid' => ['rows' => 4, 'columns' => 4]], // Missing tiles array
            [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => [
                    ['x' => 0, 'y' => 0, 'price' => 100] // Contains forbidden key 'price'
                ]
            ],
            [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => [
                    ['type' => 'product', 'id' => '123'] // Missing coordinates
                ]
            ],
            [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => [
                    ['x' => 4, 'y' => 0, 'type' => 'product', 'id' => '123'] // Out of bounds (x=4 in 4-column grid)
                ]
            ],
            [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => [
                    ['x' => 0, 'y' => 4, 'type' => 'product', 'id' => '123'] // Out of bounds (y=4 in 4-row grid)
                ]
            ],
            [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => [
                    ['x' => 0, 'y' => 0, 'type' => 'product', 'id' => '123'],
                    ['x' => 0, 'y' => 0, 'type' => 'product', 'id' => '456'] // Overlapping
                ]
            ]
        ];

        foreach ($invalidSchemas as $schema) {
            $this->assertFalse(PosLayoutSchemaValidator::validate($schema));
        }
    }

    public function test_branch_relationship_can_attach_a_layout_to_a_branch_in_same_tenant()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        
        $layout = PosLayout::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Layout',
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
        ]);

        $layout->branches()->attach($branch->id, ['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'is_active' => true]);

        $this->assertDatabaseHas('branch_pos_layout', [
            'branch_id' => $branch->id,
            'pos_layout_id' => $layout->id,
            'is_active' => true,
        ]);
        
        $this->assertTrue($branch->posLayouts()->where('branch_pos_layout.is_active', true)->exists());

        app(\App\Services\TenantContext::class)->clear();
    }

    public function test_only_one_active_layout_per_branch_is_allowed()
    {
        $tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $publisher = User::factory()->create(['tenant_id' => $tenant->id]);

        $firstLayout = PosLayout::create([
            'tenant_id' => $tenant->id,
            'name' => 'Morning Layout',
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
            'status' => PosLayout::STATUS_DRAFT,
        ]);

        $secondLayout = PosLayout::create([
            'tenant_id' => $tenant->id,
            'name' => 'Evening Layout',
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
            'status' => PosLayout::STATUS_DRAFT,
        ]);

        $publishService = app(PosLayoutPublishService::class);

        $publishService->publish($firstLayout, [$branch->id], $publisher);
        $publishService->publish($secondLayout, [$branch->id], $publisher);

        $activeRows = DB::table('branch_pos_layout')
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->get();

        $this->assertCount(1, $activeRows);
        $this->assertSame($secondLayout->id, $activeRows->first()->pos_layout_id);
        $this->assertDatabaseHas('branch_pos_layout', [
            'branch_id' => $branch->id,
            'pos_layout_id' => $firstLayout->id,
            'is_active' => false,
        ]);

        app(\App\Services\TenantContext::class)->clear();
    }

    public function test_rbac_permissions_are_seeded()
    {
        $tenant = Tenant::factory()->create();
        
        // Seed roles and permissions for this tenant
        $seeder = new \App\Services\RbacSeeder();
        $seeder->seedForTenant($tenant);

        app(\App\Services\TenantContext::class)->setTenant($tenant);

        $this->assertDatabaseHas('permissions', ['name' => 'pos-layouts.view', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('permissions', ['name' => 'pos-layouts.manage', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('permissions', ['name' => 'pos-layouts.publish', 'tenant_id' => $tenant->id]);

        app(\App\Services\TenantContext::class)->clear();
    }
}
