<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'supplier_invoice_id',
        'purchase_receiving_line_id',
        'product_id',
        'quantity_billed',
        'unit_cost_billed',
        'line_total',
    ];

    protected $casts = [
        'quantity_billed' => 'decimal:4',
        'unit_cost_billed' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    // Relationships
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function purchaseReceivingLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceivingLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
