<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
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

class PurchaseReceivingPostingTest extends TestCase
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
    public function test_cashier_is_completely_blocked_from_posting_receiving_voucher(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $cashier->assignRole(Role::where('name', 'Cashier')->first());
        
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $cashier->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'TEST',
            'name' => 'Test Supplier',
            'is_active' => true,
        ]);
        
        $receiving = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-TEST-001',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $cashier->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($cashier);

        $this->post(route('procurement.receivings.post', $receiving->id))
            ->assertStatus(403);
    }

    /** @test */
    public function test_authorized_user_can_post_receiving_and_trigger_wac_recalculation(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'branch_code' => 'MNL']);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        // Product starting with existing stock and cost price
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'cost_price' => 100.0000,
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => 10.0000,
            'average_cost' => 100.0000,
        ]);

        $receiving = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-MNL-001',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);

        // We receive 5 units at cost of 130.0000 each
        PurchaseReceivingLine::create([
            'tenant_id' => $tenant->id,
            'purchase_receiving_id' => $receiving->id,
            'product_id' => $product->id,
            'received_quantity' => 5.0000,
            'unit_cost' => 130.0000,
            'line_total' => 650.0000,
            'lot_number' => 'LOT-001',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.post', $receiving->id));
        $response->assertRedirect(route('procurement.receivings.show', $receiving->id));
        $response->assertSessionHas('success');

        // Check posted status
        $receiving->refresh();
        $this->assertEquals(PurchaseReceiving::STATUS_POSTED, $receiving->status);
        $this->assertEquals($manager->id, $receiving->posted_by);
        $this->assertNotNull($receiving->posted_at);

        // Check Inventory updates
        $bi = BranchInventory::where('branch_id', $branch->id)->where('product_id', $product->id)->first();
        $this->assertNotNull($bi);
        
        // Quantity should be 10 + 5 = 15
        $this->assertEquals(15.0000, (float) $bi->current_stock);

        // WAC Calculation: (10 * 100 + 5 * 130) / 15 = (1000 + 650) / 15 = 1650 / 15 = 110.0000
        $this->assertEquals(110.0000, (float) $bi->average_cost);

        // Global cost price must NOT be updated during branch-level receiving posting
        $product->refresh();
        $this->assertEquals(100.0000, (float) $product->cost_price);

        // Check InventoryMovement
        $movement = InventoryMovement::where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->where('movement_type', 'supplier_receiving')
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(5.0000, (float) $movement->quantity_change);
        $this->assertEquals('GRV-MNL-001', $movement->reference_number);
    }

    /** @test */
    public function test_posting_receiving_updates_linked_purchase_order_quantities_and_completes_it(): void
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

        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'cost_price' => 50.0000]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-001',
            'status' => PurchaseOrder::STATUS_SENT,
            'order_date' => now(),
            'created_by' => $manager->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'tenant_id' => $tenant->id,
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => 10.0000,
            'received_quantity' => 0,
            'unit_cost' => 50.0000,
            'line_total' => 500.0000,
        ]);

        $receiving = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-PO-001',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);

        PurchaseReceivingLine::create([
            'tenant_id' => $tenant->id,
            'purchase_receiving_id' => $receiving->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $product->id,
            'ordered_quantity' => 10.0000,
            'received_quantity' => 10.0000,
            'unit_cost' => 50.0000,
            'line_total' => 500.0000,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.post', $receiving->id));
        $response->assertRedirect(route('procurement.receivings.show', $receiving->id));

        // Check PO status is COMPLETED
        $po->refresh();
        $this->assertEquals(PurchaseOrder::STATUS_COMPLETED, $po->status);

        // Check PO Line received quantity is updated
        $poLine->refresh();
        $this->assertEquals(10.0000, (float) $poLine->received_quantity);
    }

    /** @test */
    public function test_cannot_post_already_posted_receiving_voucher(): void
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

        $receiving = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-002',
            'status' => PurchaseReceiving::STATUS_POSTED,
            'received_at' => now(),
            'received_by' => $manager->id,
            'posted_by' => $manager->id,
            'posted_at' => now(),
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);
        $response = $this->postJson(route('procurement.receivings.post', $receiving->id));
        $response->assertStatus(422);
    }
}
