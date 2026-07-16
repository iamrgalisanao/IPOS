<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InventoryVarianceLog extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const CATEGORY_NEGATIVE_STOCK = 'negative_stock';

    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_ACTION_PLANNED = 'action_planned';
    public const STATUS_LINKED_TO_CORRECTION = 'linked_to_correction';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'tenant_id',
        'variance_uuid',
        'variance_schema_version',
        'variance_category',
        'current_status',
        'movement_id',
        'movement_uuid',
        'movement_sequence',
        'branch_inventory_id',
        'branch_id',
        'sale_id',
        'sale_item_id',
        'product_id',
        'ingredient_id',
        'ingredient_product_id',
        'source_type',
        'source_id',
        'source_reference',
        'source_effect_key',
        'quantity_before',
        'quantity_required',
        'quantity_delta',
        'quantity_after',
        'incremental_shortage_quantity',
        'resulting_negative_quantity',
        'required_quantity',
        'available_quantity_before',
        'shortage_quantity',
        'resulting_quantity',
        'unit',
        'policy',
        'reason',
        'metadata',
        'policy_snapshot',
        'unit_snapshot',
        'conversion_snapshot',
        'source_snapshot',
        'first_reviewed_by',
        'first_reviewed_at',
        'resolved_at',
        'terminal_status_reason',
        'created_by',
    ];

    protected $casts = [
        'variance_schema_version' => 'integer',
        'movement_sequence' => 'integer',
        'quantity_before' => 'decimal:4',
        'quantity_required' => 'decimal:4',
        'quantity_delta' => 'decimal:4',
        'quantity_after' => 'decimal:4',
        'incremental_shortage_quantity' => 'decimal:4',
        'resulting_negative_quantity' => 'decimal:4',
        'required_quantity' => 'decimal:4',
        'available_quantity_before' => 'decimal:4',
        'shortage_quantity' => 'decimal:4',
        'resulting_quantity' => 'decimal:4',
        'metadata' => 'array',
        'policy_snapshot' => 'array',
        'unit_snapshot' => 'array',
        'conversion_snapshot' => 'array',
        'source_snapshot' => 'array',
        'first_reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted()
    {
        parent::booted();

        static::creating(function (self $log) {
            $log->variance_uuid ??= (string) Str::orderedUuid();
            $log->variance_schema_version ??= 1;
            $log->variance_category ??= self::CATEGORY_NEGATIVE_STOCK;
            $log->current_status ??= self::STATUS_OPEN;
            $log->ingredient_product_id ??= $log->ingredient_id;
            $log->quantity_before ??= $log->available_quantity_before;
            $log->quantity_required ??= $log->required_quantity;
            $log->quantity_delta ??= $log->required_quantity !== null
                ? number_format(-1 * (float) $log->required_quantity, 4, '.', '')
                : null;
            $log->quantity_after ??= $log->resulting_quantity;

            if ($log->incremental_shortage_quantity === null && $log->required_quantity !== null && $log->available_quantity_before !== null) {
                $required = (float) $log->required_quantity;
                $before = (float) $log->available_quantity_before;
                $log->incremental_shortage_quantity = $before < 0 ? $required : max(0, $required - max($before, 0));
            }

            if ($log->resulting_negative_quantity === null && $log->resulting_quantity !== null) {
                $log->resulting_negative_quantity = abs(min((float) $log->resulting_quantity, 0));
            }

            $log->source_type ??= $log->sale_id ? 'sale' : null;
            $log->source_id ??= $log->sale_id;
        });

        static::updating(function ($log) {
            $allowedProjectionFields = [
                'current_status',
                'first_reviewed_by',
                'first_reviewed_at',
                'resolved_at',
                'terminal_status_reason',
                'updated_at',
            ];

            if (array_diff(array_keys($log->getDirty()), $allowedProjectionFields)) {
                throw new \RuntimeException('Inventory variance source evidence is immutable and cannot be modified.');
            }
        });

        static::deleting(function ($log) {
            throw new \RuntimeException('Inventory variance logs cannot be deleted.');
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
    }

    public function branchInventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class, 'branch_inventory_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_id');
    }

    public function ingredientProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function firstReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_reviewed_by');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(InventoryVarianceStatusEvent::class);
    }

    public function correctionLinks(): HasMany
    {
        return $this->hasMany(InventoryVarianceCorrectionLink::class);
    }
}
