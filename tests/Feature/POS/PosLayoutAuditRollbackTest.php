<?php

namespace Tests\Feature\POS;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PosLayout;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosLayoutAuditRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        
        // Setup RBAC
        $role = \App\Models\Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin Role',
            'slug' => 'admin-role',
        ]);
        
        $p1 = \App\Models\Permission::firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'pos-layouts.view'], ['slug' => 'pos-layouts-view']);
        $p2 = \App\Models\Permission::firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'pos-layouts.manage'], ['slug' => 'pos-layouts-manage']);
        $p3 = \App\Models\Permission::firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'pos-layouts.publish'], ['slug' => 'pos-layouts-publish']);
        
        $role->permissions()->attach([$p1->id, $p2->id, $p3->id]);
        $this->admin->assignRole($role);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $this->actingAs($this->admin);
    }

    public function test_publishing_creates_correct_audit_events()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => PosLayout::STATUS_DRAFT,
            'version' => 1,
        ]);

        $response = $this->post(route('admin.pos-layouts.publish', $layout->id), [
            'branch_ids' => [$this->branch->id],
        ]);

        $response->assertRedirect();
        
        // Check pos_layout_published event
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'pos_layout_published',
            'auditable_type' => PosLayout::class,
            'auditable_id' => $layout->id,
        ]);

        // Check pos_layout_branch_assigned event
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'action' => 'pos_layout_branch_assigned',
            'auditable_type' => PosLayout::class,
            'auditable_id' => $layout->id,
        ]);

        // Verify metadata does not contain full schema
        $audit = AuditLog::where('action', 'pos_layout_published')->first();
        $this->assertArrayNotHasKey('schema', $audit->metadata);
        $this->assertEquals($layout->id, $audit->metadata['layout_id']);
    }

    public function test_replacing_active_layout_creates_replacement_audit_event()
    {
        // First layout
        $layout1 = PosLayout::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->post(route('admin.pos-layouts.publish', $layout1->id), [
            'branch_ids' => [$this->branch->id],
        ]);

        // Second layout (replacement)
        $layout2 = PosLayout::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->post(route('admin.pos-layouts.publish', $layout2->id), [
            'branch_ids' => [$this->branch->id],
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'action' => 'pos_layout_branch_replaced',
        ]);

        $audit = AuditLog::where('action', 'pos_layout_branch_replaced')->first();
        $this->assertEquals($layout1->id, $audit->metadata['deactivated_layout_id']);
    }

    public function test_deployment_history_is_visible_and_tenant_isolated()
    {
        $layout = PosLayout::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->post(route('admin.pos-layouts.publish', $layout->id), [
            'branch_ids' => [$this->branch->id],
        ]);

        $response = $this->get(route('admin.pos-layouts.show', $layout->id));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('history', 1)
            ->where('history.0.branch_name', $this->branch->name)
        );

        // Test Isolation: Tenant B admin cannot see this history
        $tenantB = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($tenantB);
        $adminB = User::factory()->create(['tenant_id' => $tenantB->id]);
        
        $this->actingAs($adminB);
        $responseB = $this->get(route('admin.pos-layouts.show', $layout->id));
        $responseB->assertStatus(404);
    }

    public function test_rollback_republishes_layout_and_logs_event()
    {
        // 1. Publish Layout A
        $layoutA = PosLayout::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Layout A']);
        $this->post(route('admin.pos-layouts.publish', $layoutA->id), ['branch_ids' => [$this->branch->id]]);

        // 2. Publish Layout B (Layout A is now inactive)
        $layoutB = PosLayout::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Layout B']);
        $this->post(route('admin.pos-layouts.publish', $layoutB->id), ['branch_ids' => [$this->branch->id]]);

        // 3. Rollback to Layout A
        $response = $this->post(route('admin.pos-layouts.rollback', $layoutA->id), [
            'branch_id' => $this->branch->id,
        ]);

        $response->assertRedirect();
        
        // Verify Layout A is active again for the branch
        $this->assertDatabaseHas('branch_pos_layout', [
            'branch_id' => $this->branch->id,
            'pos_layout_id' => $layoutA->id,
            'is_active' => true,
        ]);

        // Verify Layout B is deactivated
        $this->assertDatabaseHas('branch_pos_layout', [
            'branch_id' => $this->branch->id,
            'pos_layout_id' => $layoutB->id,
            'is_active' => false,
        ]);

        // Verify Rollback Audit Event
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'action' => 'pos_layout_rollback_completed',
            'auditable_id' => $layoutA->id,
        ]);
    }

    public function test_rollback_requires_publish_permission()
    {
        $layout = PosLayout::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // Setup only view permission
        $role = \App\Models\Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $p1 = \App\Models\Permission::firstOrCreate(['tenant_id' => $this->tenant->id, 'name' => 'pos-layouts.view'], ['slug' => 'pos-layouts-v']);
        $role->permissions()->attach([$p1->id]);
        $viewer->assignRole($role);

        $this->actingAs($viewer);
        $response = $this->post(route('admin.pos-layouts.rollback', $layout->id), [
            'branch_id' => $this->branch->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_system_remains_mutation_safe_during_layout_operations()
    {
        $initialProductCount = DB::table('products')->count();

        $layout = PosLayout::factory()->create(['tenant_id' => $this->tenant->id]);
        
        // Publish
        $this->post(route('admin.pos-layouts.publish', $layout->id), ['branch_ids' => [$this->branch->id]]);
        
        // Rollback
        $this->post(route('admin.pos-layouts.rollback', $layout->id), ['branch_id' => $this->branch->id]);

        $this->assertEquals($initialProductCount, DB::table('products')->count());
    }
}
