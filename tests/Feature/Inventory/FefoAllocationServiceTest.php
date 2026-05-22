<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\ExpiryLot;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\TenantContext;
use App\Services\Inventory\FefoAllocationService;
use App\Exceptions\Inventory\InsufficientStockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FefoAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FefoAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        $this->service = app(FefoAllocationService::class);
    }

    protected function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    protected function createSetup(string $plan = 'basic'): array
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => $plan]
        ]);
        $this->setTenantContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => true,
        ]);

        return [$tenant, $branch, $product];
    }

    protected function createLot(Tenant $tenant, Branch $branch, Product $product, array $overrides = []): ExpiryLot
    {
        $this->setTenantContext($tenant);
        return ExpiryLot::create(array_merge([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'batch_code' => 'BATCH-' . uniqid(),
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(10)->toDateString(),
            'status' => 'active',
        ], $overrides));
    }

    /** @test */
    public function test_exact_allocation_from_single_lot(): void
    {
        [$tenant, $branch, $product] = $this->createSetup();

        $lot = $this->createLot($tenant, $branch, $product, [
            'batch_code' => 'BATCH-A',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        // Request 5 units
        $allocations = $this->service->allocate($tenant->id, $branch->id, $product->id, '5.0000');

        $this->assertCount(1, $allocations);
        $this->assertEquals($lot->id, $allocations[0]['expiry_lot_id']);
        $this->assertEquals('BATCH-A', $allocations[0]['batch_code']);
        $this->assertEquals('5.0000', $allocations[0]['quantity_allocated']);

        // Verify remaining database state
        $lot->refresh();
        $this->assertEquals(5.0000, (float) $lot->quantity_remaining);
        $this->assertEquals('active', $lot->status);
    }

    /** @test */
    public function test_split_allocation_across_multiple_lots(): void
    {
        [$tenant, $branch, $product] = $this->createSetup();

        // Lot A expires in 10 days
        $lotA = $this->createLot($tenant, $branch, $product, [
            'batch_code' => 'BATCH-A',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        // Lot B expires in 20 days
        $lotB = $this->createLot($tenant, $branch, $product, [
            'batch_code' => 'BATCH-B',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);

        // Request 15 units
        $allocations = $this->service->allocate($tenant->id, $branch->id, $product->id, '15.0000');

        $this->assertCount(2, $allocations);

        // First allocation should be Lot A
        $this->assertEquals($lotA->id, $allocations[0]['expiry_lot_id']);
        $this->assertEquals('BATCH-A', $allocations[0]['batch_code']);
        $this->assertEquals('10.0000', $allocations[0]['quantity_allocated']);

        // Second allocation should be Lot B
        $this->assertEquals($lotB->id, $allocations[1]['expiry_lot_id']);
        $this->assertEquals('BATCH-B', $allocations[1]['batch_code']);
        $this->assertEquals('5.0000', $allocations[1]['quantity_allocated']);

        // Verify remaining database state
        $lotA->refresh();
        $this->assertEquals(0.0000, (float) $lotA->quantity_remaining);
        $this->assertEquals('depleted', $lotA->status);

        $lotB->refresh();
        $this->assertEquals(5.0000, (float) $lotB->quantity_remaining);
        $this->assertEquals('active', $lotB->status);
    }

    /** @test */
    public function test_insufficient_stock_throws_exception_and_rolls_back(): void
    {
        [$tenant, $branch, $product] = $this->createSetup();

        $lot = $this->createLot($tenant, $branch, $product, [
            'batch_code' => 'BATCH-A',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
        ]);

        // Request 15 units when only 10 exist
        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage("Insufficient unexpired stock available.");

        try {
            $this->service->allocate($tenant->id, $branch->id, $product->id, '15.0000');
        } finally {
            // Verify database state is untouched (rolled back)
            $lot->refresh();
            $this->assertEquals(10.0000, (float) $lot->quantity_remaining);
            $this->assertEquals('active', $lot->status);
        }
    }

    /** @test */
    public function test_expired_lots_are_strictly_excluded(): void
    {
        [$tenant, $branch, $product] = $this->createSetup();

        // Lot A is expired (expired yesterday)
        $lotA = $this->createLot($tenant, $branch, $product, [
            'batch_code' => 'BATCH-A',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        // Lot B expires in 10 days
        $lotB = $this->createLot($tenant, $branch, $product, [
            'batch_code' => 'BATCH-B',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        // Request 5 units
        $allocations = $this->service->allocate($tenant->id, $branch->id, $product->id, '5.0000');

        $this->assertCount(1, $allocations);
        $this->assertEquals($lotB->id, $allocations[0]['expiry_lot_id']);
        $this->assertEquals('BATCH-B', $allocations[0]['batch_code']);
        $this->assertEquals('5.0000', $allocations[0]['quantity_allocated']);

        // Verify database state
        $lotA->refresh();
        $this->assertEquals(10.0000, (float) $lotA->quantity_remaining); // Unchanged

        $lotB->refresh();
        $this->assertEquals(5.0000, (float) $lotB->quantity_remaining); // Decremented
    }

    /** @test */
    public function test_tenant_and_branch_isolation(): void
    {
        // 1. Setup Tenant A
        [$tenantA, $branchA, $productA] = $this->createSetup('enterprise');
        $lotA = $this->createLot($tenantA, $branchA, $productA, [
            'batch_code' => 'BATCH-A',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
        ]);

        // 2. Setup Tenant B
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $productB = Product::factory()->create([
            'tenant_id' => $tenantB->id,
            'expiry_tracking_enabled' => true,
        ]);
        $lotB = $this->createLot($tenantB, $branchB, $productB, [
            'batch_code' => 'BATCH-B',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
        ]);

        // 3. Clear and set Tenant A context
        $this->setTenantContext($tenantA);

        // Allocating on Tenant A should not see Tenant B's lot, and shouldn't bleed
        $allocations = $this->service->allocate($tenantA->id, $branchA->id, $productA->id, '5.0000');
        $this->assertCount(1, $allocations);
        $this->assertEquals('BATCH-A', $allocations[0]['batch_code']);

        $lotA->refresh();
        $this->assertEquals(5.0000, (float) $lotA->quantity_remaining);

        // Verify Tenant B's lot remains untouched
        $this->setTenantContext($tenantB);
        $lotB->refresh();
        $this->assertEquals(10.0000, (float) $lotB->quantity_remaining);

        // Attempting to allocate with mismatched tenant context should throw Exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Tenant context mismatch");
        $this->service->allocate($tenantA->id, $branchB->id, $productB->id, '5.0000');
    }

    /** @test */
    public function test_inactive_or_depleted_lots_are_excluded(): void
    {
        [$tenant, $branch, $product] = $this->createSetup();

        // Lot A is depleted
        $lotA = $this->createLot($tenant, $branch, $product, [
            'batch_code' => 'BATCH-A',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '0.0000',
            'status' => 'depleted',
        ]);

        // Lot B is active
        $lotB = $this->createLot($tenant, $branch, $product, [
            'batch_code' => 'BATCH-B',
            'quantity_received' => '10.0000',
            'quantity_remaining' => '10.0000',
            'status' => 'active',
        ]);

        // Request 5 units
        $allocations = $this->service->allocate($tenant->id, $branch->id, $product->id, '5.0000');

        $this->assertCount(1, $allocations);
        $this->assertEquals($lotB->id, $allocations[0]['expiry_lot_id']);
        $this->assertEquals('5.0000', $allocations[0]['quantity_allocated']);
    }
}
