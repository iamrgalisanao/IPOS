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
        'conflict_family',
        'reason_code',
        'review_severity',
        'retry_classification',
        'suggested_action_code',
        'retryable_error_code',
        'cash_status',
        'cash_exposure_status',
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
        'conflict_metadata',
        'predecessor_offline_transaction_uuid',
        'predecessor_dependency',
        'sequence_gap_detected_at',
        'sequence_gap_grace_expires_at',
        'sequence_gap_state',
        'missing_sequence_from',
        'missing_sequence_to',
        'predecessor_lookup_last_attempt_at',
        'duplicate_score',
        'duplicate_review_threshold',
        'duplicate_rule_ids',
        'duplicate_candidates',
        'duplicate_candidate_sale_id',
        'duplicate_candidate_import_id',
        'duplicate_detection_version',
        'conflict_policy_version',
        'ordering_policy_version',
        'review_payload_schema_version',
        'time_evidence_status',
        'business_date_status',
        'proposed_business_date',
        'resolved_business_date',
        'business_date_review_reason',
        'reported_sync_delay_seconds',
        'normalized_sync_delay_seconds',
        'offline_capture_timestamp',
        'server_accepted_at',
        'conflict_notes',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'first_seen_at',
        'last_replayed_at',
        'accepted_at',
        'rejected_at',
        'review_required_at',
        'review_locked_at',
        'review_opened_at',
        'review_due_at',
        'review_sla_policy_id',
        'review_sla_policy_version',
        'review_escalation_level',
        'last_review_activity_at',
        'assigned_team',
        'review_decision_snapshot',
        'current_resolution_status',
    ];

    protected $casts = [
        'raw_payload'          => 'array',
        'server_recalculation' => 'array',
        'consequence_status_snapshot' => 'array',
        'acceptance_consequence_snapshot' => 'array',
        'current_consequence_status' => 'array',
        'conflict_metadata' => 'array',
        'duplicate_rule_ids' => 'array',
        'duplicate_candidates' => 'array',
        'review_decision_snapshot' => 'array',
        'fingerprint_schema_version' => 'integer',
        'duplicate_score' => 'integer',
        'duplicate_review_threshold' => 'integer',
        'review_escalation_level' => 'integer',
        'submitted_at'         => 'datetime',
        'reconciled_at'        => 'datetime',
        'sequence_gap_detected_at' => 'datetime',
        'sequence_gap_grace_expires_at' => 'datetime',
        'predecessor_lookup_last_attempt_at' => 'datetime',
        'proposed_business_date' => 'date',
        'resolved_business_date' => 'date',
        'reported_sync_delay_seconds' => 'integer',
        'normalized_sync_delay_seconds' => 'integer',
        'offline_capture_timestamp' => 'datetime',
        'server_accepted_at' => 'datetime',
        'reviewed_at'          => 'datetime',
        'first_seen_at'        => 'datetime',
        'last_replayed_at'     => 'datetime',
        'accepted_at'          => 'datetime',
        'rejected_at'          => 'datetime',
        'review_required_at'   => 'datetime',
        'review_locked_at'     => 'datetime',
        'review_opened_at'     => 'datetime',
        'review_due_at'        => 'datetime',
        'last_review_activity_at' => 'datetime',
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

    public function consequenceAttempts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OfflineSyncConsequenceAttempt::class, 'offline_sales_import_id');
    }
}
