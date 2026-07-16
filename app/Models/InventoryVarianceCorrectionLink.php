<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryVarianceCorrectionLink extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'inventory_variance_log_id',
        'inventory_movement_id',
        'stocktake_session_id',
        'stocktake_line_id',
        'correction_type',
        'linked_quantity',
        'relationship_type',
        'reason_code',
        'actor_id',
        'link_snapshot',
    ];

    protected $casts = [
        'linked_quantity' => 'decimal:4',
        'link_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Inventory variance correction links are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Inventory variance correction links are append-only and cannot be deleted.');
        });
    }

    public function varianceLog(): BelongsTo
    {
        return $this->belongsTo(InventoryVarianceLog::class, 'inventory_variance_log_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
