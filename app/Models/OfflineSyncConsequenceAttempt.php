<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSyncConsequenceAttempt extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_LOYALTY_ACCRUAL = 'loyalty_accrual';

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_SKIPPED_BY_POLICY = 'skipped_by_policy';
    public const STATUS_RETRYABLE_FAILED = 'retryable_failed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVIEW_REQUIRED = 'review_required';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'offline_sales_import_id',
        'sale_id',
        'consequence_type',
        'status',
        'idempotency_key',
        'attempt_no',
        'available_at',
        'claimed_at',
        'claim_owner',
        'started_at',
        'completed_at',
        'failed_at',
        'next_retry_at',
        'last_error_code',
        'last_error_summary',
        'result_reference_type',
        'result_reference_id',
        'metadata_json',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(OfflineSalesImport::class, 'offline_sales_import_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
