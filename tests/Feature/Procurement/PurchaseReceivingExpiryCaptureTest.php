<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\ExpiryLot;
use App\Models\Product;
use App\Models\PurchaseReceiving;
use App\Models\PurchaseReceivingLine;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReceivingExpiryCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    protected function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    /** @test */
    public function test_perishable_product_validation_on_draft_creation(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        // Perishable product
        $perishableProduct = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Perishable Product',
            'expiry_tracking_enabled' => true,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // 1. Missing expiry date should fail validation
        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $perishableProduct->id,
                    'received_quantity' => 10,
                    'unit_cost' => 15.50,
                    'lot_number' => 'LOT-123',
                    'expiry_date' => null,
                ]
            ]
        ]);

        $response->assertSessionHasErrors(['lines.0.expiry_date']);

        // 2. Valid expiry date should succeed
        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $perishableProduct->id,
                    'received_quantity' => 10,
                    'unit_cost' => 15.50,
                    'lot_number' => 'LOT-123',
                    'expiry_date' => now()->addYear()->toDateString(),
                ]
            ]
        ]);

        $response->assertStatus(302);
        $this->setTenantContext($tenant);
        $grv = PurchaseReceiving::where('supplier_id', $supplier->id)->first();
        $this->assertNotNull($grv);
        $this->assertCount(1, $grv->lines);
        $this->assertEquals('LOT-123', $grv->lines[0]->lot_number);
        $this->assertNotNull($grv->lines[0]->expiry_date);
    }

    /** @test */
    public function test_perishable_product_validation_on_draft_update(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        $perishableProduct = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Perishable Product',
            'expiry_tracking_enabled' => true,
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-EXP-01',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);

        PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grv->id,
            'product_id' => $perishableProduct->id,
            'received_quantity' => 5,
            'unit_cost' => 10.00,
            'line_total' => 50.00,
            'lot_number' => 'LOT-INIT',
            'expiry_date' => now()->addMonth()->toDateString(),
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // 1. Update with null expiry_date should fail validation
        $response = $this->put(route('procurement.receivings.update', $grv->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $perishableProduct->id,
                    'received_quantity' => 12,
                    'unit_cost' => 12.00,
                    'lot_number' => 'LOT-UPDATED',
                    'expiry_date' => null,
                ]
            ]
        ]);

        $response->assertSessionHasErrors(['lines.0.expiry_date']);

        // 2. Update with valid expiry_date should succeed
        $response = $this->put(route('procurement.receivings.update', $grv->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $perishableProduct->id,
                    'received_quantity' => 12,
                    'unit_cost' => 12.00,
                    'lot_number' => 'LOT-UPDATED',
                    'expiry_date' => now()->addYear()->toDateString(),
                ]
            ]
        ]);

        $response->assertStatus(302);
        $this->setTenantContext($tenant);
        $grv->refresh();
        $this->assertCount(1, $grv->lines);
        $this->assertEquals('LOT-UPDATED', $grv->lines[0]->lot_number);
        $this->assertEquals(12, $grv->lines[0]->received_quantity);
    }

    /** @test */
    public function test_non_perishable_product_does_not_require_expiry_date(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        // Non-perishable product
        $nonPerishableProduct = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Regular Product',
            'expiry_tracking_enabled' => false,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Submitting with empty expiry_date succeeds
        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $nonPerishableProduct->id,
                    'received_quantity' => 10,
                    'unit_cost' => 15.50,
                    'lot_number' => null,
                    'expiry_date' => null,
                ]
            ]
        ]);

        $response->assertStatus(302);
        $this->setTenantContext($tenant);
        $grv = PurchaseReceiving::where('supplier_id', $supplier->id)->first();
        $this->assertNotNull($grv);
        $this->assertNull($grv->lines[0]->expiry_date);
    }

    /** @test */
    public function test_posting_creates_new_expiry_lot(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        $perishableProduct = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => true,
            'cost_price' => 100.0000,
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $perishableProduct->id,
            'current_stock' => 0,
            'average_cost' => 0,
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-EXP-02',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);

        $expiryDate = now()->addYear()->toDateString();
        $line = PurchaseReceivingLine::create([
            'tenant_id' => $tenant->id,
            'purchase_receiving_id' => $grv->id,
            'product_id' => $perishableProduct->id,
            'received_quantity' => 10.0000,
            'unit_cost' => 120.0000,
            'line_total' => 1200.0000,
            'lot_number' => 'LOT-AAA',
            'expiry_date' => $expiryDate,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.post', $grv->id));
        $response->assertRedirect();

        $this->setTenantContext($tenant);

        // Verify ExpiryLot creation
        $lot = ExpiryLot::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $perishableProduct->id)
            ->where('batch_code', 'LOT-AAA')
            ->first();

        $this->assertNotNull($lot);
        $this->assertEquals(10.0000, (float) $lot->quantity_received);
        $this->assertEquals(10.0000, (float) $lot->quantity_remaining);
        $this->assertEquals($expiryDate, $lot->expiry_date->toDateString());
        $this->assertEquals('active', $lot->status);
        $this->assertEquals($line->id, $lot->purchase_receiving_line_id);
    }

    /** @test */
    public function test_posting_increments_existing_expiry_lot(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        $perishableProduct = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => true,
            'cost_price' => 100.0000,
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $perishableProduct->id,
            'current_stock' => 0,
            'average_cost' => 0,
        ]);

        $expiryDate = now()->addYear()->toDateString();

        // Create pre-existing lot with 5 units
        $existingLot = ExpiryLot::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $perishableProduct->id,
            'batch_code' => 'LOT-AAA',
            'quantity_received' => 5.0000,
            'quantity_remaining' => 5.0000,
            'expiry_date' => $expiryDate,
            'status' => 'active',
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-EXP-03',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);

        PurchaseReceivingLine::create([
            'tenant_id' => $tenant->id,
            'purchase_receiving_id' => $grv->id,
            'product_id' => $perishableProduct->id,
            'received_quantity' => 10.0000,
            'unit_cost' => 120.0000,
            'line_total' => 1200.0000,
            'lot_number' => 'LOT-AAA',
            'expiry_date' => $expiryDate,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.post', $grv->id));
        $response->assertRedirect();

        $this->setTenantContext($tenant);

        // Verify existing lot is incremented: 5 + 10 = 15
        $existingLot->refresh();
        $this->assertEquals(15.0000, (float) $existingLot->quantity_received);
        $this->assertEquals(15.0000, (float) $existingLot->quantity_remaining);
    }

    /** @test */
    public function test_posting_generates_fallback_batch_code_if_lot_number_is_empty(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        $perishableProduct = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'expiry_tracking_enabled' => true,
            'cost_price' => 100.0000,
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $perishableProduct->id,
            'current_stock' => 0,
            'average_cost' => 0,
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-EXP-04',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);

        $expiryDate = now()->addYear()->toDateString();
        $line = PurchaseReceivingLine::create([
            'tenant_id' => $tenant->id,
            'purchase_receiving_id' => $grv->id,
            'product_id' => $perishableProduct->id,
            'received_quantity' => 10.0000,
            'unit_cost' => 120.0000,
            'line_total' => 1200.0000,
            'lot_number' => null, // Left blank!
            'expiry_date' => $expiryDate,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.post', $grv->id));
        $response->assertRedirect();

        $this->setTenantContext($tenant);

        // Look for the created lot
        $lot = ExpiryLot::where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $perishableProduct->id)
            ->first();

        $this->assertNotNull($lot);
        
        // Assert lot batch code starts with the expected fallback prefix: LOT-GRVEXP04-
        $expectedPrefix = 'LOT-GRVEXP04-';
        $this->assertStringStartsWith($expectedPrefix, $lot->batch_code);
        
        // Assert quantities are correctly saved
        $this->assertEquals(10.0000, (float) $lot->quantity_received);
        $this->assertEquals(10.0000, (float) $lot->quantity_remaining);
    }
}
