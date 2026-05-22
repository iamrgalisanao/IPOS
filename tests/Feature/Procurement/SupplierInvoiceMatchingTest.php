<?php

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceiving;
use App\Models\PurchaseReceivingLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Procurement\SupplierInvoiceMatchingService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierInvoiceMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected SupplierInvoiceMatchingService $matchingService;
    protected Tenant $tenant;
    protected Branch $branch;
    protected Supplier $supplier;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        $this->matchingService = new SupplierInvoiceMatchingService();

        // Standard test context
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'TEST_SUP',
            'name' => 'Test Supplier Co.',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    /** @test */
    public function test_perfect_happy_path_matched_status(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-HAPPY',
            'status' => 'approved',
            'order_date' => '2026-05-18',
            'created_by' => $this->user->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'unit_cost' => '150.0000',
            'line_total' => '1500.0000',
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-HAPPY',
            'status' => 'posted',
            'received_by' => $this->user->id,
        ]);

        $grvLine = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grv->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'received_quantity' => '10.0000',
            'unit_cost' => '150.0000',
            'line_total' => '1500.0000',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'purchase_receiving_id' => $grv->id,
            'invoice_number' => 'INV-HAPPY',
            'invoice_date' => '2026-05-18',
            'subtotal' => '1500.0000',
            'tax_total' => '0.0000',
            'total_amount' => '1500.0000',
            'created_by' => $this->user->id,
        ]);

        $invoiceLine = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_receiving_line_id' => $grvLine->id,
            'product_id' => $product->id,
            'quantity_billed' => '10.0000',
            'unit_cost_billed' => '150.0000',
            'line_total' => '1500.0000',
        ]);

        $updatedInvoice = $this->matchingService->match($invoice);

        $this->assertTrue($updatedInvoice->isMatched());
        $this->assertFalse($updatedInvoice->isDiscrepant());

        $metadata = $updatedInvoice->matching_metadata;
        $this->assertTrue($metadata['is_matched']);
        $this->assertEquals(0, count($metadata['discrepancies']));
        $this->assertEquals(1500.0000, $metadata['invoice_summary']['billed_total']);
        $this->assertEquals(1500.0000, $metadata['invoice_summary']['expected_total']);
        $this->assertEquals(0.0000, $metadata['invoice_summary']['total_variance']);
    }

    /** @test */
    public function test_under_billing_allowed_status(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-UNDER',
            'status' => 'approved',
            'order_date' => '2026-05-18',
            'created_by' => $this->user->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-UNDER',
            'status' => 'posted',
            'received_by' => $this->user->id,
        ]);

        $grvLine = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grv->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'received_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-UNDER',
            'invoice_date' => '2026-05-18',
            'subtotal' => '600.0000',
            'tax_total' => '0.0000',
            'total_amount' => '600.0000',
            'created_by' => $this->user->id,
        ]);

        // Under-billed: Qty Billed = 6 (instead of 10 received)
        $invoiceLine = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_receiving_line_id' => $grvLine->id,
            'product_id' => $product->id,
            'quantity_billed' => '6.0000',
            'unit_cost_billed' => '100.0000',
            'line_total' => '600.0000',
        ]);

        $updatedInvoice = $this->matchingService->match($invoice);

        $this->assertTrue($updatedInvoice->isMatched());
        $this->assertFalse($updatedInvoice->isDiscrepant());
        $this->assertCount(0, $updatedInvoice->matching_metadata['discrepancies']);
    }

    /** @test */
    public function test_over_billing_quantity_causes_discrepant_status(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-OVERQTY',
            'status' => 'approved',
            'order_date' => '2026-05-18',
            'created_by' => $this->user->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-OVERQTY',
            'status' => 'posted',
            'received_by' => $this->user->id,
        ]);

        $grvLine = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grv->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'received_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-OVERQTY',
            'invoice_date' => '2026-05-18',
            'subtotal' => '1200.0000',
            'tax_total' => '0.0000',
            'total_amount' => '1200.0000',
            'created_by' => $this->user->id,
        ]);

        // Over-billed: Qty Billed = 12 (instead of 10 received)
        $invoiceLine = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_receiving_line_id' => $grvLine->id,
            'product_id' => $product->id,
            'quantity_billed' => '12.0000',
            'unit_cost_billed' => '100.0000',
            'line_total' => '1200.0000',
        ]);

        $updatedInvoice = $this->matchingService->match($invoice);

        $this->assertTrue($updatedInvoice->isDiscrepant());
        $this->assertFalse($updatedInvoice->isMatched());

        $metadata = $updatedInvoice->matching_metadata;
        $this->assertCount(1, $metadata['discrepancies']);
        $this->assertEquals('over_billed_quantity', $metadata['discrepancies'][0]['type']);
        $this->assertEquals(12.0, $metadata['discrepancies'][0]['billed_qty']);
        $this->assertEquals(10.0, $metadata['discrepancies'][0]['received_qty']);
    }

    /** @test */
    public function test_price_increase_within_tolerance_is_matched(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-WITHINTOL',
            'status' => 'approved',
            'order_date' => '2026-05-18',
            'created_by' => $this->user->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-WITHINTOL',
            'status' => 'posted',
            'received_by' => $this->user->id,
        ]);

        $grvLine = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grv->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'received_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-WITHINTOL',
            'invoice_date' => '2026-05-18',
            'subtotal' => '1005.0000',
            'tax_total' => '0.0000',
            'total_amount' => '1005.0000',
            'created_by' => $this->user->id,
        ]);

        // Price = 100.5 (0.5% variance, which is <= 1.0% default price tolerance)
        $invoiceLine = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_receiving_line_id' => $grvLine->id,
            'product_id' => $product->id,
            'quantity_billed' => '10.0000',
            'unit_cost_billed' => '100.5000',
            'line_total' => '1005.0000',
        ]);

        $updatedInvoice = $this->matchingService->match($invoice);

        $this->assertTrue($updatedInvoice->isMatched());
        $this->assertCount(0, $updatedInvoice->matching_metadata['discrepancies']);
    }

    /** @test */
    public function test_price_increase_exceeding_tolerance_causes_discrepant_status(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-EXCEEDTOL',
            'status' => 'approved',
            'order_date' => '2026-05-18',
            'created_by' => $this->user->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-EXCEEDTOL',
            'status' => 'posted',
            'received_by' => $this->user->id,
        ]);

        $grvLine = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grv->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $product->id,
            'ordered_quantity' => '10.0000',
            'received_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-EXCEEDTOL',
            'invoice_date' => '2026-05-18',
            'subtotal' => '1020.0000',
            'tax_total' => '0.0000',
            'total_amount' => '1020.0000',
            'created_by' => $this->user->id,
        ]);

        // Price = 102.0 (2.0% variance, which is > 1.0% default price tolerance)
        $invoiceLine = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_receiving_line_id' => $grvLine->id,
            'product_id' => $product->id,
            'quantity_billed' => '10.0000',
            'unit_cost_billed' => '102.0000',
            'line_total' => '1020.0000',
        ]);

        $updatedInvoice = $this->matchingService->match($invoice, 0.01, 100.00);

        $this->assertTrue($updatedInvoice->isDiscrepant());
        $this->assertCount(1, $updatedInvoice->matching_metadata['discrepancies']);
        $this->assertEquals('price_variance', $updatedInvoice->matching_metadata['discrepancies'][0]['type']);
        $this->assertEquals(102.0, $updatedInvoice->matching_metadata['discrepancies'][0]['billed_cost']);
        $this->assertEquals(100.0, $updatedInvoice->matching_metadata['discrepancies'][0]['ordered_cost']);
        $this->assertEquals(0.02, $updatedInvoice->matching_metadata['discrepancies'][0]['variance_percent']);
    }

    /** @test */
    public function test_cumulative_absolute_variance_causes_discrepant_status(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-CUMULATIVE',
            'status' => 'approved',
            'order_date' => '2026-05-18',
            'created_by' => $this->user->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'ordered_quantity' => '1000.0000',
            'unit_cost' => '10.0000',
            'line_total' => '10000.0000',
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-CUMULATIVE',
            'status' => 'posted',
            'received_by' => $this->user->id,
        ]);

        $grvLine = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grv->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $product->id,
            'ordered_quantity' => '1000.0000',
            'received_quantity' => '1000.0000',
            'unit_cost' => '10.0000',
            'line_total' => '10000.0000',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-CUMULATIVE',
            'invoice_date' => '2026-05-18',
            'subtotal' => '10080.0000',
            'tax_total' => '0.0000',
            'total_amount' => '10080.0000',
            'created_by' => $this->user->id,
        ]);

        // Price = 10.08 (0.8% increase is below 1% line tolerance, but total absolute variance is 80.00, above the 5.00 limit)
        $invoiceLine = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_receiving_line_id' => $grvLine->id,
            'product_id' => $product->id,
            'quantity_billed' => '1000.0000',
            'unit_cost_billed' => '10.0800',
            'line_total' => '10080.0000',
        ]);

        $updatedInvoice = $this->matchingService->match($invoice);

        $this->assertTrue($updatedInvoice->isDiscrepant());
        $this->assertCount(1, $updatedInvoice->matching_metadata['discrepancies']);
        $this->assertEquals('cumulative_price_variance', $updatedInvoice->matching_metadata['discrepancies'][0]['type']);
        $this->assertEquals(80.0, $updatedInvoice->matching_metadata['discrepancies'][0]['total_variance']);
    }

    /** @test */
    public function test_unlinked_line_causes_discrepant_status(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-UNLINKED',
            'invoice_date' => '2026-05-18',
            'subtotal' => '150.0000',
            'tax_total' => '0.0000',
            'total_amount' => '150.0000',
            'created_by' => $this->user->id,
        ]);

        // Unlinked: `purchase_receiving_line_id` is null
        $invoiceLine = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_receiving_line_id' => null,
            'product_id' => $product->id,
            'quantity_billed' => '1.0000',
            'unit_cost_billed' => '150.0000',
            'line_total' => '150.0000',
        ]);

        $updatedInvoice = $this->matchingService->match($invoice, 0.01, 1000.00);

        $this->assertTrue($updatedInvoice->isDiscrepant());
        $this->assertCount(1, $updatedInvoice->matching_metadata['discrepancies']);
        $this->assertEquals('unlinked_line', $updatedInvoice->matching_metadata['discrepancies'][0]['type']);
    }

    /** @test */
    public function test_respects_tenant_isolation_during_matching(): void
    {
        $tenantA = $this->tenant;
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // Set context to Tenant B
        $this->setTenantContext($tenantB);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $supplierB = Supplier::create([
            'tenant_id' => $tenantB->id,
            'code' => 'SUPB',
            'name' => 'Supplier B',
        ]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $productB = Product::factory()->create(['tenant_id' => $tenantB->id]);

        $poB = PurchaseOrder::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'supplier_id' => $supplierB->id,
            'po_number' => 'PO-TENANTB',
            'status' => 'approved',
            'order_date' => '2026-05-18',
            'created_by' => $userB->id,
        ]);

        $poLineB = PurchaseOrderLine::create([
            'purchase_order_id' => $poB->id,
            'product_id' => $productB->id,
            'ordered_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $grvB = PurchaseReceiving::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'supplier_id' => $supplierB->id,
            'purchase_order_id' => $poB->id,
            'receiving_number' => 'GRV-TENANTB',
            'status' => 'posted',
            'received_by' => $userB->id,
        ]);

        $grvLineB = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grvB->id,
            'purchase_order_line_id' => $poLineB->id,
            'product_id' => $productB->id,
            'ordered_quantity' => '10.0000',
            'received_quantity' => '10.0000',
            'unit_cost' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        $invoiceB = SupplierInvoice::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'supplier_id' => $supplierB->id,
            'invoice_number' => 'INV-TENANTB',
            'invoice_date' => '2026-05-18',
            'subtotal' => '1000.0000',
            'tax_total' => '0.0000',
            'total_amount' => '1000.0000',
            'created_by' => $userB->id,
        ]);

        $invoiceLineB = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoiceB->id,
            'purchase_receiving_line_id' => $grvLineB->id,
            'product_id' => $productB->id,
            'quantity_billed' => '10.0000',
            'unit_cost_billed' => '100.0000',
            'line_total' => '1000.0000',
        ]);

        // Switched context back to Tenant A
        $this->setTenantContext($tenantA);

        // Run matching on Tenant B's invoice while context is Tenant A
        // Because of BelongsToTenant global scope, Tenant B's invoice will not even load or resolve lines, or query across tenants
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        // Trying to find and match Tenant B's invoice under Tenant A context fails closed!
        $invoiceFromDB = SupplierInvoice::findOrFail($invoiceB->id);
        $this->matchingService->match($invoiceFromDB);
    }
}
