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

class PurchaseReceivingDraftTest extends TestCase
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
    public function test_cashier_is_completely_blocked_from_all_receiving_routes(): void
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

        $this->get(route('procurement.receivings.index'))->assertStatus(403);
        $this->get(route('procurement.receivings.create'))->assertStatus(403);
        $this->post(route('procurement.receivings.store'))->assertStatus(403);
        $this->get(route('procurement.receivings.show', $receiving->id))->assertStatus(403);
        $this->get(route('procurement.receivings.edit', $receiving->id))->assertStatus(403);
        $this->put(route('procurement.receivings.update', $receiving->id))->assertStatus(403);
        $this->post(route('procurement.receivings.cancel', $receiving->id))->assertStatus(403);
    }

    /** @test */
    public function test_authorized_user_can_create_standalone_receiving_draft(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'branch_code' => 'CEB']);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        $product1 = Product::factory()->create(['tenant_id' => $tenant->id]);
        $product2 = Product::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $receivedAt = now()->toDateString();
        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'received_at' => $receivedAt,
            'delivery_ref_number' => 'DR-12345',
            'notes' => 'Standalone draft receipt',
            'lines' => [
                [
                    'product_id' => $product1->id,
                    'purchase_order_line_id' => null,
                    'received_quantity' => 10,
                    'unit_cost' => 15.50,
                    'lot_number' => 'LOT-A1',
                    'expiry_date' => now()->addYear()->toDateString(),
                ],
                [
                    'product_id' => $product2->id,
                    'purchase_order_line_id' => null,
                    'received_quantity' => 5,
                    'unit_cost' => 20.00,
                    'lot_number' => null,
                    'expiry_date' => null,
                ]
            ]
        ]);

        $this->setTenantContext($tenant);
        $grv = PurchaseReceiving::where('supplier_id', $supplier->id)->first();
        $this->assertNotNull($grv);
        
        $response->assertRedirect(route('procurement.receivings.show', $grv->id));

        // Assert GRV number format: GRV-{branch_code}-YYYYMMDD-{sequence}
        $expectedGrvPrefix = 'GRV-CEB-' . now()->format('Ymd');
        $this->assertStringStartsWith($expectedGrvPrefix, $grv->receiving_number);

        // Assert status is draft
        $this->assertEquals(PurchaseReceiving::STATUS_DRAFT, $grv->status);

        // Assert total received amount computed server-side: (10 * 15.50) + (5 * 20.00) = 155 + 100 = 255
        $this->assertEquals(255.0000, (float) $grv->total_received_amount);

        // Assert individual lines count and line totals computed server-side
        $this->assertCount(2, $grv->lines);
        $this->assertEquals(155.0000, (float) $grv->lines[0]->line_total);
        $this->assertEquals(100.0000, (float) $grv->lines[1]->line_total);
        $this->assertEquals('LOT-A1', $grv->lines[0]->lot_number);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_authorized_user_can_create_po_linked_receiving_draft_from_approved_or_sent_po(): void
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
            'name' => 'Supplier',
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-LINKED',
            'status' => PurchaseOrder::STATUS_APPROVED,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => 50,
            'unit_cost' => 12.00,
            'line_total' => 600.00,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'purchase_order_id' => $po->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'purchase_order_line_id' => $poLine->id,
                    'received_quantity' => 45, // variance of -5
                    'unit_cost' => 12.00,
                    'lot_number' => 'LOT-PO',
                    'expiry_date' => null,
                ]
            ]
        ]);

        $this->setTenantContext($tenant);
        $grv = PurchaseReceiving::where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($grv);
        
        $response->assertRedirect(route('procurement.receivings.show', $grv->id));
        $this->assertEquals(45, (float) $grv->lines[0]->received_quantity);
        $this->assertEquals(50, (float) $grv->lines[0]->ordered_quantity);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_po_linked_receiving_rejects_draft_pending_cancelled_po(): void
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
            'name' => 'Supplier',
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-REJECT',
            'status' => PurchaseOrder::STATUS_DRAFT, // not approved or sent
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'purchase_order_id' => $po->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'purchase_order_line_id' => null,
                    'received_quantity' => 10,
                    'unit_cost' => 12.00,
                ]
            ]
        ]);

        $response->assertSessionHasErrors(['purchase_order_id']);
    }

    /** @test */
    public function test_po_linked_receiving_rejects_cross_branch_po(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise']
        ]);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch1);
        $manager->assignToBranch($branch2);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier',
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch2->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-CROSS',
            'status' => PurchaseOrder::STATUS_APPROVED,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch1->id, // mismatched branch
            'purchase_order_id' => $po->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'purchase_order_line_id' => null,
                    'received_quantity' => 10,
                    'unit_cost' => 12.00,
                ]
            ]
        ]);

        $response->assertSessionHasErrors(['purchase_order_id']);
    }

    /** @test */
    public function test_po_linked_receiving_rejects_mismatched_supplier(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);

        $supplier1 = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUP1',
            'name' => 'Supplier 1',
            'is_active' => true,
        ]);
        $supplier2 = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUP2',
            'name' => 'Supplier 2',
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier2->id,
            'po_number' => 'PO-SUPPLIER',
            'status' => PurchaseOrder::STATUS_APPROVED,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier1->id, // mismatched supplier
            'branch_id' => $branch->id,
            'purchase_order_id' => $po->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'purchase_order_line_id' => null,
                    'received_quantity' => 10,
                    'unit_cost' => 12.00,
                ]
            ]
        ]);

        $response->assertSessionHasErrors(['purchase_order_id']);
    }

    /** @test */
    public function test_standalone_receiving_requires_supplier(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => null, // empty
            'branch_id' => $branch->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_quantity' => 10,
                    'unit_cost' => 12.00,
                ]
            ]
        ]);

        $response->assertSessionHasErrors(['supplier_id']);
    }

    /** @test */
    public function test_received_quantity_must_be_greater_than_zero(): void
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
            'name' => 'Supplier',
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_quantity' => 0, // invalid quantity
                    'unit_cost' => 12.00,
                ]
            ]
        ]);

        $response->assertSessionHasErrors(['lines.0.received_quantity']);
    }

    /** @test */
    public function test_unit_cost_must_be_non_negative(): void
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
            'name' => 'Supplier',
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_quantity' => 10,
                    'unit_cost' => -5.00, // invalid unit cost
                ]
            ]
        ]);

        $response->assertSessionHasErrors(['lines.0.unit_cost']);
    }

    /** @test */
    public function test_branch_manager_cannot_access_other_branch_receiving(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise']
        ]);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());
        $branch1 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignToBranch($branch1); // assigned only to branch 1

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'SUPP',
            'name' => 'Supplier',
            'is_active' => true,
        ]);
        
        $grv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch2->id, // branch 2
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-CROSS',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $this->get(route('procurement.receivings.show', $grv->id))->assertStatus(403);
        $this->get(route('procurement.receivings.edit', $grv->id))->assertStatus(403);
        $this->put(route('procurement.receivings.update', $grv->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch2->id,
            'received_at' => now()->toDateString(),
            'lines' => []
        ])->assertStatus(403);
        $this->post(route('procurement.receivings.cancel', $grv->id))->assertStatus(403);
    }

    /** @test */
    public function test_cross_tenant_receiving_access_is_blocked(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(RbacSeeder::class)->seedForTenant($tenantA);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        $this->setTenantContext($tenantA);
        $managerA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $managerA->assignRole(Role::where('name', 'Branch Manager')->first());
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id]);
        $managerA->assignToBranch($branchA);
        $supplierA = Supplier::create([
            'tenant_id' => $tenantA->id,
            'code' => 'SUPA',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);
        $productA = Product::factory()->create(['tenant_id' => $tenantA->id]);
        app(TenantContext::class)->clear();

        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $supplierB = Supplier::create([
            'tenant_id' => $tenantB->id,
            'code' => 'SUPB',
            'name' => 'Supplier B',
            'is_active' => true,
        ]);
        $productB = Product::factory()->create(['tenant_id' => $tenantB->id]);
        
        $grvB = PurchaseReceiving::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'supplier_id' => $supplierB->id,
            'receiving_number' => 'GRV-B',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => User::factory()->create(['tenant_id' => $tenantB->id])->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($managerA);

        // Access other tenant GRV
        $this->get(route('procurement.receivings.show', $grvB->id))->assertStatus(404);

        // Store cross tenant inputs
        $response1 = $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplierB->id, // Tenant B supplier
            'branch_id' => $branchA->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                ['product_id' => $productA->id, 'received_quantity' => 10, 'unit_cost' => 10]
            ]
        ]);
        $response1->assertStatus(404);
    }

    /** @test */
    public function test_draft_receiving_can_be_updated(): void
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
            'name' => 'Supplier',
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-UPDATE',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->put(route('procurement.receivings.update', $grv->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'purchase_order_id' => null,
            'received_at' => now()->toDateString(),
            'delivery_ref_number' => 'DR-NEW',
            'notes' => 'Updated draft receipt',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'purchase_order_line_id' => null,
                    'received_quantity' => 100,
                    'unit_cost' => 15.00,
                    'lot_number' => 'LOT-UPDATED',
                    'expiry_date' => null,
                ]
            ]
        ]);

        $this->setTenantContext($tenant);
        $response->assertRedirect(route('procurement.receivings.show', $grv->id));
        $this->assertEquals(1500.0000, (float) $grv->refresh()->total_received_amount);
        $this->assertEquals('LOT-UPDATED', $grv->lines()->first()->lot_number);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_draft_receiving_can_be_cancelled(): void
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
            'name' => 'Supplier',
            'is_active' => true,
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-CANCEL',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->post(route('procurement.receivings.cancel', $grv->id));
        $response->assertStatus(302);

        $this->setTenantContext($tenant);
        $this->assertEquals(PurchaseReceiving::STATUS_CANCELLED, $grv->refresh()->status);
        $this->assertNotNull($grv->cancelled_at);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_cancelled_receiving_is_immutable(): void
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
            'name' => 'Supplier',
            'is_active' => true,
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'receiving_number' => 'GRV-IMMUTABLE',
            'status' => PurchaseReceiving::STATUS_CANCELLED,
            'received_at' => now(),
            'received_by' => $manager->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->put(route('procurement.receivings.update', $grv->id), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'received_at' => now()->toDateString(),
            'lines' => []
        ]);
        $response->assertSessionHasErrors(['status']);
    }

    /** @test */
    public function test_receiving_draft_does_not_mutate_inventory_or_po_received_quantity(): void
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
            'name' => 'Supplier',
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);

        $inventory = BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'current_stock' => 10,
            'status' => 'active'
        ]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-STABLE',
            'status' => PurchaseOrder::STATUS_APPROVED,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => 100,
            'unit_cost' => 10.00,
            'line_total' => 1000.00,
        ]);

        $initialMovementsCount = InventoryMovement::count();
        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Store a receiving draft linked to PO
        $this->post(route('procurement.receivings.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'purchase_order_id' => $po->id,
            'received_at' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'purchase_order_line_id' => $poLine->id,
                    'received_quantity' => 90,
                    'unit_cost' => 10.00,
                ]
            ]
        ]);

        $this->setTenantContext($tenant);
        // Verify current stock remains absolutely unchanged (still 10)
        $this->assertEquals(10, $inventory->refresh()->current_stock);
        // Verify zero new inventory movements created
        $this->assertEquals($initialMovementsCount, InventoryMovement::count());
        // Verify purchase order line received_quantity is NOT updated (still 0)
        $this->assertEquals(0, (float) $poLine->refresh()->received_quantity);
        app(TenantContext::class)->clear();
    }
}
