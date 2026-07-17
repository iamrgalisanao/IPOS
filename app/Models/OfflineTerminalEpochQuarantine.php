<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfflineTerminalEpochQuarantine extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sales_machine_profile_id',
        'terminal_binding_epoch',
        'quarantine_reason',
        'source_offline_import_id',
        'quarantine_status',
        'quarantined_at',
        'released_at',
        'released_by',
        'release_reference',
    ];

    protected $casts = [
        'quarantined_at' => 'datetime',
        'released_at' => 'datetime',
    ];
}
