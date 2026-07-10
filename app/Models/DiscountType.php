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

    // ...existing code...

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

