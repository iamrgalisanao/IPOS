<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAuditEvent extends Model
{
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Support audit events are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Support audit events are append-only and cannot be deleted.');
        });
    }

    protected $fillable = [
        'event_type',
        'support_session_id',
        'actor_id',
        'route_name',
        'path',
        'method',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function supportSession(): BelongsTo
    {
        return $this->belongsTo(SupportAccessSession::class, 'support_session_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}