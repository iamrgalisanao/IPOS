<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTimecard extends Model
{
    use HasFactory, HasUuids, \App\Traits\BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'terminal_id',
        'user_id',
        'clocked_in_at',
        'clocked_out_at',
        'clock_in_ip',
        'clock_out_ip',
        'clock_in_device_id',
        'clock_out_device_id',
        'clock_in_method',
        'clock_out_method',
        'clock_out_reason',
        'manually_adjusted_by',
        'manually_adjusted_at',
        'manual_adjustment_reason',
        'is_active', // Supporting MySQL fallback unique index if active
    ];

    protected $casts = [
        'clocked_in_at' => 'datetime',
        'clocked_out_at' => 'datetime',
        'manually_adjusted_at' => 'datetime',
        'is_active' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manuallyAdjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manually_adjusted_by');
    }
}
