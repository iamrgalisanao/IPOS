<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSalesImport extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    // Lifecycle states
    const STATUS_PENDING   = 'pending';
    const STATUS_VALIDATED = 'validated';
    const STATUS_POSTED    = 'posted';
    const STATUS_REJECTED        = 'rejected';
    const STATUS_DUPLICATE       = 'duplicate';
    const STATUS_SERVER_VERIFIED = 'server_verified';
    const STATUS_CONFLICT        = 'conflict';
    const STATUS_HOLD            = 'hold';
    const STATUS_OVERRIDE_APPROVED = 'override_approved';
    const STATUS_ACCEPTED_WITH_WARNING = 'accepted_with_warning';

    public const SYNC_ACCEPTED = 'accepted';
    public const SYNC_REPLAYED = 'replayed';
    public const SYNC_RETRYABLE_FAILED = 'retryable_failed';
    public const SYNC_REVIEW_REQUIRED = 'review_required';
    public const SYNC_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sales_machine_profile_id',
        'batch_id',
        'offline_sequence_number',
        'offline_transaction_uuid',
        'terminal_binding_epoch',
        'local_sequence',
        'payload_hash',
        'sync_contract_version',
        'server_payload_fingerprint',
        'fingerprint_algorithm',
        'fingerprint_schema_version',
        'raw_payload',
        'status',
        'server_sync_status',
        'original_sync_status',
        'review_reason',
        'retryable_error_code',
        'cash_status',
        'resolution_status',
        'rejection_reason',
        'reconciled_sale_id',
        'official_invoice_number',
        'submitted_at',
        'reconciled_at',
        'server_recalculation',
        'consequence_status_snapshot',
        'acceptance_consequence_snapshot',
        'current_consequence_status',
        'conflict_notes',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'first_seen_at',
        'last_replayed_at',
        'accepted_at',
        'rejected_at',
        'review_required_at',
    ];

    protected $casts = [
        'raw_payload'          => 'array',
        'server_recalculation' => 'array',
        'consequence_status_snapshot' => 'array',
        'acceptance_consequence_snapshot' => 'array',
        'current_consequence_status' => 'array',
        'fingerprint_schema_version' => 'integer',
        'submitted_at'         => 'datetime',
        'reconciled_at'        => 'datetime',
        'reviewed_at'          => 'datetime',
        'first_seen_at'        => 'datetime',
        'last_replayed_at'     => 'datetime',
        'accepted_at'          => 'datetime',
        'rejected_at'          => 'datetime',
        'review_required_at'   => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OfflineSyncBatch::class, 'batch_id');
    }

    public function salesMachineProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class);
    }

    /**
     * The official Sale that was created upon successful reconciliation.
     * Remains null until this import is fully reconciled and posted.
     */
    public function reconciledSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'reconciled_sale_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function syncAttempts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OfflineSyncAttempt::class, 'offline_sales_import_id');
    }
}
