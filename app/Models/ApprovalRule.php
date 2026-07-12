<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApprovalRule extends Model
{
    use HasUuids, BelongsToTenant;

    public const ACTION_STATUTORY_DISCOUNT = 'statutory_discount';

    protected $fillable = [
        'tenant_id', 'branch_id', 'scope_key', 'action',
        'always_require_approval', 'updated_by',
    ];

    protected $casts = ['always_require_approval' => 'boolean'];
}
