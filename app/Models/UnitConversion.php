<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UnitConversion extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'conversion_uuid',
        'conversion_schema_version',
        'version',
        'product_id',
        'scope_key',
        'from_unit',
        'normalized_from_unit',
        'to_unit',
        'normalized_to_unit',
        'source_unit_kind',
        'target_unit_kind',
        'unit_kind_confidence',
        'conversion_factor',
        'factor_numerator',
        'factor_denominator',
        'supersedes_conversion_id',
        'is_active',
        'locked_at',
        'active_slot',
        'created_by',
        'updated_by',
        'metadata',
    ];

    protected $casts = [
        'conversion_schema_version' => 'integer',
        'version' => 'integer',
        'conversion_factor' => 'decimal:4',
        'factor_numerator' => 'decimal:8',
        'factor_denominator' => 'decimal:8',
        'is_active' => 'boolean',
        'locked_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $conversion) {
            $conversion->conversion_uuid ??= (string) Str::orderedUuid();
            $conversion->conversion_schema_version ??= 1;
            $conversion->version ??= 1;
            $conversion->is_active ??= true;
            $conversion->normalized_from_unit = self::normalizeUnit($conversion->from_unit);
            $conversion->normalized_to_unit = self::normalizeUnit($conversion->to_unit);
            $conversion->scope_key = self::scopeKey($conversion->tenant_id, $conversion->product_id);
            $conversion->active_slot = $conversion->is_active ? 'active' : null;

            $conversion->source_unit_kind ??= self::classifyUnit($conversion->from_unit)[0];
            $conversion->target_unit_kind ??= self::classifyUnit($conversion->to_unit)[0];
            $conversion->unit_kind_confidence ??= (
                self::classifyUnit($conversion->from_unit)[1] === 'certain'
                && self::classifyUnit($conversion->to_unit)[1] === 'certain'
            ) ? 'certain' : 'uncertain';

            $conversion->factor_numerator ??= $conversion->conversion_factor;
            $conversion->factor_denominator ??= 1;
        });
    }

    /**
     * The product this unit conversion belongs to (if product-specific).
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_conversion_id');
    }

    public static function normalizeUnit(?string $unit): string
    {
        return strtolower(trim((string) $unit));
    }

    public static function scopeKey(string $tenantId, ?string $productId): string
    {
        return $productId ? "product:{$productId}" : "tenant:{$tenantId}";
    }

    public static function classifyUnit(?string $unit): array
    {
        $unit = self::normalizeUnit($unit);

        return match ($unit) {
            'kg', 'kilogram', 'kilograms', 'gram', 'grams', 'g' => ['mass', 'certain'],
            'liter', 'litre', 'liters', 'litres', 'l', 'ml', 'milliliter', 'millilitre' => ['volume', 'certain'],
            'piece', 'pieces', 'pc', 'pcs', 'unit', 'dozen', 'gross', 'pair' => ['count', 'certain'],
            'case', 'tray', 'crate', 'bag', 'bottle', 'bucket', 'sack', 'scoop' => ['package', 'uncertain'],
            default => ['custom', 'uncertain'],
        };
    }

    public static function isCanonicalMetricPair(string $fromUnit, string $toUnit): bool
    {
        [$fromKind] = self::classifyUnit($fromUnit);
        [$toKind] = self::classifyUnit($toUnit);

        if ($fromKind !== $toKind) {
            return false;
        }

        return in_array($fromKind, ['mass', 'volume'], true)
            && self::classifyUnit($fromUnit)[1] === 'certain'
            && self::classifyUnit($toUnit)[1] === 'certain';
    }

    public static function isCanonicalPackageUnit(string $unit): bool
    {
        return in_array(self::normalizeUnit($unit), ['dozen', 'gross', 'pair'], true);
    }
}
