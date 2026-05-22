<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryVarianceLog extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'product_id',
        'ingredient_id',
        'required_quantity',
        'available_quantity_before',
        'shortage_quantity',
        'resulting_quantity',
        'unit',
        'policy',
        'reason',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'required_quantity' => 'decimal:4',
        'available_quantity_before' => 'decimal:4',
        'shortage_quantity' => 'decimal:4',
        'resulting_quantity' => 'decimal:4',
        'metadata' => 'array',
    ];

    protected static function booted()
    {
        parent::booted();

        static::updating(function ($log) {
            throw new \RuntimeException('Inventory variance logs are historical records and cannot be modified.');
        });

        static::deleting(function ($log) {
            throw new \RuntimeException('Inventory variance logs cannot be deleted.');
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
