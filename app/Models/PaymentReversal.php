<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReversal extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'sale_payment_id',
        'reversal_type',
        'amount',
        'reason_code',
        'reason_notes',
        'reversed_by',
        'reversed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'reversed_at' => 'datetime',
    ];

    /**
     * Boot the model and add immutability guards.
     */
    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \RuntimeException('PaymentReversal records are immutable and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('PaymentReversal records are immutable and cannot be deleted.');
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
