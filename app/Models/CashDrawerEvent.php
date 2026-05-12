<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDrawerEvent extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    public const TYPE_OPENING_CASH = 'opening_cash';
    public const TYPE_CASH_DROP = 'cash_drop';
    public const TYPE_CASH_TOP_UP = 'cash_top_up';
    public const TYPE_CASH_IN = 'cash_in';
    public const TYPE_CASH_OUT = 'cash_out';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'shift_id',
        'cashier_id',
        'event_type',
        'amount',
        'reason_code',
        'reason_notes',
        'created_by',
        'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'occurred_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted()
    {
        static::updating(function ($event) {
            throw new \RuntimeException('Cash drawer events are immutable.');
        });

        static::deleting(function ($event) {
            throw new \RuntimeException('Cash drawer events cannot be deleted.');
        });
    }
}
