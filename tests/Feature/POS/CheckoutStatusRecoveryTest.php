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

class CheckoutStatusRecoveryTest extends TestCase
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
            'status' => 'active',
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

        $category = ProductCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'General', 'code' => 'GEN']);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'name' => 'Recovery Product',
            'sku' => 'REC-1',
            'selling_price' => 100,
            'is_inventory_tracked' => false,
            'status' => 'active',
        ]);
    }

    private function postWithContext(string $route, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route($route), $payload);
    }

    private function validPayload(string $uuid): array
    {
        return [
            'client_request_uuid' => $uuid,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ];
    }

    public function test_status_returns_confirmed_when_sale_exists(): void
    {
        $uuid = (string) Str::uuid();
        $payload = $this->validPayload($uuid);

        $this->postWithContext('pos.checkout.validate', $payload)->assertStatus(200);
        $saleResponse = $this->postWithContext('pos.checkout.create-sale', $payload)->assertStatus(200);

        $this->postWithContext('pos.checkout.status', ['client_request_uuid' => $uuid])
            ->assertStatus(200)
            ->assertJson([
                'status' => 'confirmed',
                'client_request_uuid' => $uuid,
                'sale_id' => $saleResponse->json('sale_id'),
            ])
            ->assertJsonPath('server_totals.total', '100.0000');
    }

    public function test_status_returns_retry_available_when_checkout_was_validated_but_sale_not_created(): void
    {
        $uuid = (string) Str::uuid();
        $payload = $this->validPayload($uuid);

        $this->postWithContext('pos.checkout.validate', $payload)->assertStatus(200);

        $this->postWithContext('pos.checkout.status', ['client_request_uuid' => $uuid])
            ->assertStatus(200)
            ->assertJson([
                'status' => 'retry_available',
                'client_request_uuid' => $uuid,
            ]);
    }

    public function test_status_returns_not_found_for_unknown_uuid(): void
    {
        $uuid = (string) Str::uuid();

        $this->postWithContext('pos.checkout.status', ['client_request_uuid' => $uuid])
            ->assertStatus(200)
            ->assertJson([
                'status' => 'not_found',
                'client_request_uuid' => $uuid,
            ]);
    }
}