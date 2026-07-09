<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleDiscountBeneficiary extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'sale_discount_id',
        'beneficiary_name',
        'id_number',
        'tin',
        'spic_number',
        'child_name',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function saleDiscount(): BelongsTo
    {
        return $this->belongsTo(SaleDiscount::class);
    }
}

