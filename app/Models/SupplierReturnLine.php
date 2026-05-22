<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturnLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'supplier_return_id',
        'product_id',
        'expiry_lot_id',
        'quantity',
        'unit_cost',
        'line_total',
        'batch_code',
        'expiry_date',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'line_total' => 'decimal:4',
        'expiry_date' => 'date',
    ];

    // Relationships
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function expiryLot(): BelongsTo
    {
        return $this->belongsTo(ExpiryLot::class);
    }
}
