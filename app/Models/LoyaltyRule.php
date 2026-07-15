<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRule extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const TYPE_EARNING = 'earning';
    public const TYPE_REDEMPTION = 'redemption';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'code',
        'rule_type',
        'rule_version',
        'status',
        'priority',
        'configuration',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'rule_version' => 'integer',
        'priority' => 'integer',
        'configuration' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
