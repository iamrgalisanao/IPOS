<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StocktakeSession extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_COUNTING = 'counting';
    public const STATUS_REVIEW = 'review';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REJECTED = 'rejected';
    public const MODE_MOVEMENT_AWARE = 'movement_aware';
    public const MODE_FROZEN_WINDOW = 'frozen_window';
    public const SCOPE_FULL_BRANCH = 'full_branch';
    public const SCOPE_SELECTED_PRODUCTS = 'selected_products';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'stocktake_number',
        'status',
        'started_by',
        'reviewed_by',
        'approved_by',
        'posted_by',
        'started_at',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'posted_at',
        'cancelled_at',
        'rejected_at',
        'notes',
        'count_started_at',
        'count_start_movement_sequence',
        'stocktake_operation_mode',
        'stocktake_scope_type',
        'session_revision',
        'posting_preview_generated_at',
        'posting_preview_latest_movement_sequence',
        'posting_preview_inventory_revision',
        'posted_movement_sequence_min',
        'posted_movement_sequence_max',
        'posting_schema_version',
        'projection_policy_version',
        'posting_evidence_quality',
        'posting_summary_snapshot',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rejected_at' => 'datetime',
        'count_started_at' => 'datetime',
        'posting_preview_generated_at' => 'datetime',
        'count_start_movement_sequence' => 'integer',
        'session_revision' => 'integer',
        'posting_preview_latest_movement_sequence' => 'integer',
        'posting_preview_inventory_revision' => 'integer',
        'posted_movement_sequence_min' => 'integer',
        'posted_movement_sequence_max' => 'integer',
        'posting_schema_version' => 'integer',
        'projection_policy_version' => 'integer',
        'posting_summary_snapshot' => 'array',
    ];

    /**
     * Relationships
     */

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    public function startedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->reviewedByUser();
    }

    public function approver(): BelongsTo
    {
        return $this->approvedByUser();
    }

    public function poster(): BelongsTo
    {
        return $this->postedByUser();
    }

    /**
     * Status Helpers
     */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCounting(): bool
    {
        return $this->status === self::STATUS_COUNTING;
    }

    public function isInReview(): bool
    {
        return $this->status === self::STATUS_REVIEW;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_POSTED,
            self::STATUS_CANCELLED,
            self::STATUS_REJECTED,
        ]);
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_COUNTING,
            self::STATUS_REVIEW,
        ]);
    }

    public function canBePosted(): bool
    {
        // Typically only from Review state
        return $this->status === self::STATUS_REVIEW;
    }
}
