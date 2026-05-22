<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceiving;
use App\Models\PurchaseReceivingLine;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Services\Procurement\PurchaseReceivingPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected Branch $branchA1;
    protected Branch $branchA2;
    protected Branch $branchB1;
    protected User $cashierA;
    protected User $clerkA;
    protected User $managerA1;
    protected User $managerA2;
    protected Supplier $supplierA;
    protected Supplier $supplierB;
    protected Product $productA;
    protected Product $productB;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();

        // Initialize two separate tenants for cross-tenant testing
        $this->tenantA = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise']
        ]);
        $this->tenantB = Tenant::factory()->create([
            'status' => 'active',
            'subscription_metadata' => ['plan' => 'enterprise']
        ]);

        app(RbacSeeder::class)->seedForTenant($this->tenantA);
        app(RbacSeeder::class)->seedForTenant($this->tenantB);

        // Setup Tenant A environment
        $this->setTenantContext($this->tenantA);

        $this->branchA1 = Branch::factory()->create(['tenant_id' => $this->tenantA->id, 'branch_code' => 'BR1']);
        $this->branchA2 = Branch::factory()->create(['tenant_id' => $this->tenantA->id, 'branch_code' => 'BR2']);

        $this->cashierA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->cashierA->assignRole(Role::where('name', 'Cashier')->first());
        $this->cashierA->assignToBranch($this->branchA1);

        $this->managerA1 = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->managerA1->assignRole(Role::where('name', 'Branch Manager')->first());
        $this->managerA1->assignToBranch($this->branchA1);

        $this->managerA2 = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->managerA2->assignRole(Role::where('name', 'Branch Manager')->first());
        $this->managerA2->assignToBranch($this->branchA2);

        // Store Clerk with view and create permissions, but no approve or post permissions
        $this->clerkA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $clerkRole = Role::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store Clerk',
            'description' => 'Store clerk role',
        ]);
        $clerkRole->permissions()->sync(
            Permission::whereIn('name', [
                'procurement.suppliers.view',
                'procurement.purchase-orders.view',
                'procurement.purchase-orders.create',
                'procurement.purchase-orders.export',
                'procurement.receiving.view',
                'procurement.receiving.create',
                'procurement.receiving.export'
            ])->pluck('id')->toArray()
        );
        $this->clerkA->assignRole($clerkRole);
        $this->clerkA->assignToBranch($this->branchA1);

        $this->supplierA = Supplier::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'SUP-A',
            'name' => 'Supplier A',
            'is_active' => true,
        ]);

        $this->productA = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'cost_price' => '100.0000',
        ]);

        // Setup Tenant B environment
        $this->setTenantContext($this->tenantB);
        $this->branchB1 = Branch::factory()->create(['tenant_id' => $this->tenantB->id, 'branch_code' => 'BB1']);
        
        $this->supplierB = Supplier::create([
            'tenant_id' => $this->tenantB->id,
            'code' => 'SUP-B',
            'name' => 'Supplier B',
            'is_active' => true,
        ]);

        $this->productB = Product::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'cost_price' => '200.0000',
        ]);

        app(TenantContext::class)->clear();
    }

    protected function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    /** @test */
    public function test_cashier_is_completely_blocked_from_all_procurement_actions(): void
    {
        $this->actingAs($this->cashierA);

        // 1. Supplier Pages/Actions
        $this->get(route('procurement.suppliers.index'))->assertStatus(403);
        $this->get(route('procurement.suppliers.create'))->assertStatus(403);
        $this->post(route('procurement.suppliers.store'), [])->assertStatus(403);
        $this->get(route('procurement.suppliers.show', $this->supplierA->id))->assertStatus(403);
        $this->get(route('procurement.suppliers.edit', $this->supplierA->id))->assertStatus(403);
        $this->put(route('procurement.suppliers.update', $this->supplierA->id), [])->assertStatus(403);
        $this->patch(route('procurement.suppliers.toggle-status', $this->supplierA->id))->assertStatus(403);

        // 2. Purchase Order Pages/Actions
        $this->get(route('procurement.purchase-orders.index'))->assertStatus(403);
        $this->get(route('procurement.purchase-orders.create'))->assertStatus(403);
        $this->post(route('procurement.purchase-orders.store'), [])->assertStatus(403);
        $this->get(route('procurement.purchase-orders.export'))->assertStatus(403);

        // 3. Purchase Receiving Pages/Actions
        $this->get(route('procurement.receivings.index'))->assertStatus(403);
        $this->get(route('procurement.receivings.create'))->assertStatus(403);
        $this->post(route('procurement.receivings.store'), [])->assertStatus(403);
        $this->get(route('procurement.receivings.export'))->assertStatus(403);
    }

    /** @test */
    public function test_store_clerk_cannot_approve_po_or_post_receiving(): void
    {
        $this->setTenantContext($this->tenantA);
        
        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'po_number' => 'PO-C-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $this->clerkA->id,
        ]);

        $receiving = PurchaseReceiving::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'receiving_number' => 'GRV-C-001',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $this->clerkA->id,
        ]);

        PurchaseReceivingLine::create([
            'purchase_receiving_id' => $receiving->id,
            'product_id' => $this->productA->id,
            'received_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($this->clerkA);

        // 1. Approve PO blocked
        $this->post(route('procurement.purchase-orders.approve', $po->id))->assertStatus(403);

        // 2. Post Receiving blocked
        $this->post(route('procurement.receivings.post', $receiving->id))->assertStatus(403);
    }

    /** @test */
    public function test_branch_manager_cannot_access_other_branch_records(): void
    {
        $this->setTenantContext($this->tenantA);

        // PO in Branch 2 (Manager A1 belongs to Branch 1)
        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA2->id,
            'supplier_id' => $this->supplierA->id,
            'po_number' => 'PO-M2-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $this->managerA2->id,
        ]);

        // Receiving in Branch 2
        $receiving = PurchaseReceiving::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA2->id,
            'supplier_id' => $this->supplierA->id,
            'receiving_number' => 'GRV-M2-001',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $this->managerA2->id,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($this->managerA1);

        // 1. Access PO from Branch 2 blocked (403)
        $this->get(route('procurement.purchase-orders.show', $po->id))->assertStatus(403);
        $this->get(route('procurement.purchase-orders.edit', $po->id))->assertStatus(403);

        // 2. Access Receiving from Branch 2 blocked (403)
        $this->get(route('procurement.receivings.show', $receiving->id))->assertStatus(403);
        $this->get(route('procurement.receivings.edit', $receiving->id))->assertStatus(403);
    }

    /** @test */
    public function test_cross_tenant_isolation_is_strictly_enforced(): void
    {
        // Set context to Tenant B to create records
        $this->setTenantContext($this->tenantB);

        $poB = PurchaseOrder::create([
            'tenant_id' => $this->tenantB->id,
            'branch_id' => $this->branchB1->id,
            'supplier_id' => $this->supplierB->id,
            'po_number' => 'PO-TB-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => User::factory()->create(['tenant_id' => $this->tenantB->id])->id,
        ]);

        $receivingB = PurchaseReceiving::create([
            'tenant_id' => $this->tenantB->id,
            'branch_id' => $this->branchB1->id,
            'supplier_id' => $this->supplierB->id,
            'receiving_number' => 'GRV-TB-001',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => User::factory()->create(['tenant_id' => $this->tenantB->id])->id,
        ]);

        app(TenantContext::class)->clear();

        // Act as Tenant A Manager
        $this->actingAs($this->managerA1);

        // Tenant A user should get a 404 (not found) for Tenant B objects due to global tenant scopes
        $this->get(route('procurement.suppliers.show', $this->supplierB->id))->assertStatus(404);
        $this->get(route('procurement.purchase-orders.show', $poB->id))->assertStatus(404);
        $this->get(route('procurement.receivings.show', $receivingB->id))->assertStatus(404);
    }

    /** @test */
    public function test_po_terminal_state_immutability(): void
    {
        $this->setTenantContext($this->tenantA);

        $poCompleted = PurchaseOrder::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'po_number' => 'PO-COMP',
            'status' => PurchaseOrder::STATUS_COMPLETED,
            'order_date' => now()->toDateString(),
            'created_by' => $this->managerA1->id,
        ]);

        $poCancelled = PurchaseOrder::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'po_number' => 'PO-CANC',
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'order_date' => now()->toDateString(),
            'created_by' => $this->managerA1->id,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($this->managerA1);

        // Completed PO cannot be edited or modified
        $this->get(route('procurement.purchase-orders.edit', $poCompleted->id))
            ->assertRedirect(route('procurement.purchase-orders.show', $poCompleted->id));

        $this->put(route('procurement.purchase-orders.update', $poCompleted->id), [
            'supplier_id' => $this->supplierA->id,
            'branch_id' => $this->branchA1->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->productA->id, 'ordered_quantity' => 5, 'unit_cost' => 10]],
        ])->assertSessionHasErrors();

        // Cancelled PO cannot be edited or modified
        $this->get(route('procurement.purchase-orders.edit', $poCancelled->id))
            ->assertRedirect(route('procurement.purchase-orders.show', $poCancelled->id));

        $this->put(route('procurement.purchase-orders.update', $poCancelled->id), [
            'supplier_id' => $this->supplierA->id,
            'branch_id' => $this->branchA1->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->productA->id, 'ordered_quantity' => 5, 'unit_cost' => 10]],
        ])->assertSessionHasErrors();
    }

    /** @test */
    public function test_receiving_terminal_state_immutability(): void
    {
        $this->setTenantContext($this->tenantA);

        $receivingPosted = PurchaseReceiving::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'receiving_number' => 'GRV-POSTED',
            'status' => PurchaseReceiving::STATUS_POSTED,
            'received_at' => now(),
            'received_by' => $this->managerA1->id,
        ]);

        PurchaseReceivingLine::create([
            'purchase_receiving_id' => $receivingPosted->id,
            'product_id' => $this->productA->id,
            'received_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $receivingCancelled = PurchaseReceiving::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'receiving_number' => 'GRV-CANCELLED',
            'status' => PurchaseReceiving::STATUS_CANCELLED,
            'received_at' => now(),
            'received_by' => $this->managerA1->id,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($this->managerA1);

        // Posted Receiving cannot be edited or modified
        $this->get(route('procurement.receivings.edit', $receivingPosted->id))
            ->assertRedirect(route('procurement.receivings.show', $receivingPosted->id));

        $this->put(route('procurement.receivings.update', $receivingPosted->id), [
            'supplier_id' => $this->supplierA->id,
            'branch_id' => $this->branchA1->id,
            'received_at' => now()->toDateString(),
            'lines' => [['product_id' => $this->productA->id, 'received_quantity' => 5, 'unit_cost' => 10]],
        ])->assertSessionHasErrors();

        // Posted Receiving cannot be posted again
        $this->post(route('procurement.receivings.post', $receivingPosted->id))->assertSessionHasErrors();

        // Cancelled Receiving cannot be edited or posted
        $this->get(route('procurement.receivings.edit', $receivingCancelled->id))
            ->assertRedirect(route('procurement.receivings.show', $receivingCancelled->id));

        $this->post(route('procurement.receivings.post', $receivingCancelled->id))->assertSessionHasErrors();
    }

    /** @test */
    public function test_non_posting_actions_do_not_mutate_inventory(): void
    {
        $this->setTenantContext($this->tenantA);

        // Set up original stock
        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'product_id' => $this->productA->id,
            'current_stock' => '50.0000',
            'average_cost' => '100.0000',
            'status' => 'active',
        ]);

        $originalMovementsCount = InventoryMovement::count();

        // 1. Supplier Directory CRUD
        Supplier::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'SUP-TEST-DUMMY',
            'name' => 'Dummy Supplier',
        ]);

        $this->assertEquals('50.0000', $inventory->fresh()->current_stock);
        $this->assertEquals($originalMovementsCount, InventoryMovement::count());

        // 2. Purchase Order lifecycle
        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'po_number' => 'PO-INV-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $this->managerA1->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->productA->id,
            'ordered_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $po->update(['status' => PurchaseOrder::STATUS_APPROVED]);

        $this->assertEquals('50.0000', $inventory->fresh()->current_stock);
        $this->assertEquals($originalMovementsCount, InventoryMovement::count());

        // 3. Physical Receiving drafts
        $receiving = PurchaseReceiving::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'receiving_number' => 'GRV-INV-001',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $this->managerA1->id,
        ]);

        PurchaseReceivingLine::create([
            'purchase_receiving_id' => $receiving->id,
            'product_id' => $this->productA->id,
            'received_quantity' => '25.0000',
            'unit_cost' => '120.0000',
            'line_total' => '3000.0000',
        ]);

        $this->assertEquals('50.0000', $inventory->fresh()->current_stock);
        $this->assertEquals($originalMovementsCount, InventoryMovement::count());

        // 4. Exports do not mutate stock
        $this->actingAs($this->managerA1);
        $this->get(route('procurement.purchase-orders.export'))->assertOk();
        $this->get(route('procurement.receivings.export'))->assertOk();

        $this->assertEquals('50.0000', $inventory->fresh()->current_stock);
        $this->assertEquals($originalMovementsCount, InventoryMovement::count());

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_receiving_posting_does_not_mutate_global_product_cost_price(): void
    {
        $this->setTenantContext($this->tenantA);

        $inventory = BranchInventory::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'product_id' => $this->productA->id,
            'current_stock' => '0.0000',
            'average_cost' => '0.0000',
            'status' => 'active',
        ]);

        $receiving = PurchaseReceiving::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'receiving_number' => 'GRV-WAC-TEST',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $this->managerA1->id,
        ]);

        PurchaseReceivingLine::create([
            'purchase_receiving_id' => $receiving->id,
            'product_id' => $this->productA->id,
            'received_quantity' => '10.0000',
            'unit_cost' => '120.0000',
            'line_total' => '1200.0000',
        ]);

        $originalProductCostPrice = $this->productA->cost_price; // 100.0000

        app(TenantContext::class)->clear();

        $this->actingAs($this->managerA1);

        $this->post(route('procurement.receivings.post', $receiving->id))->assertRedirect();

        // 1. Branch WAC must be set to 120.0000
        $this->setTenantContext($this->tenantA);
        $this->assertEquals('120.0000', $inventory->fresh()->average_cost);

        // 2. Global product cost_price must NOT be mutated during branch-level posting (isolated costing scope)
        $this->assertEquals($originalProductCostPrice, $this->productA->fresh()->cost_price);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_procurement_actions_generate_structured_audit_logs(): void
    {
        $this->setTenantContext($this->tenantA);

        $receiving = PurchaseReceiving::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'receiving_number' => 'GRV-AUDIT-TEST',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $this->managerA1->id,
        ]);

        PurchaseReceivingLine::create([
            'purchase_receiving_id' => $receiving->id,
            'product_id' => $this->productA->id,
            'received_quantity' => '10.0000',
            'unit_cost' => '120.0000',
            'line_total' => '1200.0000',
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($this->managerA1);

        // Clear existing logs in test environment to make parsing accurate
        AuditLog::query()->delete();

        // Trigger single PO Export
        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenantA->id,
            'branch_id' => $this->branchA1->id,
            'supplier_id' => $this->supplierA->id,
            'po_number' => 'PO-AUDIT',
            'status' => PurchaseOrder::STATUS_APPROVED,
            'order_date' => now()->toDateString(),
            'created_by' => $this->managerA1->id,
        ]);
        $this->get(route('procurement.purchase-orders.export-one', $po->id))->assertOk();

        // Trigger bulk receiving export
        $this->get(route('procurement.receivings.export'))->assertOk();

        // Trigger post receiving
        $this->post(route('procurement.receivings.post', $receiving->id))->assertRedirect();

        $this->setTenantContext($this->tenantA);

        // Verify Audit Logs have been created
        $logs = AuditLog::all();

        // 1. Verify single PO Export logs
        $poExportLog = $logs->where('action', 'purchase_order_exported')->first();
        $this->assertNotNull($poExportLog);
        $this->assertEquals($po->id, $poExportLog->auditable_id);
        $this->assertEquals($this->tenantA->id, $poExportLog->metadata['tenant_id']);
        $this->assertEquals($this->branchA1->id, $poExportLog->metadata['branch_id']);
        $this->assertEquals($this->managerA1->id, $poExportLog->metadata['actor_id']);

        // 2. Verify bulk receiving export logs
        $bulkExportLog = $logs->where('action', 'purchase_receivings_exported')->first();
        $this->assertNotNull($bulkExportLog);
        $this->assertEquals($this->tenantA->id, $bulkExportLog->metadata['tenant_id']);
        $this->assertEquals($this->managerA1->id, $bulkExportLog->metadata['actor_id']);

        // 3. Verify physical receiving post logs
        $postLog = $logs->where('action', 'purchase_receiving_posted')->first();
        $this->assertNotNull($postLog);
        $this->assertEquals($receiving->id, $postLog->auditable_id);
        $this->assertEquals($this->tenantA->id, $postLog->metadata['tenant_id']);
        $this->assertEquals($this->branchA1->id, $postLog->metadata['branch_id']);
        $this->assertEquals($this->managerA1->id, $postLog->metadata['posted_by']);

        app(TenantContext::class)->clear();
    }
}
