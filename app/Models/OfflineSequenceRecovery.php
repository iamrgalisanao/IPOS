<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSequenceRecovery extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    // Recovery types
    const TYPE_GAP_DETECTED      = 'gap_detected';
    const TYPE_DUPLICATE_DETECTED = 'duplicate_detected';
    const TYPE_RANGE_DEPLETED    = 'range_depleted';
    const TYPE_MANUAL_CORRECTION = 'manual_correction';

    // Resolution types
    const RESOLUTION_RANGE_EXTENDED    = 'range_extended';
    const RESOLUTION_PREFIX_REASSIGNED = 'prefix_reassigned';
    const RESOLUTION_IMPORTS_REJECTED  = 'imports_rejected';

    protected $fillable = [
        'tenant_id',
        'sales_machine_profile_id',
        'recovery_type',
        'affected_prefix',
        'affected_range_start',
        'affected_range_end',
        'resolution',
        'resolved_by_user_id',
        'resolved_at',
        'notes',
    ];

    protected $casts = [
        'affected_range_start' => 'integer',
        'affected_range_end'   => 'integer',
        'resolved_at'          => 'datetime',
    ];

    public function salesMachineProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class);
    }

    /**
     * The admin user who resolved this recovery event.
     * Only users with manage_offline_sales_settings may resolve.
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
