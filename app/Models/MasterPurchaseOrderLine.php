<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterPurchaseOrderLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'master_purchase_order_id',
        'product_id',
        'total_ordered_quantity',
        'unit_cost',
        'line_total',
    ];

    protected $casts = [
        'total_ordered_quantity' => 'decimal:4',
        'unit_cost'              => 'decimal:4',
        'line_total'             => 'decimal:4',
    ];

    // Relationships
    public function masterPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(MasterPurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(MasterPurchaseOrderAllocation::class);
    }
}
