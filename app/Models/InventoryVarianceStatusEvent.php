<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InventoryVarianceStatusEvent extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'event_uuid',
        'event_schema_version',
        'branch_id',
        'inventory_variance_log_id',
        'from_status',
        'to_status',
        'event_type',
        'reason_code',
        'notes',
        'request_uuid',
        'request_fingerprint',
        'actor_id',
        'event_snapshot',
    ];

    protected $casts = [
        'event_schema_version' => 'integer',
        'event_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->event_uuid ??= (string) Str::orderedUuid();
            $event->event_schema_version ??= 1;
        });

        static::updating(function () {
            throw new \RuntimeException('Inventory variance status events are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Inventory variance status events are append-only and cannot be deleted.');
        });
    }

    public function varianceLog(): BelongsTo
    {
        return $this->belongsTo(InventoryVarianceLog::class, 'inventory_variance_log_id');
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
