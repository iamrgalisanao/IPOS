<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingOutbox extends Model
{
    use HasFactory, HasUuids, \App\Traits\BelongsToTenant;

    protected $table = 'accounting_outbox';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'event_type',
        'source_type',
        'source_id',
        'payload',
        'sync_status',
        'sync_error',
        'sync_error_category',
        'attempt_count',
        'available_at',
        'last_attempted_at',
        'next_attempt_at',
        'synced_at',
        'external_provider',
        'external_id',
        'external_reference',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempt_count' => 'integer',
        'available_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Boot the model and add identity immutability guards.
     */
    protected static function booted()
    {
        static::updating(function ($model) {
            $immutable = ['tenant_id', 'branch_id', 'event_type', 'source_type', 'source_id', 'payload'];
            
            foreach ($immutable as $field) {
                if ($model->isDirty($field)) {
                    throw new \RuntimeException("Field '{$field}' in AccountingOutbox is immutable.");
                }
            }
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('AccountingOutbox records are append-only and cannot be deleted.');
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

    public function attempts(): HasMany
    {
        return $this->hasMany(AccountingSyncAttempt::class, 'accounting_outbox_id');
    }
}
