<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\CheckoutRequest;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutFailureTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $cashier;
    protected Product $product;

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

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier']);
        $permission = Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'create_sale']);
        $role->permissions()->attach($permission);
        $this->cashier->assignRole($role);
        $this->cashier->assignToBranch($this->branch);

        $category = ProductCategory::create(['name' => 'General', 'code' => 'GEN']);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name'      => 'Test Product',
            'sku'       => 'TEST-1',
            'selling_price' => 100,
            'is_inventory_tracked' => false,
            'status'    => 'active',
        ]);
    }

    private function postWithContext(string $route, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route($route), $payload);
    }

    /**
     * AC: Validation failure returns controlled error and creates no records.
     */
    public function test_validation_failure_returns_controlled_error_and_creates_no_records(): void
    {
        $uuid = (string) Str::uuid();
        
        // Product that doesn't exist
        $payload = [
            'client_request_uuid' => $uuid,
            'items' => [['product_id' => (string) Str::uuid(), 'quantity' => 1]]
        ];

        $response = $this->postWithContext('pos.checkout.validate', $payload);
        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'invalid_product_ids']);

        // Verify no sale/items/checkout_request created
        $this->assertEquals(0, \DB::table('sales')->count());
        $this->assertEquals(0, \DB::table('sale_items')->count());
        $this->assertEquals(0, \DB::table('checkout_requests')->count());
    }

    /**
     * AC: Inventory unavailable returns controlled 422.
     */
    public function test_inventory_unavailable_returns_controlled_422(): void
    {
        $this->product->update(['is_inventory_tracked' => true]);
        
        // No inventory record created for this product in this branch yet
        $uuid = (string) Str::uuid();
        $payload = [
            'client_request_uuid' => $uuid,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]]
        ];

        $response = $this->postWithContext('pos.checkout.validate', $payload);
        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'inventory_errors']);
    }

    /**
     * AC: Sale creation conflict returns controlled 409.
     */
    public function test_sale_creation_conflict_returns_controlled_409(): void
    {
        $uuid = (string) Str::uuid();
        $payload1 = [
            'client_request_uuid' => $uuid,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]]
        ];
        
        // 1. Validate correctly
        $this->postWithContext('pos.checkout.validate', $payload1)->assertStatus(200);

        // 2. Attempt to create sale with SAME UUID but DIFFERENT items
        $payload2 = [
            'client_request_uuid' => $uuid,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]]
        ];

        $response = $this->postWithContext('pos.checkout.create-sale', $payload2);
        $response->assertStatus(409);
        $response->assertJson(['status' => 'conflict']);
        
        // Verify only 0 or 1 sale exists (actually 0 because creation failed)
        $this->assertEquals(0, \DB::table('sales')->count());
    }

    /**
     * AC: Failed paths do not mutate inventory, payments, or outbox.
     */
    public function test_failed_paths_are_mutation_silent(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'client_request_uuid' => $uuid,
            'items' => [['product_id' => (string) Str::uuid(), 'quantity' => 1]]
        ];

        $this->postWithContext('pos.checkout.validate', $payload)->assertStatus(422);

        if (\Schema::hasTable('payments')) $this->assertEquals(0, \DB::table('payments')->count());
        if (\Schema::hasTable('inventory_movements')) $this->assertEquals(0, \DB::table('inventory_movements')->count());
        if (\Schema::hasTable('accounting_outbox')) $this->assertEquals(0, \DB::table('accounting_outbox')->count());
    }

    /**
     * AC: User without permission still receives 403.
     */
    public function test_user_without_permission_receives_403(): void
    {
        $unprivilegedUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $unprivilegedUser->assignToBranch($this->branch);

        $response = $this->actingAs($unprivilegedUser)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.validate'), [
                'client_request_uuid' => (string) Str::uuid(),
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]]
            ]);

        $response->assertStatus(403);
    }

    /**
     * AC: Failed paths remain tenant/branch scoped.
     */
    public function test_failed_paths_remain_scoped(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        $uuid = (string) Str::uuid();
        
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $otherTenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.validate'), [
                'client_request_uuid' => $uuid,
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]]
            ]);

        // Cashier doesn't belong to otherTenant
        $response->assertStatus(403);
    }
}
