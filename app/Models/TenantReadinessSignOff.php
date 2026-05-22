<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantReadinessSignOff extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'signed_off_by',
        'signed_off_state',
        'readiness_state_calculated',
        'notes',
        'readiness_snapshot',
        'created_at',
    ];

    protected $casts = [
        'readiness_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Tenant readiness sign-offs are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Tenant readiness sign-offs are append-only and cannot be deleted.');
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }
}
