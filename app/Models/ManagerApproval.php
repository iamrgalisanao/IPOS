<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagerApproval extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'user_id',           // The manager who approved
        'requesting_user_id', // The cashier who requested
        'approvable_type',   // e.g., 'Sale', 'SaleStatutoryDiscount'
        'approvable_id',
        'action',            // 'approve', 'deny'
        'reason',
        'metadata',          // JSON snapshot of what was approved
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_user_id');
    }

    public function approvable()
    {
        return $this->morphTo();
    }
}
