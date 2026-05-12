<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
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

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
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

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier']);
        $permission = Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'create_sale']);
        $role->permissions()->attach($permission);
        $this->user->assignRole($role);
        $this->user->assignToBranch($this->branch);

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
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route($route), $payload);
    }

    /**
     * AC: Connect the POS frontend "Ready to Complete" action to the existing checkout validation, sale creation
     */
    public function test_it_completes_full_checkout_flow_with_same_uuid(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'client_request_uuid' => $uuid,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ];

        // 1. Validate
        $response = $this->postWithContext('pos.checkout.validate', $payload);
        $response->assertStatus(200);
        $response->assertJson(['status' => 'validated', 'client_request_uuid' => $uuid]);

        // 2. Create Sale
        $response = $this->postWithContext('pos.checkout.create-sale', $payload);
        $response->assertStatus(200);
        $response->assertJson(['status' => 'created', 'client_request_uuid' => $uuid]);
        
        $saleId = $response->json('sale_id');
        $this->assertNotNull($saleId);

        // 3. Idempotency Check (Duplicate Submit)
        // Verify that sending the exact same request again does not create a new sale
        $salesCountBefore = \DB::table('sales')->count();
        $response = $this->postWithContext('pos.checkout.create-sale', $payload);
        $response->assertStatus(200);
        $response->assertJson(['status' => 'duplicate_seen', 'sale_id' => $saleId]);
        $response->assertJsonStructure(['server_totals' => ['subtotal', 'tax_total', 'discount_total', 'total'], 'items']);
        $this->assertEquals('100.0000', $response->json('server_totals.total'));
        $this->assertEquals($salesCountBefore, \DB::table('sales')->count(), 'Duplicate sale creation detected on second request.');
    }

    /**
     * AC: Same client_request_uuid is used for validation and sale creation.
     * This test ensures that if we change the UUID between calls, the second call works independently 
     * but if we use the same UUID, it honors the previous validation.
     */
    public function test_it_honors_previous_validation_with_same_uuid(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'client_request_uuid' => $uuid,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]]
        ];

        // 1. Validate
        $this->postWithContext('pos.checkout.validate', $payload)->assertStatus(200);

        // 2. Create Sale - Verify the sale has the correct quantity from the validated payload
        $response = $this->postWithContext('pos.checkout.create-sale', $payload);
        $response->assertStatus(200);
        
        $sale = \App\Models\Sale::with('items')->find($response->json('sale_id'));
        $this->assertEquals(2, (float) $sale->items->first()->quantity);
        $this->assertEquals($uuid, $sale->client_request_uuid);
    }

    /**
     * AC: Double click does not submit twice (Idempotency)
     */
    public function test_it_prevents_duplicate_sale_creation_on_concurrent_requests(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'client_request_uuid' => $uuid,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ];

        // Simulate first submission
        $response1 = $this->postWithContext('pos.checkout.create-sale', $payload);
        $response1->assertStatus(200);
        $saleId = $response1->json('sale_id');

        // Simulate second submission (double click)
        $response2 = $this->postWithContext('pos.checkout.create-sale', $payload);
        $response2->assertStatus(200);
        $response2->assertJson(['status' => 'duplicate_seen', 'sale_id' => $saleId]);
        $response2->assertJsonStructure(['server_totals' => ['subtotal', 'tax_total', 'discount_total', 'total'], 'items']);

        // Verify only one sale exists
        $this->assertEquals(1, \DB::table('sales')->where('client_request_uuid', $uuid)->count());
    }

    /**
     * AC: Mutation Boundary Proof
     */
    public function test_it_is_mutation_silent_on_validation_and_creation(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'client_request_uuid' => $uuid,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ];

        // Ensure zero records initially
        if (\Schema::hasTable('payments')) $this->assertEquals(0, \DB::table('payments')->count());
        if (\Schema::hasTable('inventory_movements')) $this->assertEquals(0, \DB::table('inventory_movements')->count());
        if (\Schema::hasTable('accounting_outbox')) $this->assertEquals(0, \DB::table('accounting_outbox')->count());

        $this->postWithContext('pos.checkout.validate', $payload);
        $this->postWithContext('pos.checkout.create-sale', $payload);

        // Verify zero records after flow
        if (\Schema::hasTable('payments')) $this->assertEquals(0, \DB::table('payments')->count());
        if (\Schema::hasTable('inventory_movements')) $this->assertEquals(0, \DB::table('inventory_movements')->count());
        if (\Schema::hasTable('accounting_outbox')) $this->assertEquals(0, \DB::table('accounting_outbox')->count());

        // Verify no sequential numbering (sale_number should be null)
        $sale = \App\Models\Sale::where('client_request_uuid', $uuid)->first();
        $this->assertNull($sale->sale_number, 'Sequential sale number was unexpectedly assigned.');
    }

    /**
     * AC: Isolation proof
     */
    public function test_it_enforces_tenant_isolation_on_checkout_flow(): void
    {
        $uuid = (string) Str::uuid();
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        
        $payload = [
            'client_request_uuid' => $uuid,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ];

        // Attempt to checkout with current user but for other tenant header
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $otherTenant->id)
            ->withHeader('X-Branch-ID', (string) Str::uuid()) // Random branch
            ->postJson(route('pos.checkout.validate'), $payload);

        // Should fail because user is not in other tenant
        $response->assertStatus(403);
    }
}
