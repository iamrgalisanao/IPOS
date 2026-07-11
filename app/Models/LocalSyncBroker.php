<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalSyncBroker extends Model
{
    use HasFactory, HasUuids, \App\Traits\BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'master_profile_id',
        'local_ip_address',
        'local_port',
        'last_heartbeat_at',
        'status',
    ];

    protected $casts = [
        'local_port' => 'integer',
        'last_heartbeat_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function masterProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class, 'master_profile_id');
    }
}
