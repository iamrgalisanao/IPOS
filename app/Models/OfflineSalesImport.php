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

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sales_machine_profile_id',
        'batch_id',
        'offline_sequence_number',
        'payload_hash',
        'raw_payload',
        'status',
        'rejection_reason',
        'reconciled_sale_id',
        'submitted_at',
        'reconciled_at',
        'server_recalculation',
        'conflict_notes',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'raw_payload'          => 'array',
        'server_recalculation' => 'array',
        'submitted_at'         => 'datetime',
        'reconciled_at'        => 'datetime',
        'reviewed_at'          => 'datetime',
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
}
