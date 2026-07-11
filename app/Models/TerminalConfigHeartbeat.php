<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerminalConfigHeartbeat extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sales_machine_profile_id',
        'app_version',
        'device_id',
        'config_snapshot',
        'last_snapshot_downloaded_at',
        'last_successful_sync_at',
        'queue_count',
        'connection_state',
        'reported_at',
    ];

    protected $casts = [
        'config_snapshot' => 'array',
        'last_snapshot_downloaded_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
        'queue_count' => 'integer',
        'reported_at' => 'datetime',
    ];

    public function salesMachineProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class);
    }
}
