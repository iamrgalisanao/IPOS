<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable financial record.
 * No updated_at. No soft deletes.
 * All snapshot fields are copied from product at time of sale creation
 * and must never be mutated after that.
 */
class SaleItem extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    // Immutable — disable automatic timestamp management for updated_at
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'product_id',
        'product_name',
        'sku',
        'barcode',
        'unit_of_measure',
        'quantity',
        'unit_price',
        'subtotal',
        'discount_amount',
        'tax_category_id',
        'tax_type',
        'tax_rate',
        'tax_amount',
        'line_total',
        'is_inventory_tracked',
    ];

    protected $casts = [
        'quantity'            => 'decimal:4',
        'unit_price'          => 'decimal:4',
        'subtotal'            => 'decimal:4',
        'discount_amount'     => 'decimal:4',
        'tax_rate'            => 'decimal:4',
        'tax_amount'          => 'decimal:4',
        'line_total'          => 'decimal:4',
        'is_inventory_tracked' => 'boolean',
        'created_at'          => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \RuntimeException('Sale items are immutable and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('Sale items are immutable and cannot be deleted.');
        });
    }
}
