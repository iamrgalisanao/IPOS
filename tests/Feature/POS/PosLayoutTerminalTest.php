<?php

namespace Tests\Feature\POS;

use App\Models\PosLayout;
use App\Models\Tenant;
use App\Models\Branch;
use App\Models\User;
use App\Models\Product;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosLayoutTerminalTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $branch;
    protected $cashierUser;
    protected $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        
        // Seed RBAC
        $seeder = new RbacSeeder();
        $seeder->seedForTenant($this->tenant);

        // Set tenant context
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        // Create Cashier
        $this->cashierUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cashierRole = \App\Models\Role::where('tenant_id', $this->tenant->id)->where('name', 'Cashier')->first();
        $this->cashierUser->assignRole($cashierRole);
        $this->cashierUser->assignToBranch($this->branch);

        // Create Products
        $this->products = Product::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        // Set branch context
        app(\App\Services\BranchContext::class)->setBranch($this->branch);
    }

    protected function tearDown(): void
    {
        app(\App\Services\TenantContext::class)->clear();
        app(\App\Services\BranchContext::class)->clear();
        parent::tearDown();
    }

    public function test_cashier_can_fetch_active_published_layout_for_current_branch()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'published',
            'schema' => [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => [
                    ['x' => 0, 'y' => 0, 'type' => 'product', 'id' => $this->products[0]->id]
                ]
            ]
        ]);

        $this->branch->posLayouts()->attach($layout, [
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'is_active' => true
        ]);

        $response = $this->actingAs($this->cashierUser)
            ->withHeaders(['X-Branch-ID' => $this->branch->id])
            ->get(route('pos.layout'));

        $response->assertStatus(200);
        $response->assertJsonPath('fallback', false);
        $response->assertJsonPath('layout.id', $layout->id);
        $response->assertJsonCount(1, 'products');
        $response->assertJsonPath('products.0.product_id', $this->products[0]->id);
    }

    public function test_returns_fallback_if_no_active_layout_assigned()
    {
        $response = $this->actingAs($this->cashierUser)
            ->withHeaders(['X-Branch-ID' => $this->branch->id])
            ->get(route('pos.layout'));

        $response->assertStatus(200);
        $response->assertJsonPath('fallback', true);
        $response->assertJsonPath('layout', null);
    }

    public function test_does_not_return_draft_layout_even_if_active()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'draft',
        ]);

        $this->branch->posLayouts()->attach($layout, [
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'is_active' => true
        ]);

        $response = $this->actingAs($this->cashierUser)
            ->withHeaders(['X-Branch-ID' => $this->branch->id])
            ->get(route('pos.layout'));

        $response->assertStatus(200);
        $response->assertJsonPath('fallback', true);
    }

    public function test_does_not_return_archived_layout_even_if_active()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'archived',
        ]);

        $this->branch->posLayouts()->attach($layout, [
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'is_active' => true
        ]);

        $response = $this->actingAs($this->cashierUser)
            ->withHeaders(['X-Branch-ID' => $this->branch->id])
            ->get(route('pos.layout'));

        $response->assertStatus(200);
        $response->assertJsonPath('fallback', true);
    }

    public function test_tenant_isolation_fails_closed()
    {
        // Switch context to set up other tenant
        app(\App\Services\TenantContext::class)->clear();
        $otherTenant = Tenant::factory()->create();
        app(\App\Services\TenantContext::class)->setTenant($otherTenant);

        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        
        $layout = PosLayout::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'published',
        ]);

        $otherBranch->posLayouts()->attach($layout, [
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $otherTenant->id,
            'is_active' => true
        ]);

        // Switch back to original context
        app(\App\Services\TenantContext::class)->setTenant($this->tenant);

        // Attempt to fetch from Tenant context A
        $response = $this->actingAs($this->cashierUser)
            ->withHeaders(['X-Branch-ID' => $otherBranch->id])
            ->get(route('pos.layout'));

        // Middleware should block this before it hits the controller because the branch is not findable in current tenant context
        $response->assertStatus(403);
    }

    public function test_branch_isolation_fails_closed()
    {
        $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'published',
        ]);

        $otherBranch->posLayouts()->attach($layout, [
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'is_active' => true
        ]);

        // Cashier is assigned to $this->branch (implied in middleware/header context)
        // Attempt to fetch layout for a branch they might not have access to
        $response = $this->actingAs($this->cashierUser)
            ->withHeaders(['X-Branch-ID' => $otherBranch->id])
            ->get(route('pos.layout'));

        // If the user can access the other branch, it works.
        // But our middleware checks if user->canAccessBranch.
        // We didn't assign cashierUser to otherBranch.
        $response->assertStatus(403);
    }

    public function test_product_data_integrity_comes_from_catalog()
    {
        $product = $this->products[0];
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'published',
            'schema' => [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => [
                    ['x' => 0, 'y' => 0, 'type' => 'product', 'id' => $product->id]
                ]
            ]
        ]);

        $this->branch->posLayouts()->attach($layout, [
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'is_active' => true
        ]);

        $response = $this->actingAs($this->cashierUser)
            ->withHeaders(['X-Branch-ID' => $this->branch->id])
            ->get(route('pos.layout'));

        $response->assertJsonPath('products.0.display_name', $product->name);
        $this->assertEquals($product->selling_price, $response->json('products.0.selling_price'));
    }

    public function test_returns_fallback_if_schema_is_invalid()
    {
        $layout = PosLayout::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'published',
        ]);
        
        // Force invalid schema in DB bypass validation
        \DB::table('pos_layouts')->where('id', $layout->id)->update([
            'schema' => json_encode(['broken' => 'schema'])
        ]);

        $this->branch->posLayouts()->attach($layout, [
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'is_active' => true
        ]);

        $response = $this->actingAs($this->cashierUser)
            ->withHeaders(['X-Branch-ID' => $this->branch->id])
            ->get(route('pos.layout'));

        $response->assertJsonPath('fallback', true);
    }
}
