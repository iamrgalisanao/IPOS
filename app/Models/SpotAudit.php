<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpotAudit extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'shift_id',
        'cashier_id',
        'manager_id',
        'expected_cash_amount',
        'counted_cash_amount',
        'variance_amount',
        'denominations',
        'audit_notes',
        'occurred_at',
    ];

    protected $casts = [
        'expected_cash_amount' => 'decimal:4',
        'counted_cash_amount' => 'decimal:4',
        'variance_amount' => 'decimal:4',
        'denominations' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \RuntimeException('Spot audit records are immutable and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('Spot audit records are immutable and cannot be deleted.');
        });
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
