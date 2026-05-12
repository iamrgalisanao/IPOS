<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementSnapshot extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const UPDATED_AT = null;

    public const TYPE_REVIEW = 'review';
    public const TYPE_PRE_LOCK = 'pre_lock';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'settlement_period_id',
        'snapshot_type',
        'summary_payload',
        'variance_payload',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'summary_payload' => 'array',
        'variance_payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function () {
            throw new \RuntimeException('Settlement snapshots are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Settlement snapshots are append-only and cannot be deleted.');
        });
    }

    public static function supportedTypes(): array
    {
        return [
            self::TYPE_REVIEW,
            self::TYPE_PRE_LOCK,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SettlementPeriod::class, 'settlement_period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}