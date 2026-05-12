<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleVoid extends Model
{
    use HasFactory, HasUuids, \App\Traits\BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'reason_code',
        'reason_notes',
        'voided_by',
        'voided_at',
    ];

    protected $casts = [
        'voided_at' => 'datetime',
    ];

    /**
     * Boot the model and add immutability guards.
     */
    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \RuntimeException('SaleVoid records are immutable and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('SaleVoid records are immutable and cannot be deleted.');
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
