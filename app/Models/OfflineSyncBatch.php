<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfflineSyncBatch extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    // Lifecycle states
    const STATUS_RECEIVED   = 'received';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sales_machine_profile_id',
        'batch_reference',
        'status',
        'submitted_import_count',
        'processed_count',
        'failed_count',
        'sync_started_at',
        'sync_completed_at',
    ];

    protected $casts = [
        'submitted_import_count' => 'integer',
        'processed_count'        => 'integer',
        'failed_count'           => 'integer',
        'sync_started_at'        => 'datetime',
        'sync_completed_at'      => 'datetime',
    ];

    public function salesMachineProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(OfflineSalesImport::class, 'batch_id');
    }
}
