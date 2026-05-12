<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SettlementPeriod extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_REOPENED = 'reopened';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'period_start_at',
        'period_end_at',
        'status',
        'opened_by',
        'opened_at',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'locked_by',
        'locked_at',
        'reopened_by',
        'reopened_at',
        'closing_notes',
        'reopen_reason',
        'metadata',
    ];

    protected $casts = [
        'period_start_at' => 'datetime',
        'period_end_at' => 'datetime',
        'opened_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
        'reopened_at' => 'datetime',
        'metadata' => 'array',
    ];

    public static function supportedStatuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_IN_REVIEW,
            self::STATUS_APPROVED,
            self::STATUS_LOCKED,
            self::STATUS_REOPENED,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SettlementSnapshot::class, 'settlement_period_id')
            ->orderByDesc('created_at');
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(SettlementSnapshot::class, 'settlement_period_id')->latestOfMany('created_at');
    }
}
