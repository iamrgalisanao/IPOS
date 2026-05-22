<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementCsvExportTest extends TestCase
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
    public function test_unauthorized_user_is_completely_blocked_from_all_export_routes(): void
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
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-TEST-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $cashier->id,
        ]);

        $receiving = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-TEST-001',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $cashier->id,
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($cashier);

        // PO exports blocked
        $this->get(route('procurement.purchase-orders.export'))->assertStatus(403);
        $this->get(route('procurement.purchase-orders.export-one', $po->id))->assertStatus(403);

        // Receiving exports blocked
        $this->get(route('procurement.receivings.export'))->assertStatus(403);
        $this->get(route('procurement.receivings.export-one', $receiving->id))->assertStatus(403);
    }

    /** @test */
    public function test_authorized_user_can_export_bulk_and_single_purchase_orders(): void
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
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'sku' => 'PROD001', 'name' => 'Test Product']);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-TEST-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
            'total_estimated_amount' => 125.00,
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => 10,
            'received_quantity' => 0,
            'unit_cost' => 12.50,
            'line_total' => 125.00,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Bulk Export
        $responseBulk = $this->get(route('procurement.purchase-orders.export'));
        $responseBulk->assertStatus(200);
        $responseBulk->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        
        $csvContentBulk = $responseBulk->getContent();
        $this->assertStringContainsString('po_number,branch,supplier,status', $csvContentBulk);
        $this->assertStringContainsString('PO-TEST-001', $csvContentBulk);
        $this->assertStringContainsString('PROD001', $csvContentBulk);

        // Single Export
        $responseOne = $this->get(route('procurement.purchase-orders.export-one', $po->id));
        $responseOne->assertStatus(200);
        $responseOne->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csvContentOne = $responseOne->getContent();
        $this->assertStringContainsString('PO-TEST-001', $csvContentOne);

        // Audit Log verification
        $this->setTenantContext($tenant);
        $this->assertTrue(AuditLog::where('action', 'purchase_orders_exported')->exists());
        $this->assertTrue(AuditLog::where('action', 'purchase_order_exported')->exists());
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_authorized_user_can_export_bulk_and_single_purchase_receivings(): void
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
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);

        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'sku' => 'PROD001', 'name' => 'Test Product']);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-TEST-001',
            'status' => PurchaseOrder::STATUS_APPROVED,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        $receiving = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-TEST-001',
            'status' => PurchaseReceiving::STATUS_DRAFT,
            'received_at' => now(),
            'received_by' => $manager->id,
            'total_received_amount' => 125.00,
        ]);

        PurchaseReceivingLine::create([
            'purchase_receiving_id' => $receiving->id,
            'product_id' => $product->id,
            'ordered_quantity' => 10,
            'received_quantity' => 10,
            'unit_cost' => 12.50,
            'line_total' => 125.00,
            'lot_number' => 'LOT123',
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        // Bulk Export
        $responseBulk = $this->get(route('procurement.receivings.export'));
        $responseBulk->assertStatus(200);
        $responseBulk->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        
        $csvContentBulk = $responseBulk->getContent();
        $this->assertStringContainsString('receiving_number,branch,supplier,purchase_order_number', $csvContentBulk);
        $this->assertStringContainsString('GRV-TEST-001', $csvContentBulk);
        $this->assertStringContainsString('LOT123', $csvContentBulk);

        // Single Export
        $responseOne = $this->get(route('procurement.receivings.export-one', $receiving->id));
        $responseOne->assertStatus(200);
        $responseOne->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csvContentOne = $responseOne->getContent();
        $this->assertStringContainsString('GRV-TEST-001', $csvContentOne);

        // Audit Log verification
        $this->setTenantContext($tenant);
        $this->assertTrue(AuditLog::where('action', 'purchase_receivings_exported')->exists());
        $this->assertTrue(AuditLog::where('action', 'purchase_receiving_exported')->exists());
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_excel_injection_csv_cell_protection(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($tenant);

        $this->setTenantContext($tenant);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager->assignRole(Role::where('name', 'Branch Manager')->first());

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => '=CriticalBranch']);
        $manager->assignToBranch($branch);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => '+DangerousSupplier',
        ]);

        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'sku' => '-SKU001', 'name' => '@InjectProduct']);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-TEST-001',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => 10,
            'received_quantity' => 0,
            'unit_cost' => 12.50,
            'line_total' => 125.00,
        ]);

        app(TenantContext::class)->clear();

        $this->actingAs($manager);

        $response = $this->get(route('procurement.purchase-orders.export-one', $po->id));
        $response->assertStatus(200);

        $csvContent = $response->getContent();
        
        // Assert that cell injection characters are properly prefixed with single quote '
        $this->assertStringContainsString("'=CriticalBranch", $csvContent);
        $this->assertStringContainsString("'+DangerousSupplier", $csvContent);
        $this->assertStringContainsString("'-SKU001", $csvContent);
        $this->assertStringContainsString("'@InjectProduct", $csvContent);
    }

    /** @test */
    public function test_tenant_and_branch_isolation_on_exports(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(RbacSeeder::class)->seedForTenant($tenantA);
        app(RbacSeeder::class)->seedForTenant($tenantB);

        // Tenant A user assigned to Branch A
        $this->setTenantContext($tenantA);
        $managerA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $managerA->assignRole(Role::where('name', 'Branch Manager')->first());
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id]);
        $managerA->assignToBranch($branchA);
        $supplierA = Supplier::create(['tenant_id' => $tenantA->id, 'code' => 'SUPA', 'name' => 'Supplier A']);
        $productA = Product::factory()->create(['tenant_id' => $tenantA->id]);
        
        $poA = PurchaseOrder::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'supplier_id' => $supplierA->id,
            'po_number' => 'PO-TENANT-A',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $managerA->id,
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $poA->id,
            'product_id' => $productA->id,
            'ordered_quantity' => 5,
            'received_quantity' => 0,
            'unit_cost' => 10.00,
            'line_total' => 50.00,
        ]);
        app(TenantContext::class)->clear();

        // Tenant B setup
        $this->setTenantContext($tenantB);
        $managerB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $supplierB = Supplier::create(['tenant_id' => $tenantB->id, 'code' => 'SUPB', 'name' => 'Supplier B']);
        $productB = Product::factory()->create(['tenant_id' => $tenantB->id]);
        
        $poB = PurchaseOrder::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'supplier_id' => $supplierB->id,
            'po_number' => 'PO-TENANT-B',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now()->toDateString(),
            'created_by' => $managerB->id,
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $poB->id,
            'product_id' => $productB->id,
            'ordered_quantity' => 10,
            'received_quantity' => 0,
            'unit_cost' => 12.00,
            'line_total' => 120.00,
        ]);
        app(TenantContext::class)->clear();

        // Acting as Manager A
        $this->actingAs($managerA);

        // 1. Bulk export must only contain Tenant A / Branch A PO
        $responseBulk = $this->get(route('procurement.purchase-orders.export'));
        $responseBulk->assertStatus(200);
        $csvContentBulk = $responseBulk->getContent();
        
        $this->assertStringContainsString('PO-TENANT-A', $csvContentBulk);
        $this->assertStringNotContainsString('PO-TENANT-B', $csvContentBulk);

        // 2. Export single PO from other tenant/branch must be forbidden (403 or 404 depending on middleware/route-binding)
        // Since Laravel UUID binding scopes it to global or tenant scope, it should not be accessible.
        // The policy/controller checks user's branch access.
        $this->get(route('procurement.purchase-orders.export-one', $poB->id))->assertStatus(404);
    }
}
