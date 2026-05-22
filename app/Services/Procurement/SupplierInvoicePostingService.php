<?php

namespace App\Services\Procurement;

use App\Models\SupplierInvoice;
use App\Services\AuditLogger;
use App\Services\Accounting\AccountingOutboxService;
use Illuminate\Support\Facades\DB;

class SupplierInvoicePostingService
{
    public function __construct(
        protected AuditLogger $auditLogger,
        protected AccountingOutboxService $outboxService,
    ) {}

    /**
     * Post a matched Supplier Invoice, serializing a QBO Bill payload to the Accounting Outbox.
     *
     * Precondition: Invoice must be in `matched` status.
     * The entire operation (status transition + outbox row) is atomic inside DB::transaction.
     */
    public function post(SupplierInvoice $invoice, string $postedBy): SupplierInvoice
    {
        if (!$invoice->isMatched()) {
            throw new \RuntimeException(
                "Only matched supplier invoices can be posted. Current status: {$invoice->match_status}"
            );
        }

        return DB::transaction(function () use ($invoice, $postedBy) {
            // Pessimistic lock to prevent race-condition double posting
            $invoice = SupplierInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            // Idempotency guard — if a concurrent request already posted it, exit cleanly
            if ($invoice->isPosted()) {
                return $invoice;
            }

            if (!$invoice->isMatched()) {
                throw new \RuntimeException(
                    "Supplier invoice status changed unexpectedly during posting transaction."
                );
            }

            $postedAt = now();

            // Load lines with product for payload serialization
            $lines = $invoice->lines()->with('product')->get();

            // Transition to posted
            $invoice->forceFill([
                'match_status' => SupplierInvoice::STATUS_POSTED,
                'posted_by'    => $postedBy,
                'posted_at'    => $postedAt,
            ])->save();

            // Audit Log
            $this->auditLogger->log(
                action: 'supplier_invoice_posted',
                auditable: $invoice,
                metadata: [
                    'tenant_id'          => $invoice->tenant_id,
                    'branch_id'          => $invoice->branch_id,
                    'supplier_invoice_id' => $invoice->id,
                    'invoice_number'     => $invoice->invoice_number,
                    'posted_by'          => $postedBy,
                    'posted_at'          => $postedAt->toIso8601String(),
                    'total_amount'       => (string) $invoice->total_amount,
                ]
            );

            // Push QBO Bill event to Accounting Outbox inside the transaction
            $this->outboxService->recordEvent('supplier_invoice_posted', $invoice, [
                'supplier_invoice_id'  => $invoice->id,
                'invoice_number'       => $invoice->invoice_number,
                'supplier_id'          => $invoice->supplier_id,
                'purchase_order_id'    => $invoice->purchase_order_id,
                'purchase_receiving_id' => $invoice->purchase_receiving_id,
                'invoice_date'         => (string) $invoice->invoice_date,
                'due_date'             => $invoice->due_date ? (string) $invoice->due_date : null,
                'subtotal'             => (string) $invoice->subtotal,
                'tax_total'            => (string) $invoice->tax_total,
                'total_amount'         => (string) $invoice->total_amount,
                'posted_at'            => $postedAt->toIso8601String(),
                'notes'                => $invoice->notes,
                'lines'                => $lines->map(fn ($line) => [
                    'supplier_invoice_line_id' => $line->id,
                    'product_id'               => $line->product_id,
                    'product_name'             => $line->product?->name,
                    'quantity_billed'          => (string) $line->quantity_billed,
                    'unit_cost_billed'         => (string) $line->unit_cost_billed,
                    'line_total'               => (string) $line->line_total,
                ])->toArray(),
            ]);

            return $invoice;
        });
    }
}
