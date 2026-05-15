<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleStatutoryDiscount extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    public const DISCOUNT_TYPE_SENIOR_CITIZEN = 'senior_citizen';
    public const DISCOUNT_TYPE_PWD = 'pwd';
    public const DISCOUNT_TYPE_OTHER = 'other';
    public const DISCOUNT_TYPE_UNKNOWN = 'unknown';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'sale_item_id',
        'discount_type',
        'discount_code',
        'discount_rate',
        'discount_basis_amount',
        'discount_amount',
        'vat_adjustment_amount',
        'vat_exempt_amount',
        'beneficiary_reference',
        'beneficiary_hash',
        'source',
        'snapshot',
    ];

    protected $casts = [
        'discount_rate' => 'decimal:4',
        'discount_basis_amount' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'vat_adjustment_amount' => 'decimal:4',
        'vat_exempt_amount' => 'decimal:4',
        'snapshot' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public static function discountTypes(): array
    {
        return [
            self::DISCOUNT_TYPE_SENIOR_CITIZEN,
            self::DISCOUNT_TYPE_PWD,
            self::DISCOUNT_TYPE_OTHER,
            self::DISCOUNT_TYPE_UNKNOWN,
        ];
    }
}