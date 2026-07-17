<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSyncAttempt extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'offline_sales_import_id',
        'offline_transaction_uuid',
        'sync_attempt_id',
        'lease_id',
        'attempt_generation',
        'worker',
        'request_started_at',
        'response_finished_at',
        'http_status',
        'result_status',
        'transient_error_code',
        'error_message',
        'retryable',
        'metadata',
    ];

    protected $casts = [
        'attempt_generation' => 'integer',
        'request_started_at' => 'datetime',
        'response_finished_at' => 'datetime',
        'http_status' => 'integer',
        'retryable' => 'boolean',
        'metadata' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(OfflineSalesImport::class, 'offline_sales_import_id');
    }
}
