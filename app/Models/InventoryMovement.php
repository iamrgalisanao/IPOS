<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'product_id',
        'branch_inventory_id',
        'original_movement_id',
        'movement_type',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'source_type',
        'source_id',
        'reference_number',
        'user_id',
        'reason_code',
        'remarks',
    ];

    protected $casts = [
        'quantity_change' => 'decimal:4',
        'quantity_before' => 'decimal:4',
        'quantity_after' => 'decimal:4',
    ];

    protected static function booted()
    {
        static::updating(function ($movement) {
            throw new \RuntimeException('Inventory movements are immutable and cannot be updated.');
        });

        static::deleting(function ($movement) {
            throw new \RuntimeException('Inventory movements are append-only and cannot be deleted.');
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(BranchInventory::class, 'branch_inventory_id');
    }
}
