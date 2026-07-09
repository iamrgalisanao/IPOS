<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleDiscount extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'sale_id',
        'discount_type_id',
        'application_mode',
        'base_amount',
        'discount_amount',
        'vat_exempt_amount',
        'eligible_person_count',
        'total_pax_count',
        'approved_by',
        'calculation_snapshot',
    ];

    protected $casts = [
        'base_amount' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'vat_exempt_amount' => 'decimal:4',
        'calculation_snapshot' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function discountType(): BelongsTo
    {
        return $this->belongsTo(DiscountType::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(SaleDiscountBeneficiary::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

