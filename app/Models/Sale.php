<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'user_id',
        'client_request_uuid',
        'checkout_request_id',
        'sale_number',
        'status',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'confirmed_at',
    ];

    protected $casts = [
        'subtotal'       => 'decimal:4',
        'tax_total'      => 'decimal:4',
        'discount_total' => 'decimal:4',
        'total'          => 'decimal:4',
        'confirmed_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkoutRequest(): BelongsTo
    {
        return $this->belongsTo(CheckoutRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    protected static function booted()
    {
        static::updating(function ($sale) {
            // Block updates to financial totals and core identity fields
            if ($sale->isDirty(['subtotal', 'tax_total', 'discount_total', 'total', 'tenant_id', 'branch_id', 'client_request_uuid'])) {
                throw new \RuntimeException('Financial totals and core identity of a sale are immutable.');
            }
        });

        static::deleting(function ($sale) {
            throw new \RuntimeException('Sales cannot be deleted. Use void/refund protocols.');
        });
    }
}
