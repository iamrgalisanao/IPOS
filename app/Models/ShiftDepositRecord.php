<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftDepositRecord extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'shift_id',
        'manager_id',
        'deposit_amount',
        'expected_cash_amount',
        'counted_cash_amount',
        'cash_drop_total',
        'variance_amount',
        'variance_explanation',
        'bank_name',
        'reference_number',
        'deposited_at',
        'approved_at',
    ];

    protected $casts = [
        'deposit_amount' => 'decimal:4',
        'expected_cash_amount' => 'decimal:4',
        'counted_cash_amount' => 'decimal:4',
        'cash_drop_total' => 'decimal:4',
        'variance_amount' => 'decimal:4',
        'deposited_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function ($model) {
            throw new \RuntimeException('Shift deposit records are immutable and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('Shift deposit records are immutable and cannot be deleted.');
        });
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
