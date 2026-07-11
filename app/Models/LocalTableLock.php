<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalTableLock extends Model
{
    use HasFactory, HasUuids, \App\Traits\BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'table_id',
        'locked_by_profile_id',
        'locked_at',
        'expires_at',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lockedByProfile(): BelongsTo
    {
        return $this->belongsTo(SalesMachineProfile::class, 'locked_by_profile_id');
    }
}
