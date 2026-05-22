<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InterBranchTransfer extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'source_branch_id',
        'destination_branch_id',
        'reference_number',
        'status',
        'transfer_date',
        'notes',
        'created_by',
        'approved_by',
        'dispatched_by',
        'received_by',
        'cancelled_by',
        'approved_at',
        'dispatched_at',
        'received_at',
        'cancelled_at',
    ];

    protected $casts = [
        'transfer_date'  => 'date',
        'approved_at'    => 'datetime',
        'dispatched_at'  => 'datetime',
        'received_at'    => 'datetime',
        'cancelled_at'   => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    // Status helpers
    public function isDraft(): bool           { return $this->status === self::STATUS_DRAFT; }
    public function isPendingApproval(): bool  { return $this->status === self::STATUS_PENDING_APPROVAL; }
    public function isApproved(): bool         { return $this->status === self::STATUS_APPROVED; }
    public function isInTransit(): bool        { return $this->status === self::STATUS_IN_TRANSIT; }
    public function isReceived(): bool         { return $this->status === self::STATUS_RECEIVED; }
    public function isCancelled(): bool        { return $this->status === self::STATUS_CANCELLED; }

    public function isTerminal(): bool
    {
        return $this->isReceived() || $this->isCancelled();
    }

    public function canBeCancelled(): bool
    {
        // Per Q2 decision: cancellation is only allowed before in_transit
        return !$this->isInTransit() && !$this->isTerminal();
    }

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InterBranchTransferLine::class);
    }
}
