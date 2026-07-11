<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscountType extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'statutory_category',
        'default_rate',
        'vat_treatment',
        'requires_identity',
        'requires_approval',
        'applies_to_fnb',
        'applies_to_retail',
        'is_active',
    ];

    protected $casts = [
        'default_rate' => 'decimal:4',
        'requires_identity' => 'boolean',
        'requires_approval' => 'boolean',
        'applies_to_fnb' => 'boolean',
        'applies_to_retail' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function saleDiscounts(): HasMany
    {
        return $this->hasMany(SaleDiscount::class);
    }

    public function productEligibilities(): HasMany
    {
        return $this->hasMany(ProductDiscountEligibility::class);
    }

    /**
     * Scope: active discount types only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
