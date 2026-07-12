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
        'sales_machine_profile_id',
        'discount_type_id',
        'approval_rule_id',
        'context_version',
        'context_hmac',
        'status',
        'expires_at',
        'consumed_at',
        'consumed_by_sale_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
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
