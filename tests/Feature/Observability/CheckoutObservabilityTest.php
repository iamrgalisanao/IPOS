<?php

namespace Tests\Feature\Observability;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\CheckoutRequest;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CheckoutObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected ProductCategory $category;
    protected Product $product;
    protected string $correlationId = 'checkout-correlation-123';

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

        $this->category = ProductCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General',
            'code' => 'GEN',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'name' => 'Observability Product',
            'sku' => 'OBS-1',
            'selling_price' => 100,
            'cost_price' => 45,
            'is_inventory_tracked' => false,
            'status' => 'active',
        ]);
    }

    public function test_checkout_validate_logs_validated_duplicate_and_conflict_paths_safely(): void
    {
        Http::fake();
        Log::spy();

        $uuid = (string) Str::uuid();
        $payload = $this->validPayload($uuid);

        $this->postValidate($payload)
            ->assertOk()
            ->assertJsonPath('status', 'validated');

        $this->postValidate($payload)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate_seen');

        $conflictPayload = $this->validPayload($uuid, [
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
            ]],
        ]);

        $this->postValidate($conflictPayload)
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'checkout.validation.validated',
            Mockery::on(fn (array $context) => $this->assertCheckoutContext($context, 'pos.checkout.validate', $uuid, ['item_count' => 1]))
        );
        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'checkout.validation.duplicate_seen',
            Mockery::on(fn (array $context) => $this->assertCheckoutContext($context, 'pos.checkout.validate', $uuid, ['item_count' => 1]))
        );
        Log::shouldHaveReceived('warning')->atLeast()->once()->with(
            'checkout.validation.conflict',
            Mockery::on(fn (array $context) => $this->assertCheckoutContext($context, 'pos.checkout.validate', $uuid, ['item_count' => 1]))
        );

        $this->assertSame(1, CheckoutRequest::count());
        $this->assertSame(0, \DB::table('accounting_outbox')->count());
        Http::assertNothingSent();
    }

    public function test_checkout_validate_logs_invalid_product_inventory_unavailable_and_empty_valid_items_safely(): void
    {
        Http::fake();
        Log::spy();

        $invalidUuid = (string) Str::uuid();
        $this->postValidate($this->validPayload($invalidUuid, [
            'items' => [['product_id' => (string) Str::uuid(), 'quantity' => 1]],
        ]))->assertStatus(422);

        $trackedProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'name' => 'Tracked Product',
            'sku' => 'OBS-TRACKED',
            'selling_price' => 150,
            'cost_price' => 60,
            'is_inventory_tracked' => true,
            'status' => 'active',
        ]);

        $inventoryUuid = (string) Str::uuid();
        $this->postValidate($this->validPayload($inventoryUuid, [
            'items' => [['product_id' => $trackedProduct->id, 'quantity' => 1]],
        ]))->assertStatus(422);

        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $trackedProduct->id,
            'current_stock' => 0,
            'reserved_stock' => 0,
            'reorder_level' => 0,
            'status' => 'inactive',
        ]);

        $emptyUuid = (string) Str::uuid();
        $this->postValidate($this->validPayload($emptyUuid, [
            'items' => [['product_id' => $trackedProduct->id, 'quantity' => 1]],
        ]))->assertStatus(422);

        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'checkout.validation.invalid_products',
            Mockery::on(fn (array $context) => $this->assertCheckoutContext($context, 'pos.checkout.validate', $invalidUuid, [
                'item_count' => 1,
                'invalid_product_count' => 1,
            ]))
        );
        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'checkout.validation.inventory_unavailable',
            Mockery::on(fn (array $context) => $this->assertCheckoutContext($context, 'pos.checkout.validate', $inventoryUuid, [
                'item_count' => 1,
                'inventory_error_count' => 1,
            ]))
        );
        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'checkout.validation.empty_valid_items',
            Mockery::on(fn (array $context) => $this->assertCheckoutContext($context, 'pos.checkout.validate', $emptyUuid, ['item_count' => 1]))
        );

        $this->assertSame(0, \DB::table('accounting_outbox')->count());
        Http::assertNothingSent();
    }

    public function test_checkout_status_logs_confirmed_retry_available_and_not_found_paths_safely(): void
    {
        Http::fake();
        Log::spy();

        $confirmedUuid = (string) Str::uuid();
        $payload = $this->validPayload($confirmedUuid);
        $this->postValidate($payload)->assertOk();
        $saleResponse = $this->postCreateSale($payload)->assertOk();

        $this->postStatus($confirmedUuid)
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');

        $retryUuid = (string) Str::uuid();
        $this->postValidate($this->validPayload($retryUuid))->assertOk();

        $this->postStatus($retryUuid)
            ->assertOk()
            ->assertJsonPath('status', 'retry_available');

        $missingUuid = (string) Str::uuid();
        $this->postStatus($missingUuid)
            ->assertOk()
            ->assertJsonPath('status', 'not_found');

        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'checkout.status.confirmed',
            Mockery::on(fn (array $context) => $this->assertCheckoutContext($context, 'pos.checkout.status', $confirmedUuid, [
                'sale_id' => $saleResponse->json('sale_id'),
            ]))
        );
        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'checkout.status.retry_available',
            Mockery::on(fn (array $context) => $this->assertCheckoutContext($context, 'pos.checkout.status', $retryUuid))
        );
        Log::shouldHaveReceived('info')->atLeast()->once()->with(
            'checkout.status.not_found',
            Mockery::on(fn (array $context) => $this->assertCheckoutContext($context, 'pos.checkout.status', $missingUuid))
        );

        $this->assertSame(0, \DB::table('accounting_outbox')->count());
        Http::assertNothingSent();
    }

    protected function validPayload(string $uuid, array $overrides = []): array
    {
        return array_replace_recursive([
            'client_request_uuid' => $uuid,
            'cart_state' => 'Ready to Complete',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
            ]],
            'estimated_totals' => [
                'subtotal' => '100.0000',
                'tax_total' => '0.0000',
                'total' => '100.0000',
            ],
        ], $overrides);
    }

    protected function postValidate(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withCheckoutContext()
            ->postJson(route('pos.checkout.validate'), $payload);
    }

    protected function postCreateSale(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withCheckoutContext()
            ->postJson(route('pos.checkout.create-sale'), $payload);
    }

    protected function postStatus(string $uuid): \Illuminate\Testing\TestResponse
    {
        return $this->withCheckoutContext()
            ->postJson(route('pos.checkout.status'), ['client_request_uuid' => $uuid]);
    }

    protected function withCheckoutContext(): self
    {
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->withHeader('X-Correlation-ID', $this->correlationId);
    }

    protected function assertCheckoutContext(array $context, string $routeName, string $uuid, array $extra = []): bool
    {
        if (($context['correlation_id'] ?? null) !== $this->correlationId
            || ($context['tenant_id'] ?? null) !== $this->tenant->id
            || ($context['branch_id'] ?? null) !== $this->branch->id
            || ($context['actor_id'] ?? null) !== $this->user->id
            || ($context['actor_type'] ?? null) !== $this->user->actor_type
            || ($context['route_name'] ?? null) !== $routeName
            || ($context['client_request_uuid'] ?? null) !== $uuid
            || array_key_exists('support_session_id', $context)
            || array_key_exists('headers', $context)
            || array_key_exists('Authorization', $context)
            || array_key_exists('authorization', $context)
            || array_key_exists('cookies', $context)
            || array_key_exists('session', $context)
            || array_key_exists('query', $context)
            || array_key_exists('request', $context)
            || array_key_exists('raw_request_body', $context)
            || array_key_exists('items', $context)
            || array_key_exists('payments', $context)
            || array_key_exists('provider_payload', $context)
            || array_key_exists('provider_token', $context)) {
            return false;
        }

        $serialized = json_encode($context, JSON_THROW_ON_ERROR);

        if (str_contains($serialized, 'Bearer') || str_contains($serialized, 'secret')) {
            return false;
        }

        foreach ($extra as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}