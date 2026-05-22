<?php

namespace Tests\Feature\Procurement;

use App\Models\AccountingOutbox;
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
use App\Services\AuditLogger;
use App\Services\Accounting\AccountingOutboxService;
use App\Services\Procurement\SupplierInvoicePostingService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierInvoicePostingTest extends TestCase
{
    use RefreshDatabase;

    protected SupplierInvoicePostingService $postingService;
    protected Tenant $tenant;
    protected Branch $branch;
    protected Supplier $supplier;
    protected User $user;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        $this->postingService = new SupplierInvoicePostingService(
            app(AuditLogger::class),
            app(AccountingOutboxService::class),
        );

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->supplier = Supplier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'INV_SUP',
            'name' => 'Invoice Supplier Ltd.',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function setTenantContext(Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
    }

    /**
     * Build a matched invoice with a linked PO / GRV chain.
     */
    private function buildMatchedInvoice(
        string $invoiceNumber = 'INV-POST-001',
        string $qty = '10.0000',
        string $unitCost = '100.0000'
    ): array {
        $po = PurchaseOrder::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-' . $invoiceNumber,
            'status' => 'approved',
            'order_date' => '2026-05-18',
            'created_by' => $this->user->id,
        ]);

        $poLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'ordered_quantity' => $qty,
            'unit_cost' => $unitCost,
            'line_total' => bcmul($qty, $unitCost, 4),
        ]);

        $grv = PurchaseReceiving::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'receiving_number' => 'GRV-' . $invoiceNumber,
            'status' => 'posted',
            'received_by' => $this->user->id,
        ]);

        $grvLine = PurchaseReceivingLine::create([
            'purchase_receiving_id' => $grv->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $this->product->id,
            'ordered_quantity' => $qty,
            'received_quantity' => $qty,
            'unit_cost' => $unitCost,
            'line_total' => bcmul($qty, $unitCost, 4),
        ]);

        $lineTotal = bcmul($qty, $unitCost, 4);
        $invoice = SupplierInvoice::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'purchase_receiving_id' => $grv->id,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => '2026-05-18',
            'subtotal' => $lineTotal,
            'tax_total' => '0.0000',
            'total_amount' => $lineTotal,
            'match_status' => SupplierInvoice::STATUS_MATCHED,
            'created_by' => $this->user->id,
        ]);

        $invoiceLine = SupplierInvoiceLine::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_receiving_line_id' => $grvLine->id,
            'product_id' => $this->product->id,
            'quantity_billed' => $qty,
            'unit_cost_billed' => $unitCost,
            'line_total' => $lineTotal,
        ]);

        return compact('invoice', 'invoiceLine', 'po', 'grv');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Happy Path Tests
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_posting_matched_invoice_transitions_to_posted_status(): void
    {
        ['invoice' => $invoice] = $this->buildMatchedInvoice();

        $posted = $this->postingService->post($invoice, $this->user->id);

        $this->assertTrue($posted->isPosted());
        $this->assertEquals($this->user->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
    }

    /** @test */
    public function test_posting_creates_accounting_outbox_row_with_correct_event_type(): void
    {
        ['invoice' => $invoice] = $this->buildMatchedInvoice('INV-OUTBOX-001');

        $this->postingService->post($invoice, $this->user->id);

        $outbox = AccountingOutbox::where('source_type', 'supplier_invoice')
            ->where('source_id', $invoice->id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('supplier_invoice_posted', $outbox->event_type);
        $this->assertEquals('pending', $outbox->sync_status);
        $this->assertEquals($this->tenant->id, $outbox->tenant_id);
        $this->assertEquals($this->branch->id, $outbox->branch_id);
    }

    /** @test */
    public function test_outbox_payload_contains_correct_invoice_and_line_data(): void
    {
        ['invoice' => $invoice, 'invoiceLine' => $invoiceLine] = $this->buildMatchedInvoice('INV-PAYLOAD-001', '10.0000', '150.0000');

        $this->postingService->post($invoice, $this->user->id);

        $outbox = AccountingOutbox::where('source_id', $invoice->id)->first();
        $payload = $outbox->payload;

        // Invoice-level fields
        $this->assertEquals($invoice->id, $payload['supplier_invoice_id']);
        $this->assertEquals('INV-PAYLOAD-001', $payload['invoice_number']);
        $this->assertEquals($this->supplier->id, $payload['supplier_id']);
        $this->assertEquals('1500.0000', $payload['subtotal']);
        $this->assertEquals('1500.0000', $payload['total_amount']);

        // Line-level fields
        $this->assertCount(1, $payload['lines']);
        $line = $payload['lines'][0];
        $this->assertEquals($this->product->id, $line['product_id']);
        $this->assertEquals($this->product->name, $line['product_name']);
        $this->assertEquals('10.0000', $line['quantity_billed']);
        $this->assertEquals('150.0000', $line['unit_cost_billed']);
        $this->assertEquals('1500.0000', $line['line_total']);
    }

    /** @test */
    public function test_posting_writes_audit_log(): void
    {
        ['invoice' => $invoice] = $this->buildMatchedInvoice('INV-AUDIT-001');

        $this->postingService->post($invoice, $this->user->id);

        $auditLog = \App\Models\AuditLog::where('action', 'supplier_invoice_posted')
            ->where('auditable_id', $invoice->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($this->tenant->id, $auditLog->metadata['tenant_id']);
        $this->assertEquals('INV-AUDIT-001', $auditLog->metadata['invoice_number']);
        $this->assertEquals($this->user->id, $auditLog->metadata['posted_by']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Guard / Precondition Tests
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_posting_pending_invoice_throws_runtime_exception(): void
    {
        $invoice = SupplierInvoice::create([
            'tenant_id'      => $this->tenant->id,
            'branch_id'      => $this->branch->id,
            'supplier_id'    => $this->supplier->id,
            'invoice_number' => 'INV-PENDING-001',
            'invoice_date'   => '2026-05-18',
            'match_status'   => SupplierInvoice::STATUS_PENDING,
            'created_by'     => $this->user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only matched supplier invoices can be posted');

        $this->postingService->post($invoice, $this->user->id);
    }

    /** @test */
    public function test_posting_discrepant_invoice_throws_runtime_exception(): void
    {
        $invoice = SupplierInvoice::create([
            'tenant_id'      => $this->tenant->id,
            'branch_id'      => $this->branch->id,
            'supplier_id'    => $this->supplier->id,
            'invoice_number' => 'INV-DISCREPANT-001',
            'invoice_date'   => '2026-05-18',
            'match_status'   => SupplierInvoice::STATUS_DISCREPANT,
            'created_by'     => $this->user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only matched supplier invoices can be posted');

        $this->postingService->post($invoice, $this->user->id);
    }

    /** @test */
    public function test_immutability_guard_prevents_status_change_on_posted_invoice(): void
    {
        ['invoice' => $invoice] = $this->buildMatchedInvoice('INV-IMMUTABLE-001');

        $posted = $this->postingService->post($invoice, $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/posted and immutable/');

        $posted->update(['match_status' => SupplierInvoice::STATUS_PENDING]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Idempotency / Rollback Tests
    // ──────────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_double_posting_is_rejected_and_leaves_single_outbox_row(): void
    {
        ['invoice' => $invoice] = $this->buildMatchedInvoice('INV-DUPE-001');

        // First post — succeeds
        $this->postingService->post($invoice, $this->user->id);

        $this->assertEquals(1, AccountingOutbox::where('source_id', $invoice->id)->count());

        // Second post attempt — must be rejected (invoice is now `posted`)
        try {
            $this->postingService->post($invoice->fresh(), $this->user->id);
            $this->fail('Expected RuntimeException on second post attempt was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Only matched supplier invoices can be posted', $e->getMessage());
        }

        // Still only one outbox row — no duplicate written
        $this->assertEquals(
            1,
            AccountingOutbox::where('source_id', $invoice->id)->count(),
            'Expected exactly one outbox row even after rejected second post.'
        );
    }

    /** @test */
    public function test_posted_invoice_status_is_persisted_in_database(): void
    {
        ['invoice' => $invoice] = $this->buildMatchedInvoice('INV-DB-PERSIST-001');

        $this->postingService->post($invoice, $this->user->id);

        $this->assertDatabaseHas('supplier_invoices', [
            'id'           => $invoice->id,
            'match_status' => SupplierInvoice::STATUS_POSTED,
        ]);
    }

    /** @test */
    public function test_outbox_row_and_posted_status_committed_together(): void
    {
        ['invoice' => $invoice] = $this->buildMatchedInvoice('INV-ATOMIC-001');

        $this->postingService->post($invoice, $this->user->id);

        // Both must be in DB together
        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoice->id,
            'match_status' => SupplierInvoice::STATUS_POSTED,
        ]);

        $this->assertEquals(
            1,
            AccountingOutbox::where('source_id', $invoice->id)->count()
        );
    }
}
