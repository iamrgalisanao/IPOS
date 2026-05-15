<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable financial record.
 * No updated_at. No soft deletes.
 * All snapshot fields are copied from product at time of sale creation
 * and must never be mutated after that.
 */
class SaleItem extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    public const TAX_BUCKET_VATABLE = 'vatable';
    public const TAX_BUCKET_VAT_EXEMPT = 'vat_exempt';
    public const TAX_BUCKET_ZERO_RATED = 'zero_rated';
    public const TAX_BUCKET_NON_VAT = 'non_vat';
    public const TAX_BUCKET_MIXED = 'mixed';
    public const TAX_BUCKET_UNKNOWN = 'unknown';

    public const TAX_SOURCE_SYSTEM = Sale::TAX_SOURCE_SYSTEM;
    public const TAX_SOURCE_POS = Sale::TAX_SOURCE_POS;
    public const TAX_SOURCE_MANUAL = Sale::TAX_SOURCE_MANUAL;
    public const TAX_SOURCE_MIGRATION = Sale::TAX_SOURCE_MIGRATION;
    public const TAX_SOURCE_UNKNOWN = Sale::TAX_SOURCE_UNKNOWN;

    // Immutable — disable automatic timestamp management for updated_at
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'product_id',
        'product_name',
        'sku',
        'barcode',
        'unit_of_measure',
        'quantity',
        'unit_price',
        'subtotal',
        'discount_amount',
        'tax_category_id',
        'tax_type',
        'tax_bucket',
        'tax_rate',
        'tax_amount',
        'net_amount',
        'vatable_amount',
        'vat_exempt_amount',
        'zero_rated_amount',
        'non_vat_amount',
        'tax_source',
        'tax_snapshot',
        'line_total',
        'is_inventory_tracked',
    ];

    protected $casts = [
        'quantity'             => 'decimal:4',
        'unit_price'           => 'decimal:4',
        'subtotal'             => 'decimal:4',
        'discount_amount'      => 'decimal:4',
        'tax_rate'             => 'decimal:4',
        'tax_amount'           => 'decimal:4',
        'net_amount'           => 'decimal:4',
        'vatable_amount'       => 'decimal:4',
        'vat_exempt_amount'    => 'decimal:4',
        'zero_rated_amount'    => 'decimal:4',
        'non_vat_amount'       => 'decimal:4',
        'tax_snapshot'         => 'array',
        'line_total'           => 'decimal:4',
        'is_inventory_tracked' => 'boolean',
        'created_at'           => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function statutoryDiscounts(): HasMany
    {
        return $this->hasMany(SaleStatutoryDiscount::class);
    }

    public static function taxBuckets(): array
    {
        return [
            self::TAX_BUCKET_VATABLE,
            self::TAX_BUCKET_VAT_EXEMPT,
            self::TAX_BUCKET_ZERO_RATED,
            self::TAX_BUCKET_NON_VAT,
            self::TAX_BUCKET_MIXED,
            self::TAX_BUCKET_UNKNOWN,
        ];
    }

    public static function taxSources(): array
    {
        return Sale::taxSources();
    }

    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \RuntimeException('Sale items are immutable and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('Sale items are immutable and cannot be deleted.');
        });
    }
}
