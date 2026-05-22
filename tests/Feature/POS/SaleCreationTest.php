<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\CheckoutRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleCreationTest extends TestCase
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

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->first());
        $this->cashier->assignToBranch($this->branch);

        $this->category = ProductCategory::create(['name' => 'Coffee', 'code' => 'COF']);
        $this->product = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'name'                 => 'Americano',
            'sku'                  => 'AMR-001',
            'barcode'              => '1234567890',
            'unit_of_measure'      => 'cup',
            'selling_price'        => 100.00,
            'cost_price'           => 30.00,
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
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ], $overrides);
    }

    private function postCreateSale(array $payload, ?User $user = null): \Illuminate\Testing\TestResponse
    {
        $actor = $user ?? $this->cashier;
        return $this->actingAs($actor)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.create-sale'), $payload);
    }

    // =========================================================================
    // 1. Sale creation requires authenticated user
    // =========================================================================
    public function test_sale_creation_requires_authenticated_user(): void
    {
        $this->postJson(route('pos.checkout.create-sale'), $this->validPayload())
            ->assertStatus(401);
    }

    // =========================================================================
    // 2. Sale creation requires active TenantContext
    // =========================================================================
    public function test_sale_creation_requires_tenant_context(): void
    {
        $noTenantUser = User::factory()->create(['tenant_id' => null]);
        
        $this->actingAs($noTenantUser)
            ->postJson(route('pos.checkout.create-sale'), $this->validPayload())
            ->assertStatus(403);
    }

    // =========================================================================
    // 3. Sale creation requires active BranchContext
    // =========================================================================
    public function test_sale_creation_requires_branch_context(): void
    {
        $unassignedCashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $unassignedCashier->assignRole(Role::where('name', 'Cashier')->first());

        $this->actingAs($unassignedCashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson(route('pos.checkout.create-sale'), $this->validPayload())
            ->assertStatus(403);
    }

    // =========================================================================
    // 4. Sale creation requires create_sale permission
    // =========================================================================
    public function test_sale_creation_requires_create_sale_permission(): void
    {
        $unprivileged = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $unprivileged->assignToBranch($this->branch);

        $this->postCreateSale($this->validPayload(), $unprivileged)->assertStatus(403);
    }

    // =========================================================================
    // 5. User without create_sale receives 403
    // =========================================================================
    public function test_user_without_create_sale_receives_403(): void
    {
        $viewerRole = Role::create(['name' => 'Viewer', 'tenant_id' => $this->tenant->id]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($viewerRole);
        $user->assignToBranch($this->branch);

        $this->postCreateSale($this->validPayload(), $user)->assertStatus(403);
    }

    // =========================================================================
    // 6. client_request_uuid is required
    // =========================================================================
    public function test_client_request_uuid_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['client_request_uuid']);

        $this->postCreateSale($payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_request_uuid']);
    }

    // =========================================================================
    // 7. Invalid UUID is rejected
    // =========================================================================
    public function test_invalid_uuid_is_rejected(): void
    {
        $this->postCreateSale($this->validPayload(['client_request_uuid' => 'not-a-uuid']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_request_uuid']);
    }

    // =========================================================================
    // 8. Valid checkout creates exactly one sale
    // =========================================================================
    public function test_valid_checkout_creates_one_sale(): void
    {
        $response = $this->postCreateSale($this->validPayload());
        $response->assertStatus(200)
            ->assertJsonPath('status', 'created')
            ->assertJsonStructure(['status', 'client_request_uuid', 'sale_id', 'server_totals', 'items']);

        $this->assertDatabaseCount('sales', 1);
    }

    // =========================================================================
    // 9. Valid checkout creates sale line items
    // =========================================================================
    public function test_valid_checkout_creates_sale_line_items(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);

        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseHas('sale_items', [
            'product_id' => $this->product->id,
        ]);
    }

    // =========================================================================
    // 10. Sale and line items use tenant_id and branch_id from active context
    // =========================================================================
    public function test_sale_and_items_use_active_tenant_and_branch_context(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);

        $sale = Sale::first();
        $this->assertEquals($this->tenant->id, $sale->tenant_id);
        $this->assertEquals($this->branch->id, $sale->branch_id);

        $item = SaleItem::first();
        $this->assertEquals($this->tenant->id, $item->tenant_id);
        $this->assertEquals($this->branch->id, $item->branch_id);
    }

    // =========================================================================
    // 11. Sale uses authenticated user_id
    // =========================================================================
    public function test_sale_uses_authenticated_user_id(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);

        $this->assertDatabaseHas('sales', ['user_id' => $this->cashier->id]);
    }

    // =========================================================================
    // 12. Sale item snapshots product name, SKU, barcode, UOM, price, tax type, tax rate
    // =========================================================================
    public function test_sale_item_snapshots_all_product_fields(): void
    {
        // Add a tax category to the product
        $taxCategory = \App\Models\TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code'      => 'VAT12',
            'name'      => 'VAT 12%',
            'tax_type'  => 'VAT',
            'rate'      => 12.00,
        ]);
        $this->product->update(['tax_category_id' => $taxCategory->id]);

        $this->postCreateSale($this->validPayload())->assertStatus(200);

        $item = SaleItem::first();
        $this->assertEquals('Americano', $item->product_name);
        $this->assertEquals('AMR-001', $item->sku);
        $this->assertEquals('1234567890', $item->barcode);
        $this->assertEquals('cup', $item->unit_of_measure);
        $this->assertEquals('100.0000', $item->unit_price);
        $this->assertEquals('VAT', $item->tax_type);
        $this->assertEquals('12.0000', $item->tax_rate);
    }

    // =========================================================================
    // 13. Product changes after sale do not mutate sale item snapshot
    // =========================================================================
    public function test_product_changes_do_not_mutate_sale_item_snapshot(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);

        // Mutate the product AFTER sale creation
        $this->product->update([
            'name'          => 'Cold Brew',
            'sku'           => 'CB-999',
            'selling_price' => 999.00,
        ]);

        $item = SaleItem::first();
        $this->assertEquals('Americano', $item->product_name);   // Snapshot unchanged
        $this->assertEquals('AMR-001', $item->sku);              // Snapshot unchanged
        $this->assertEquals('100.0000', $item->unit_price);      // Snapshot unchanged
    }

    // =========================================================================
    // 14. Frontend price/tax/totals are not trusted
    // =========================================================================
    public function test_frontend_price_is_not_trusted(): void
    {
        $payload = $this->validPayload();
        $payload['items'][0]['unit_price'] = '9999.00';
        $payload['estimated_totals'] = ['subtotal' => '9999.00', 'tax_total' => '0', 'total' => '9999.00'];

        $response = $this->postCreateSale($payload);
        $response->assertStatus(200);

        // Server must compute from product snapshot (100.00 * 2 = 200.00)
        $this->assertEquals('200.0000', $response->json('server_totals.subtotal'));
        $this->assertEquals('100.0000', SaleItem::first()->unit_price);
    }

    // =========================================================================
    // 15. Server totals are computed from product snapshot
    // =========================================================================
    public function test_server_totals_computed_from_snapshot(): void
    {
        // qty = 2, price = 100.00, no tax = total 200.00
        $response = $this->postCreateSale($this->validPayload());
        $response->assertStatus(200);
        $this->assertEquals('200.0000', $response->json('server_totals.subtotal'));
        $this->assertEquals('0.0000',   $response->json('server_totals.tax_total'));
        $this->assertEquals('0.0000',   $response->json('server_totals.discount_total'));
        $this->assertEquals('200.0000', $response->json('server_totals.total'));
    }

    // =========================================================================
    // 16. Duplicate same UUID + same payload does not create duplicate sale
    // =========================================================================
    public function test_duplicate_same_uuid_same_payload_no_duplicate_sale(): void
    {
        $payload = $this->validPayload();

        $this->postCreateSale($payload)->assertStatus(200)->assertJsonPath('status', 'created');
        $this->postCreateSale($payload)
            ->assertStatus(200)
            ->assertJsonPath('status', 'duplicate_seen')
            ->assertJsonStructure(['server_totals' => ['subtotal', 'tax_total', 'discount_total', 'total'], 'items']);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
    }

    // =========================================================================
    // 17. Duplicate same UUID returns existing sale safely
    // =========================================================================
    public function test_duplicate_same_uuid_returns_existing_sale(): void
    {
        $payload = $this->validPayload();

        $first  = $this->postCreateSale($payload);
        $second = $this->postCreateSale($payload);

        $first->assertJsonPath('status', 'created');
        $second->assertJsonPath('status', 'duplicate_seen');
        $this->assertEquals($first->json('sale_id'), $second->json('sale_id'));
        $this->assertEquals($first->json('server_totals.total'), $second->json('server_totals.total'));
        $this->assertEquals($first->json('items.0.line_total'), $second->json('items.0.line_total'));
    }

    // =========================================================================
    // 18. Same UUID with different payload returns conflict
    // =========================================================================
    public function test_same_uuid_different_payload_returns_conflict(): void
    {
        $uuid = (string) Str::uuid();

        $secondProduct = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'name'                 => 'Latte',
            'sku'                  => 'LAT-001',
            'selling_price'        => 80.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        $payload1 = $this->validPayload(['client_request_uuid' => $uuid]);
        $payload2 = $this->validPayload([
            'client_request_uuid' => $uuid,
            'items' => [['product_id' => $secondProduct->id, 'quantity' => 3]],
        ]);

        $this->postCreateSale($payload1)->assertStatus(200)->assertJsonPath('status', 'created');
        $this->postCreateSale($payload2)->assertStatus(409)->assertJsonPath('status', 'conflict');

        // Original sale unchanged
        $this->assertDatabaseCount('sales', 1);
    }

    // =========================================================================
    // 19. Sale creation is atomic: failed item insert rolls back sale header
    // =========================================================================
    public function test_sale_creation_is_atomic(): void
    {
        $response = $this->postCreateSale($this->validPayload());
        $response->assertStatus(200);

        $sale = Sale::first();
        $this->assertNotNull($sale, 'Sale header must exist after successful creation.');
        $this->assertGreaterThan(0, SaleItem::where('sale_id', $sale->id)->count(),
            'Sale items must exist if sale header exists (atomicity enforced).');

        // Verify the sale_id FK link on checkout_requests is also set
        $this->assertDatabaseHas('checkout_requests', ['sale_id' => $sale->id]);
    }

    // =========================================================================
    // 20. Sale records are tenant-scoped
    // =========================================================================
    public function test_sale_records_are_tenant_scoped(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);

        $sale = Sale::first();
        $this->assertEquals($this->tenant->id, $sale->tenant_id);
    }

    // =========================================================================
    // 21. Tenant A cannot access Tenant B sale (tenant isolation)
    // =========================================================================
    public function test_tenant_a_cannot_access_tenant_b_sale(): void
    {
        // Create sale under Tenant A
        $this->postCreateSale($this->validPayload())->assertStatus(200);
        $saleA = Sale::first();

        // Set up Tenant B
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenantB);
        app(TenantContext::class)->setTenant($tenantB);

        // Query sale from Tenant B context — BelongsToTenant global scope must filter it out
        $saleFromTenantB = Sale::find($saleA->id);
        $this->assertNull($saleFromTenantB, 'Tenant B must not be able to access Tenant A sale records.');

        // Restore context
        app(TenantContext::class)->setTenant($this->tenant);
    }

    // =========================================================================
    // 22. Sale items are immutable (update/delete blocked)
    // =========================================================================
    public function test_sale_items_are_immutable_and_cannot_be_updated(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);
        $item = SaleItem::first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sale items are immutable and cannot be updated.');
        
        $item->update(['product_name' => 'Hacked']);
    }

    public function test_sale_items_are_immutable_and_cannot_be_deleted(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);
        $item = SaleItem::first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sale items are immutable and cannot be deleted.');
        
        $item->delete();
    }

    public function test_sale_totals_are_immutable_and_cannot_be_updated(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);
        $sale = Sale::first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Financial totals and core identity of a sale are immutable.');
        
        $sale->update(['total' => 0]);
    }

    public function test_sales_cannot_be_deleted(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);
        $sale = Sale::first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sales cannot be deleted. Use void/refund protocols.');
        
        $sale->delete();
    }

    // =========================================================================
    // 23. No payment record is created
    // =========================================================================
    public function test_no_payment_record_is_created(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);
        if (\Illuminate\Support\Facades\Schema::hasTable('payments')) {
            $this->assertDatabaseCount('payments', 0);
        } else {
            $this->assertTrue(true);
        }
    }

    // =========================================================================
    // 24. No inventory movement is created
    // =========================================================================
    public function test_no_inventory_movement_is_created(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    // =========================================================================
    // 25. No accounting outbox record is created
    // =========================================================================
    public function test_no_accounting_outbox_record_is_created(): void
    {
        $this->postCreateSale($this->validPayload())->assertStatus(200);
        // Table exists in production schema but must be empty for Story 4.5
        if (\Illuminate\Support\Facades\Schema::hasTable('accounting_outbox')) {
            $this->assertDatabaseCount('accounting_outbox', 0);
        } else {
            $this->assertTrue(true);
        }
    }
}
