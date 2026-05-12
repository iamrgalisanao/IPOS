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
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutValidationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private Product $product;
    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        // Seed a standard tenant with roles/permissions
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);

        // Set tenant context for model creation
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->first());
        $this->cashier->assignToBranch($this->branch); // Required by canAccessBranch()

        $this->category = ProductCategory::create([
            'name' => 'Beverages',
            'code' => 'BEV',
        ]);

        $this->product = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'name'                 => 'Espresso',
            'sku'                  => 'ESP-001',
            'selling_price'        => 120.00,
            'cost_price'           => 40.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_uuid' => (string) Str::uuid(),
            'cart_state'          => 'Ready to Complete',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 1,
                ],
            ],
            'estimated_totals' => [
                'subtotal'  => '120.0000',
                'tax_total' => '0.0000',
                'total'     => '120.0000',
            ],
        ], $overrides);
    }

    private function postCheckout(array $payload, ?User $user = null): \Illuminate\Testing\TestResponse
    {
        $actor = $user ?? $this->cashier;
        return $this->actingAs($actor)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.validate'), $payload);
    }

    // =========================================================================
    // 1. Requires authenticated user
    // =========================================================================
    public function test_checkout_validation_requires_authenticated_user(): void
    {
        $response = $this->postJson(route('pos.checkout.validate'), $this->validPayload());
        $response->assertStatus(401);
    }

    // =========================================================================
    // 2. Requires active TenantContext
    // =========================================================================
    public function test_checkout_validation_requires_tenant_context(): void
    {
        // Send request without any tenant header — middleware should block
        $response = $this->actingAs($this->cashier)
            ->postJson(route('pos.checkout.validate'), $this->validPayload());
        // Tenant middleware returns 403 when tenant context is missing
        $response->assertStatus(403);
    }

    // =========================================================================
    // 3. Requires active BranchContext
    // =========================================================================
    public function test_checkout_validation_requires_branch_context(): void
    {
        // Send tenant header but NO branch header — branch middleware should block
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson(route('pos.checkout.validate'), $this->validPayload());
        $response->assertStatus(403);
    }

    // =========================================================================
    // 4. Requires create_sale permission
    // =========================================================================
    public function test_checkout_validation_requires_create_sale_permission(): void
    {
        // User with no roles → no permissions
        $unprivileged = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $unprivileged->assignToBranch($this->branch);

        $response = $this->postCheckout($this->validPayload(), $unprivileged);
        $response->assertStatus(403);
    }

    // =========================================================================
    // 5. User without create_sale receives 403
    // =========================================================================
    public function test_user_without_create_sale_permission_receives_403(): void
    {
        // Create a viewer role with no create_sale
        $viewerRole = Role::create([
            'name'      => 'Viewer',
            'tenant_id' => $this->tenant->id,
        ]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($viewerRole);
        $user->assignToBranch($this->branch);

        $response = $this->postCheckout($this->validPayload(), $user);
        $response->assertStatus(403);
    }

    // =========================================================================
    // 6. client_request_uuid is required
    // =========================================================================
    public function test_client_request_uuid_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['client_request_uuid']);

        $this->postCheckout($payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_request_uuid']);
    }

    // =========================================================================
    // 7. Invalid UUID is rejected
    // =========================================================================
    public function test_invalid_uuid_is_rejected(): void
    {
        $this->postCheckout($this->validPayload(['client_request_uuid' => 'not-a-uuid']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_request_uuid']);
    }

    // =========================================================================
    // 8. Valid cart payload is accepted
    // =========================================================================
    public function test_valid_cart_payload_is_accepted(): void
    {
        $response = $this->postCheckout($this->validPayload());
        $response->assertStatus(200)
            ->assertJsonPath('status', 'validated')
            ->assertJsonStructure([
                'status',
                'client_request_uuid',
                'server_totals' => ['subtotal', 'tax_total', 'total'],
                'items' => [
                    ['product_id', 'product_name', 'quantity', 'unit_price', 'tax_type', 'tax_rate'],
                ],
            ]);
    }

    // =========================================================================
    // 9. Empty cart is rejected
    // =========================================================================
    public function test_empty_cart_is_rejected(): void
    {
        $this->postCheckout($this->validPayload(['items' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    // =========================================================================
    // 10. Zero quantity is rejected
    // =========================================================================
    public function test_zero_quantity_is_rejected(): void
    {
        $payload = $this->validPayload([
            'items' => [['product_id' => $this->product->id, 'quantity' => 0]],
        ]);
        $this->postCheckout($payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    // =========================================================================
    // 11. Negative quantity is rejected
    // =========================================================================
    public function test_negative_quantity_is_rejected(): void
    {
        $payload = $this->validPayload([
            'items' => [['product_id' => $this->product->id, 'quantity' => -2]],
        ]);
        $this->postCheckout($payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    // =========================================================================
    // 12. Product from another tenant is rejected
    // =========================================================================
    public function test_product_from_another_tenant_is_rejected(): void
    {
        // Create a foreign tenant product by directly inserting into DB (bypassing global scope)
        $otherTenant = Tenant::factory()->create(['status' => 'active']);

        // Temporarily switch context to create under other tenant
        app(TenantContext::class)->setTenant($otherTenant);
        $foreignCat = ProductCategory::create(['name' => 'Foreign', 'code' => 'FOR']);
        $foreignProduct = Product::create([
            'tenant_id'            => $otherTenant->id,
            'product_category_id'  => $foreignCat->id,
            'name'                 => 'Foreign Item',
            'sku'                  => 'FOR-001',
            'selling_price'        => 50.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        // Restore our tenant context for the request
        app(TenantContext::class)->setTenant($this->tenant);

        $payload = $this->validPayload([
            'items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]],
        ]);
        $response = $this->postCheckout($payload);
        $response->assertStatus(422);
        $this->assertContains($foreignProduct->id, $response->json('invalid_product_ids'));
    }

    // =========================================================================
    // 13. Inactive product is rejected
    // =========================================================================
    public function test_inactive_product_is_rejected(): void
    {
        $this->product->update(['status' => 'inactive']);

        $response = $this->postCheckout($this->validPayload());
        $response->assertStatus(422);
        $this->assertContains($this->product->id, $response->json('invalid_product_ids'));
    }

    // =========================================================================
    // 14. Frontend cost_price is rejected
    // =========================================================================
    public function test_frontend_cost_price_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['items'][0]['cost_price'] = '40.00';

        $this->postCheckout($payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.cost_price']);
    }

    // =========================================================================
    // 15. Accounting/sync/outbox/reconciliation metadata is rejected
    // =========================================================================
    public function test_accounting_metadata_fields_are_rejected(): void
    {
        $unsafeFields = [
            'quickbooks_id'         => 'qb-123',
            'accounting_account_id' => 'acct-456',
            'sync_status'           => 'pending',
            'outbox_status'         => 'queued',
            'reconciliation_status' => 'unreconciled',
            'audit_metadata'        => ['source' => 'frontend'],
        ];

        foreach ($unsafeFields as $field => $value) {
            $payload = $this->validPayload(['client_request_uuid' => (string) Str::uuid()]);
            $payload['items'][0][$field] = $value;

            $this->postCheckout($payload)
                ->assertStatus(422, "Expected 422 rejection for unsafe field [{$field}]")
                ->assertJsonValidationErrors(["items.0.{$field}"]);
        }
    }

    // =========================================================================
    // 16. Backend uses server-side product price, not trusted client price
    // =========================================================================
    public function test_backend_uses_server_side_price_not_client_price(): void
    {
        $payload = $this->validPayload();
        $payload['items'][0]['unit_price'] = '9999.00'; // Client sends wrong price

        $response = $this->postCheckout($payload);
        $response->assertStatus(200);

        // Server total must be 120.00 (real product price), NOT 9999.00
        $this->assertEquals('120.0000', $response->json('server_totals.subtotal'));
        $this->assertEquals('120.0000', $response->json('items.0.unit_price'));
    }

    // =========================================================================
    // 17. Non-inventory-tracked product validates successfully
    // =========================================================================
    public function test_non_inventory_tracked_product_validates_successfully(): void
    {
        // Product is_inventory_tracked = false by default in setUp
        $this->postCheckout($this->validPayload())
            ->assertStatus(200)
            ->assertJsonPath('status', 'validated');
    }

    // =========================================================================
    // 18. Inventory-tracked product without branch inventory returns 422
    // =========================================================================
    public function test_inventory_tracked_product_without_branch_inventory_fails(): void
    {
        $this->product->update(['is_inventory_tracked' => true]);
        // No BranchInventory record created — stock is unavailable at this branch

        $response = $this->postCheckout($this->validPayload());
        $response->assertStatus(422)
            ->assertJsonPath('inventory_errors.0.product_id', $this->product->id);
    }

    // =========================================================================
    // 19. Same client_request_uuid + same payload is safely idempotent (duplicate_seen)
    // =========================================================================
    public function test_same_uuid_same_payload_is_idempotent(): void
    {
        $payload = $this->validPayload();

        $first = $this->postCheckout($payload);
        $first->assertStatus(200)->assertJsonPath('status', 'validated');

        $second = $this->postCheckout($payload);
        $second->assertStatus(200)->assertJsonPath('status', 'duplicate_seen');
        $second->assertJsonPath('client_request_uuid', $payload['client_request_uuid']);

        // Only one CheckoutRequest record should ever be inserted
        $this->assertDatabaseCount('checkout_requests', 1);
    }

    // =========================================================================
    // 20. Same UUID with a different payload returns 409 conflict
    // =========================================================================
    public function test_same_uuid_different_payload_returns_conflict(): void
    {
        $uuid = (string) Str::uuid();

        // Create a second valid product
        $secondProduct = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'name'                 => 'Croissant',
            'sku'                  => 'CRO-001',
            'selling_price'        => 80.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        $payload1 = $this->validPayload(['client_request_uuid' => $uuid]);
        $payload2 = $this->validPayload([
            'client_request_uuid' => $uuid,
            'items' => [['product_id' => $secondProduct->id, 'quantity' => 2]],
        ]);

        $this->postCheckout($payload1)->assertStatus(200)->assertJsonPath('status', 'validated');
        $this->postCheckout($payload2)
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict')
            ->assertJsonPath('message', 'This checkout request was already used with a different cart payload.');

        // Original validated record must remain unmodified
        $this->assertDatabaseCount('checkout_requests', 1);
        $this->assertDatabaseHas('checkout_requests', ['status' => 'validated', 'client_request_uuid' => $uuid]);
    }

    // =========================================================================
    // 21. No final sale record is created
    // =========================================================================
    public function test_no_sale_record_is_created(): void
    {
        $this->postCheckout($this->validPayload())->assertStatus(200);
        // Validate endpoint must not create any sale records (zero-mutation boundary)
        $this->assertDatabaseHas('checkout_requests', ['status' => 'validated']);
        $this->assertDatabaseCount('sales', 0);
    }

    // =========================================================================
    // 22. No sale line item is created
    // =========================================================================
    public function test_no_sale_line_item_is_created(): void
    {
        $this->postCheckout($this->validPayload())->assertStatus(200);
        // Validate endpoint must not create any sale_items (zero-mutation boundary)
        $this->assertDatabaseCount('sale_items', 0);
    }

    // =========================================================================
    // 23. No payment record is created
    // =========================================================================
    public function test_no_payment_record_is_created(): void
    {
        $this->postCheckout($this->validPayload())->assertStatus(200);
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('payments'),
            'Payments table must not exist in Story 4.4 — payment handling is a future story.'
        );
    }

    // =========================================================================
    // 24. No inventory movement is created
    // =========================================================================
    public function test_no_inventory_movement_is_created(): void
    {
        $this->postCheckout($this->validPayload())->assertStatus(200);
        // Inventory movements table exists — assert it is empty
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // =========================================================================
    // 25. No accounting outbox record is created
    // =========================================================================
    public function test_no_accounting_outbox_record_is_created(): void
    {
        $this->postCheckout($this->validPayload())->assertStatus(200);
        // Validate endpoint must not create any outbox records (zero-mutation boundary)
        $this->assertDatabaseCount('accounting_outbox', 0);
    }
}
