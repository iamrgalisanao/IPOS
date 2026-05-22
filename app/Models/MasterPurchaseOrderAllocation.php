<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterPurchaseOrderAllocation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'master_purchase_order_line_id',
        'branch_id',
        'child_purchase_order_id',
        'allocated_quantity',
    ];

    protected $casts = [
        'allocated_quantity' => 'decimal:4',
    ];

    // Relationships
    public function masterPurchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(MasterPurchaseOrderLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function childPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'child_purchase_order_id');
    }
}
