<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchInventory extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'product_id',
        'current_stock',
        'inventory_revision',
        'average_cost',
        'reorder_level',
        'par_level',
        'lead_time_days',
        'safety_stock_buffer',
        'status',
    ];

    protected $casts = [
        'current_stock' => 'decimal:4',
        'inventory_revision' => 'integer',
        'average_cost' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'par_level' => 'decimal:4',
        'lead_time_days' => 'integer',
        'safety_stock_buffer' => 'decimal:4',
    ];


    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Scope a query to only include active records.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include items below or at reorder level.
     */
    public function scopeLowStock($query)
    {
        return $query->active()
            ->whereColumn('current_stock', '<=', 'reorder_level');
    }

    /**
     * Check if the item is currently in low stock state.
     */
    public function isLowStock(): bool
    {
        return $this->status === 'active' && (float) $this->current_stock <= (float) $this->reorder_level;
    }
}
