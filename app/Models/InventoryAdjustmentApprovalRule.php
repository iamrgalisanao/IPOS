<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustmentApprovalRule extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'reason_id',
        'minimum_absolute_quantity',
        'threshold_unit',
        'minimum_percentage_of_stock',
        'minimum_value_centavos',
        'required_permission',
        'requires_distinct_approver',
        'priority',
        'is_active',
        'rule_version',
        'rule_schema_version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'minimum_absolute_quantity' => 'decimal:4',
        'minimum_percentage_of_stock' => 'decimal:4',
        'minimum_value_centavos' => 'integer',
        'requires_distinct_approver' => 'boolean',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'rule_version' => 'integer',
        'rule_schema_version' => 'integer',
    ];

    public function reason(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustmentReason::class, 'reason_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
