<?php

namespace App\Services\Accounting;

use App\Models\AccountingOutbox;
use Illuminate\Database\Eloquent\Model;

class AccountingOutboxService
{
    /**
     * Record an accounting-relevant event.
     */
    public function recordEvent(string $eventType, Model $source, array $payload): AccountingOutbox
    {
        // 10. Idempotency Guard: Unique constraint on DB will throw, 
        // but we can check here if needed or let DB handle it for atomicity.
        return AccountingOutbox::create([
            'tenant_id' => $source->tenant_id,
            'branch_id' => $source->branch_id,
            'event_type' => $eventType,
            'source_type' => $this->getSourceType($source),
            'source_id' => $source->id,
            'payload' => $payload,
            'sync_status' => 'pending',
            'available_at' => now(),
        ]);
    }

    protected function getSourceType(Model $model): string
    {
        return match (get_class($model)) {
            \App\Models\Sale::class => 'sale',
            \App\Models\SaleVoid::class => 'sale_void',
            \App\Models\SaleRefund::class => 'sale_refund',
            \App\Models\StoreCreditLedgerEntry::class => 'store_credit_ledger_entry',
            \App\Models\SupplierReturn::class => 'supplier_return',
            \App\Models\SupplierInvoice::class => 'supplier_invoice',
            default => strtolower(class_basename($model)),
        };
    }
}
