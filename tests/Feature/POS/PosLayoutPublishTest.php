<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\PosLayout;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosLayoutPublishTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;
    protected Branch $branch;
    protected PosLayout $layout;

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
        
        $p1 = \App\Models\Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'pos-layouts.view', 'slug' => 'pos-layouts-view']);
        $p2 = \App\Models\Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'pos-layouts.manage', 'slug' => 'pos-layouts-manage']);
        $p3 = \App\Models\Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'pos-layouts.publish', 'slug' => 'pos-layouts-publish']);
        
        $role->permissions()->attach([$p1->id, $p2->id, $p3->id]);
        $this->admin->assignRole($role);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $this->layout = PosLayout::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Draft Layout',
            'status' => 'draft',
            'version' => 1,
            'schema' => [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => []
            ],
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_authorized_user_can_publish_draft_layout()
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.pos-layouts.publish', $this->layout->id), [
                'branch_ids' => [$this->branch->id],
            ]);

        $response->assertRedirect();
        $this->layout->refresh();
        
        $this->assertEquals('published', $this->layout->status);
        $this->assertDatabaseHas('branch_pos_layout', [
            'branch_id' => $this->branch->id,
            'pos_layout_id' => $this->layout->id,
            'is_active' => true,
        ]);
    }

    public function test_unauthorized_user_cannot_publish()
    {
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // No manage permission

        $response = $this->actingAs($user)
            ->post(route('admin.pos-layouts.publish', $this->layout->id), [
                'branch_ids' => [$this->branch->id],
            ]);

        $response->assertStatus(403);
    }

    public function test_archived_layout_cannot_be_published()
    {
        $this->layout->update(['status' => 'archived']);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.pos-layouts.publish', $this->layout->id), [
                'branch_ids' => [$this->branch->id],
            ]);

        $response->assertSessionHasErrors(['publish']);
        $this->assertEquals('archived', $this->layout->refresh()->status);
    }

    public function test_tenant_isolation_is_enforced_on_publish()
    {
        $otherTenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);

        // Reset to original tenant for the request
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.pos-layouts.publish', $this->layout->id), [
                'branch_ids' => [$otherBranch->id],
            ]);

        $response->assertSessionHasErrors(['publish']);
    }

    public function test_publishing_deactivates_previous_active_layout()
    {
        // Setup initial active layout
        $oldLayout = PosLayout::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Old Layout',
            'status' => 'published',
            'version' => 1,
            'schema' => ['grid' => ['rows' => 4, 'columns' => 4], 'tiles' => []],
        ]);

        $this->branch->posLayouts()->attach($oldLayout->id, [
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'active_from' => now()->subDay(),
        ]);

        // Publish new layout
        $this->actingAs($this->admin)
            ->post(route('admin.pos-layouts.publish', $this->layout->id), [
                'branch_ids' => [$this->branch->id],
            ]);

        // Verify old is deactivated
        $this->assertDatabaseHas('branch_pos_layout', [
            'branch_id' => $this->branch->id,
            'pos_layout_id' => $oldLayout->id,
            'is_active' => false,
        ]);

        // Verify new is active
        $this->assertDatabaseHas('branch_pos_layout', [
            'branch_id' => $this->branch->id,
            'pos_layout_id' => $this->layout->id,
            'is_active' => true,
        ]);
    }

    public function test_publishing_to_multiple_branches_is_atomic()
    {
        $branch2 = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $invalidBranchId = \Illuminate\Support\Str::uuid(); // Does not exist

        $response = $this->actingAs($this->admin)
            ->post(route('admin.pos-layouts.publish', $this->layout->id), [
                'branch_ids' => [$this->branch->id, $branch2->id, $invalidBranchId],
            ]);

        // Should fail validation on controller level or service level
        $response->assertSessionHasErrors();
        
        $this->layout->refresh();
        $this->assertEquals('draft', $this->layout->status);
        $this->assertDatabaseMissing('branch_pos_layout', [
            'pos_layout_id' => $this->layout->id,
        ]);
    }

    public function test_terminal_fetch_returns_published_layout()
    {
        // Publish
        $this->actingAs($this->admin)
            ->post(route('admin.pos-layouts.publish', $this->layout->id), [
                'branch_ids' => [$this->branch->id],
            ]);

        // Assign user to branch for middleware check
        $this->admin->assignToBranch($this->branch);

        // Fetch from terminal
        $response = $this->actingAs($this->admin)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->get(route('pos.layout'));

        $response->assertStatus(200);
        $response->assertJsonPath('layout.id', $this->layout->id);
    }
}
