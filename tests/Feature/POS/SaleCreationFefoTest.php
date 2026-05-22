<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\CheckoutRequest;
use App\Models\ExpiryLot;
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
use App\Services\POS\SaleCreationService;
use App\Exceptions\Inventory\InsufficientStockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleCreationFefoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private ProductCategory $category;
    private SaleCreationService $saleService;

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

        $this->category = ProductCategory::create(['name' => 'Pharma', 'code' => 'PHA']);
        $this->saleService = app(SaleCreationService::class);
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

    private function createLot(Product $product, array $overrides = []): ExpiryLot
    {
        return ExpiryLot::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'batch_code' => 'BATCH-' . uniqid(),
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(10)->toDateString(),
            'status' => 'active',
        ], $overrides));
    }

    private function createProduct(string $name, string $sku, bool $expiryTracking): Product
    {
        return Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'name'                 => $name,
            'sku'                  => $sku,
            'barcode'              => Str::random(10),
            'unit_of_measure'      => 'pcs',
            'selling_price'        => 100.00,
            'cost_price'           => 30.00,
            'status'               => 'active',
            'is_inventory_tracked' => true,
            'expiry_tracking_enabled' => $expiryTracking,
        ]);
    }

    private function computePayloadHash(string $clientUuid, array $items): string
    {
        $canonicalItems = collect($items)
            ->map(fn($item) => [
                'product_id' => $item['product_id'],
                'quantity'   => number_format((float) $item['quantity'], 4, '.', ''),
            ])
            ->sortBy('product_id')
            ->values()
            ->all();

        $canonical = [
            'client_request_uuid' => $clientUuid,
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->cashier->id,
            'items'               => $canonicalItems,
            'is_training_mode'    => false,
        ];

        return hash('sha256', json_encode($canonical));
    }

    private function createCheckoutRequest(string $clientUuid, array $items): CheckoutRequest
    {
        return CheckoutRequest::create([
            'id'                  => (string) Str::uuid(),
            'tenant_id'           => $this->tenant->id,
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->cashier->id,
            'client_request_uuid' => $clientUuid,
            'payload_hash'        => $this->computePayloadHash($clientUuid, $items),
            'payload'             => ['items' => $items],
            'status'              => 'validated',
        ]);
    }

    // =========================================================================
    // Integration Tests
    // =========================================================================

    /** @test */
    public function test_perishable_product_checkout_depletes_lots_in_fefo_order(): void
    {
        $product = $this->createProduct('Perishable Product', 'PER-001', true);

        // Lot A expires in 10 days
        $lotA = $this->createLot($product, [
            'batch_code' => 'BATCH-A',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        // Lot B expires in 20 days
        $lotB = $this->createLot($product, [
            'batch_code' => 'BATCH-B',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);

        $clientUuid = (string) Str::uuid();
        $items = [['product_id' => $product->id, 'quantity' => 15]];

        $checkoutReq = $this->createCheckoutRequest($clientUuid, $items);

        $result = $this->saleService->createFromPayload(
            $this->tenant->id,
            $this->branch->id,
            $this->cashier->id,
            $clientUuid,
            $items
        );

        $this->assertEquals('created', $result['status']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);

        // Lot A should be fully depleted
        $lotA->refresh();
        $this->assertEquals(0.0000, (float) $lotA->quantity_remaining);
        $this->assertEquals('depleted', $lotA->status);

        // Lot B should be partially depleted
        $lotB->refresh();
        $this->assertEquals(5.0000, (float) $lotB->quantity_remaining);
        $this->assertEquals('active', $lotB->status);
    }

    /** @test */
    public function test_non_perishable_checkout_does_not_touch_lots(): void
    {
        $product = $this->createProduct('Normal Product', 'NOR-001', false);

        // Lot exists for this normal product (e.g. from prior tracking or misconfiguration)
        $lot = $this->createLot($product, [
            'batch_code' => 'BATCH-UNTOUCHED',
            'quantity_remaining' => '10.0000',
        ]);

        $clientUuid = (string) Str::uuid();
        $items = [['product_id' => $product->id, 'quantity' => 5]];

        $checkoutReq = $this->createCheckoutRequest($clientUuid, $items);

        $result = $this->saleService->createFromPayload(
            $this->tenant->id,
            $this->branch->id,
            $this->cashier->id,
            $clientUuid,
            $items
        );

        $this->assertEquals('created', $result['status']);
        $this->assertDatabaseCount('sales', 1);

        // Expiry lot must not be changed at all
        $lot->refresh();
        $this->assertEquals(10.0000, (float) $lot->quantity_remaining);
        $this->assertEquals('active', $lot->status);
    }

    /** @test */
    public function test_mixed_checkout_success(): void
    {
        $perishable = $this->createProduct('Perishable Item', 'PER-002', true);
        $normal = $this->createProduct('Normal Item', 'NOR-002', false);

        $lotPerishable = $this->createLot($perishable, [
            'batch_code' => 'BATCH-PER',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        $lotNormal = $this->createLot($normal, [
            'batch_code' => 'BATCH-NOR',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);

        $clientUuid = (string) Str::uuid();
        $items = [
            ['product_id' => $perishable->id, 'quantity' => 5],
            ['product_id' => $normal->id, 'quantity' => 5],
        ];

        $checkoutReq = $this->createCheckoutRequest($clientUuid, $items);

        $result = $this->saleService->createFromPayload(
            $this->tenant->id,
            $this->branch->id,
            $this->cashier->id,
            $clientUuid,
            $items
        );

        $this->assertEquals('created', $result['status']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 2);

        // Perishable lot decremented
        $lotPerishable->refresh();
        $this->assertEquals(5.0000, (float) $lotPerishable->quantity_remaining);

        // Normal lot untouched
        $lotNormal->refresh();
        $this->assertEquals(10.0000, (float) $lotNormal->quantity_remaining);
    }

    /** @test */
    public function test_insufficient_stock_fails_and_rolls_back_completely(): void
    {
        $product = $this->createProduct('Perishable Product', 'PER-003', true);

        $lot = $this->createLot($product, [
            'batch_code' => 'BATCH-A',
            'quantity_remaining' => '10.0000',
        ]);

        $clientUuid = (string) Str::uuid();
        $items = [['product_id' => $product->id, 'quantity' => 15]];

        $checkoutReq = $this->createCheckoutRequest($clientUuid, $items);

        $this->expectException(InsufficientStockException::class);

        try {
            $this->saleService->createFromPayload(
                $this->tenant->id,
                $this->branch->id,
                $this->cashier->id,
                $clientUuid,
                $items
            );
        } finally {
            // Verify absolutely no Sale header is persisted
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('sale_items', 0);

            // Lot remains untouched
            $lot->refresh();
            $this->assertEquals(10.0000, (float) $lot->quantity_remaining);
            $this->assertEquals('active', $lot->status);

            // Checkout Request FK remains null
            $checkoutReq->refresh();
            $this->assertNull($checkoutReq->sale_id);
        }
    }

    /** @test */
    public function test_expired_only_lot_fails_and_rolls_back(): void
    {
        $product = $this->createProduct('Perishable Product', 'PER-004', true);

        // Lot is expired
        $lot = $this->createLot($product, [
            'batch_code' => 'BATCH-EXPIRED',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $clientUuid = (string) Str::uuid();
        $items = [['product_id' => $product->id, 'quantity' => 5]];

        $checkoutReq = $this->createCheckoutRequest($clientUuid, $items);

        $this->expectException(InsufficientStockException::class);

        try {
            $this->saleService->createFromPayload(
                $this->tenant->id,
                $this->branch->id,
                $this->cashier->id,
                $clientUuid,
                $items
            );
        } finally {
            // Rollback verification
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('sale_items', 0);

            $lot->refresh();
            $this->assertEquals(10.0000, (float) $lot->quantity_remaining);
            $this->assertEquals('active', $lot->status);
        }
    }

    /** @test */
    public function test_multi_item_one_fails_rolls_back_all(): void
    {
        $p1 = $this->createProduct('Perishable Product 1', 'PER-005', true);
        $p2 = $this->createProduct('Perishable Product 2', 'PER-006', true);

        $lot1 = $this->createLot($p1, [
            'batch_code' => 'BATCH-P1',
            'quantity_remaining' => '10.0000',
        ]);

        $lot2 = $this->createLot($p2, [
            'batch_code' => 'BATCH-P2',
            'quantity_remaining' => '5.0000',
        ]);

        $clientUuid = (string) Str::uuid();
        $items = [
            ['product_id' => $p1->id, 'quantity' => 5],
            ['product_id' => $p2->id, 'quantity' => 10],
        ];

        $checkoutReq = $this->createCheckoutRequest($clientUuid, $items);

        $this->expectException(InsufficientStockException::class);

        try {
            $this->saleService->createFromPayload(
                $this->tenant->id,
                $this->branch->id,
                $this->cashier->id,
                $clientUuid,
                $items
            );
        } finally {
            // Verify everything rolled back
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('sale_items', 0);

            $lot1->refresh();
            $this->assertEquals(10.0000, (float) $lot1->quantity_remaining);

            $lot2->refresh();
            $this->assertEquals(5.0000, (float) $lot2->quantity_remaining);
        }
    }
}
