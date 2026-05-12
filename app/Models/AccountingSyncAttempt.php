<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingSyncAttempt extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'accounting_outbox_id',
        'attempt_number',
        'status',
        'error_category',
        'error_message',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(AccountingOutbox::class, 'accounting_outbox_id');
    }
}
