<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected Product $product;
    protected BranchInventory $inventory;
    protected ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Inventory Manager']);
        $permission = Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'view_branch_inventory']);
        $role->permissions()->attach($permission);
        $this->user->assignRole($role);
        $this->user->assignToBranch($this->branch);

        $this->category = ProductCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General',
            'code' => 'GEN'
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id, 
            'product_category_id' => $this->category->id,
            'is_inventory_tracked' => true
        ]);
        
        $this->inventory = BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_stock' => 10,
            'status' => 'active'
        ]);

        // Create a movement
        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'branch_inventory_id' => $this->inventory->id,
            'movement_type' => 'sale_deduction',
            'quantity_change' => -2,
            'quantity_before' => 10,
            'quantity_after' => 8,
            'source_type' => 'sale',
            'source_id' => 'sale-123'
        ]);
    }

    /** AC: Sale deduction movements are visible after paid sale */
    public function test_movements_are_visible_for_active_branch(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route('inventory.movements.index'));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.movement_type', 'sale_deduction');
        $response->assertJsonPath('data.0.product_name', $this->product->name);
        $this->assertEquals(-2, $response->json('data.0.quantity_change'));
    }

    /** AC: Movement list is tenant and branch scoped */
    public function test_tenant_and_branch_isolation(): void
    {
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        // Switch context to Tenant B
        app(TenantContext::class)->setTenant($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        
        // Switch back to Tenant A for the request
        app(TenantContext::class)->setTenant($this->tenant);
        
        // Attempt to view Tenant A movements with Tenant B context
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $tenantB->id)
            ->withHeader('X-Branch-ID', $branchB->id)
            ->getJson(route('inventory.movements.index'));

        // Should be forbidden or return empty based on context enforcement
        // Since the user belongs to Tenant A, the 'tenant' middleware will likely block this.
        $response->assertStatus(403);
    }

    /** AC: Tenant A cannot see Tenant B movements */
    public function test_branch_isolation_same_tenant(): void
    {
        $branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        
        // Movement for Branch B
        InventoryMovement::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchB->id,
            'product_id' => $this->product->id,
            'branch_inventory_id' => $this->inventory->id, // Simplified for test
            'movement_type' => 'sale_deduction',
            'quantity_change' => -5,
            'quantity_before' => 20,
            'quantity_after' => 15,
            'source_type' => 'sale',
            'source_id' => 'sale-456'
        ]);

        // Query Branch A
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route('inventory.movements.index'));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source_id', 'sale-123');
    }

    /** AC: Movement query is read-only */
    public function test_movement_query_is_read_only(): void
    {
        $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route('inventory.movements.index'));

        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseEmpty('accounting_outbox');
    }

    /** AC: Movement payload includes correct quantities and references */
    public function test_movement_payload_structure(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson(route('inventory.movements.index'));

        $data = $response->json('data.0');
        $this->assertArrayHasKey('quantity_before', $data);
        $this->assertArrayHasKey('quantity_change', $data);
        $this->assertArrayHasKey('quantity_after', $data);
        $this->assertArrayHasKey('source_id', $data);
        
        // Ensure cost/accounting metadata is NOT present
        $this->assertArrayNotHasKey('cost_price', $data);
        $this->assertArrayNotHasKey('accounting_synced_at', $data);
    }
}
