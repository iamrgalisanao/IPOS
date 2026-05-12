<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleRefundItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_refund_id',
        'sale_item_id',
        'product_id',
        'quantity_refunded',
        'unit_price_snapshot',
        'tax_amount_snapshot',
        'line_refund_total',
        'restock_action',
    ];

    protected $casts = [
        'quantity_refunded' => 'decimal:4',
        'unit_price_snapshot' => 'decimal:4',
        'tax_amount_snapshot' => 'decimal:4',
        'line_refund_total' => 'decimal:4',
    ];

    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \RuntimeException('SaleRefundItem records are immutable.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('SaleRefundItem records are append-only.');
        });
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(SaleRefund::class, 'sale_refund_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
