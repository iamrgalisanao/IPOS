<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryAdjustmentReason extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const DIRECTION_INCREASE = 'increase_only';
    public const DIRECTION_DECREASE = 'decrease_only';
    public const DIRECTION_BIDIRECTIONAL = 'bidirectional';
    public const DIRECTION_OPENING_BALANCE = 'opening_balance';

    public const CATEGORY_DAMAGE = 'damage';
    public const CATEGORY_EXPIRY = 'expiry';
    public const CATEGORY_SPOILAGE = 'spoilage';
    public const CATEGORY_SHRINKAGE = 'shrinkage';
    public const CATEGORY_THEFT_OR_LOSS = 'theft_or_loss';
    public const CATEGORY_FOUND_STOCK = 'found_stock';
    public const CATEGORY_DATA_CORRECTION = 'data_correction';
    public const CATEGORY_INTERNAL_CONSUMPTION = 'internal_consumption';
    public const CATEGORY_QUALITY_REJECTION = 'quality_rejection';
    public const CATEGORY_OPENING_BALANCE = 'opening_balance';
    public const CATEGORY_OTHER_CONTROLLED = 'other_controlled';

    public const RESERVED_CATEGORIES = [
        'supplier_receiving',
        'supplier_return',
        'branch_transfer',
        'stocktake_correction',
        'sale_return',
        'void_reversal',
    ];

    public const ALLOWED_CATEGORIES = [
        self::CATEGORY_DAMAGE,
        self::CATEGORY_EXPIRY,
        self::CATEGORY_SPOILAGE,
        self::CATEGORY_SHRINKAGE,
        self::CATEGORY_THEFT_OR_LOSS,
        self::CATEGORY_FOUND_STOCK,
        self::CATEGORY_DATA_CORRECTION,
        self::CATEGORY_INTERNAL_CONSUMPTION,
        self::CATEGORY_QUALITY_REJECTION,
        self::CATEGORY_OPENING_BALANCE,
        self::CATEGORY_OTHER_CONTROLLED,
    ];

    public const ALLOWED_DIRECTIONS = [
        self::DIRECTION_INCREASE,
        self::DIRECTION_DECREASE,
        self::DIRECTION_BIDIRECTIONAL,
        self::DIRECTION_OPENING_BALANCE,
    ];

    protected $fillable = [
        'reason_uuid',
        'tenant_id',
        'code',
        'name',
        'description',
        'reason_category',
        'direction_policy',
        'requires_notes',
        'evidence_required',
        'is_opening_balance',
        'is_active',
        'active_slot',
        'reason_version',
        'reason_schema_version',
        'supersedes_reason_id',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'requires_notes' => 'boolean',
        'evidence_required' => 'boolean',
        'is_opening_balance' => 'boolean',
        'is_active' => 'boolean',
        'reason_version' => 'integer',
        'reason_schema_version' => 'integer',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_reason_id');
    }

    public function approvalRules(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentApprovalRule::class, 'reason_id');
    }

    public function isIncrease(): bool
    {
        return $this->direction_policy === self::DIRECTION_INCREASE;
    }

    public function isDecrease(): bool
    {
        return $this->direction_policy === self::DIRECTION_DECREASE;
    }

    public function isBidirectional(): bool
    {
        return $this->direction_policy === self::DIRECTION_BIDIRECTIONAL;
    }
}
