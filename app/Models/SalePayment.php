<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'payment_method_id',
        'shift_id',
        'payment_type',
        'provider',
        'amount',
        'reference_number',
        'status',
        'paid_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'paid_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \Exception("SalePayment records are immutable and cannot be updated.");
        });

        static::deleting(function ($model) {
            throw new \Exception("SalePayment records are immutable and cannot be deleted.");
        });
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
