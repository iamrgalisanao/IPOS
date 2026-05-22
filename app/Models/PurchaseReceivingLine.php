<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceivingLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'purchase_receiving_id',
        'purchase_order_line_id',
        'product_id',
        'ordered_quantity',
        'received_quantity',
        'unit_cost',
        'line_total',
        'lot_number',
        'expiry_date',
        'remarks',
    ];

    protected $casts = [
        'ordered_quantity' => 'decimal:4',
        'received_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'line_total' => 'decimal:4',
        'expiry_date' => 'date',
    ];

    // Relationships
    public function purchaseReceiving(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiving::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
