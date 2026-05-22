<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceiving;
use App\Models\PurchaseReceivingLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierInvoiceSchemaTest extends TestCase
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
    public function test_can_create_supplier_invoice_with_default_pending_status(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-1001',
            'invoice_date' => '2026-05-18',
            'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoice->id,
            'invoice_number' => 'INV-1001',
            'match_status' => 'pending',
            'subtotal' => '0.0000',
            'tax_total' => '0.0000',
            'total_amount' => '0.0000',
        ]);

        $this->assertTrue($invoice->isPending());
        $this->assertFalse($invoice->isMatched());
        $this->assertFalse($invoice->isDiscrepant());
        $this->assertFalse($invoice->isPosted());
    }

    /** @test */
    public function test_blocks_duplicate_invoice_numbers_for_same_supplier_and_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-UNIQUE',
            'invoice_date' => '2026-05-18',
            'created_by' => $user->id,
        ]);

        $this->expectException(QueryException::class);

        SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-UNIQUE',
            'invoice_date' => '2026-05-18',
            'created_by' => $user->id,
        ]);
    }

    /** @test */
    public function test_allows_same_invoice_number_for_different_suppliers_on_same_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier1 = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);
        $supplier2 = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'PEPS',
            'name' => 'Pepsi Co',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $invoice1 = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier1->id,
            'invoice_number' => 'INV-SAME',
            'invoice_date' => '2026-05-18',
            'created_by' => $user->id,
        ]);

        $invoice2 = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier2->id,
            'invoice_number' => 'INV-SAME',
            'invoice_date' => '2026-05-18',
            'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice1->id]);
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice2->id]);
    }

    /** @test */
    public function test_allows_same_invoice_number_for_same_supplier_on_different_tenants(): void
    {
        $tenant1 = Tenant::factory()->create(['status' => 'active']);
        $tenant2 = Tenant::factory()->create(['status' => 'active']);

        $this->setTenantContext($tenant1);
        $branch1 = Branch::factory()->create(['tenant_id' => $tenant1->id]);
        $supplier1 = Supplier::create([
            'tenant_id' => $tenant1->id,
            'code' => 'SUP1',
            'name' => 'Supplier One',
        ]);
        $user1 = User::factory()->create(['tenant_id' => $tenant1->id]);

        $invoice1 = SupplierInvoice::create([
            'tenant_id' => $tenant1->id,
            'branch_id' => $branch1->id,
            'supplier_id' => $supplier1->id,
            'invoice_number' => 'INV-CROSS',
            'invoice_date' => '2026-05-18',
            'created_by' => $user1->id,
        ]);

        $this->setTenantContext($tenant2);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant2->id]);
        $supplier2 = Supplier::create([
            'tenant_id' => $tenant2->id,
            'code' => 'SUP1',
            'name' => 'Supplier One',
        ]);
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

        $invoice2 = SupplierInvoice::create([
            'tenant_id' => $tenant2->id,
            'branch_id' => $branch2->id,
            'supplier_id' => $supplier2->id,
            'invoice_number' => 'INV-CROSS',
            'invoice_date' => '2026-05-18',
            'created_by' => $user2->id,
        ]);

        $this->setTenantContext($tenant1);
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice1->id]);

        $this->setTenantContext($tenant2);
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice2->id]);
    }

    /** @test */
    public function test_resolves_all_relations_correctly(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $this->setTenantContext($tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'code' => 'COCA',
            'name' => 'Coca-Cola',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-001',
            'status' => 'approved',
            'order_date' => '2026-05-18',
            'created_by' => $user->id,
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-001',
            'status' => 'posted',
            'received_by' => $user->id,
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'purchase_receiving_id' => $grv->id,
            'invoice_number' => 'INV-1002',
            'invoice_date' => '2026-05-18',
            'subtotal' => '150.0000',
            'tax_total' => '18.0000',
            'total_amount' => '168.0000',
            'created_by' => $user->id,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $grvLine = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grv->id,
            'product_id' => $product->id,
            'received_quantity' => '10.0000',
            'unit_cost' => '15.0000',
            'line_total' => '150.0000',
        ]);

        $line = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_receiving_line_id' => $grvLine->id,
            'product_id' => $product->id,
            'quantity_billed' => '10.0000',
            'unit_cost_billed' => '15.0000',
            'line_total' => '150.0000',
        ]);

        // Assert relationships resolve
        $this->assertEquals($tenant->id, $invoice->tenant->id);
        $this->assertEquals($branch->id, $invoice->branch->id);
        $this->assertEquals($supplier->id, $invoice->supplier->id);
        $this->assertEquals($po->id, $invoice->purchaseOrder->id);
        $this->assertEquals($grv->id, $invoice->purchaseReceiving->id);
        $this->assertEquals($user->id, $invoice->createdBy->id);

        $this->assertCount(1, $invoice->lines);
        $this->assertEquals($line->id, $invoice->lines->first()->id);

        // Line-level relationships
        $lineResolved = $invoice->lines->first();
        $this->assertEquals($invoice->id, $lineResolved->supplierInvoice->id);
        $this->assertEquals($grvLine->id, $lineResolved->purchaseReceivingLine->id);
        $this->assertEquals($product->id, $lineResolved->product->id);

        // Inverse relations
        $this->assertCount(1, $tenant->fresh()->supplierInvoices);
        $this->assertCount(1, $supplier->fresh()->supplierInvoices);
    }

    /** @test */
    public function test_respects_tenant_isolation_scopes(): void
    {
        $tenant1 = Tenant::factory()->create(['status' => 'active']);
        $tenant2 = Tenant::factory()->create(['status' => 'active']);

        // Create Invoice on Tenant 1
        $this->setTenantContext($tenant1);
        $branch1 = Branch::factory()->create(['tenant_id' => $tenant1->id]);
        $supplier1 = Supplier::create([
            'tenant_id' => $tenant1->id,
            'code' => 'SUP1',
            'name' => 'Supplier One',
        ]);
        $user1 = User::factory()->create(['tenant_id' => $tenant1->id]);

        $invoice1 = SupplierInvoice::create([
            'tenant_id' => $tenant1->id,
            'branch_id' => $branch1->id,
            'supplier_id' => $supplier1->id,
            'invoice_number' => 'INV-TEN-1',
            'invoice_date' => '2026-05-18',
            'created_by' => $user1->id,
        ]);

        // Create Invoice on Tenant 2
        $this->setTenantContext($tenant2);
        $branch2 = Branch::factory()->create(['tenant_id' => $tenant2->id]);
        $supplier2 = Supplier::create([
            'tenant_id' => $tenant2->id,
            'code' => 'SUP2',
            'name' => 'Supplier Two',
        ]);
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

        $invoice2 = SupplierInvoice::create([
            'tenant_id' => $tenant2->id,
            'branch_id' => $branch2->id,
            'supplier_id' => $supplier2->id,
            'invoice_number' => 'INV-TEN-2',
            'invoice_date' => '2026-05-18',
            'created_by' => $user2->id,
        ]);

        // Set Tenant 1 Context and assert Tenant 2 invoices are not visible
        $this->setTenantContext($tenant1);
        $this->assertCount(1, SupplierInvoice::all());
        $this->assertEquals($invoice1->id, SupplierInvoice::first()->id);

        // Set Tenant 2 Context and assert Tenant 1 invoices are not visible
        $this->setTenantContext($tenant2);
        $this->assertCount(1, SupplierInvoice::all());
        $this->assertEquals($invoice2->id, SupplierInvoice::first()->id);
    }
}
