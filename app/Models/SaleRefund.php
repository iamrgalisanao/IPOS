<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleRefund extends Model
{
    use HasFactory, HasUuids, \App\Traits\BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'shift_id',
        'refund_number',
        'reason_code',
        'reason_notes',
        'refund_total',
        'refunded_by',
        'refunded_at',
    ];

    protected $casts = [
        'refund_total' => 'decimal:4',
        'refunded_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \RuntimeException('SaleRefund records are immutable.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('SaleRefund records are append-only.');
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleRefundItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function storeCreditIssuance(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StoreCreditRefundIssuance::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
