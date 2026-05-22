<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterBranchTransferLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'inter_branch_transfer_id',
        'product_id',
        'expiry_lot_id',
        'quantity_transferred',
        // unit_cost = source branch WAC frozen at dispatch time (Q3 decision)
        'unit_cost',
        'line_total',
    ];

    protected $casts = [
        'quantity_transferred' => 'decimal:4',
        'unit_cost'            => 'decimal:4',
        'line_total'           => 'decimal:4',
    ];

    // Relationships
    public function interBranchTransfer(): BelongsTo
    {
        return $this->belongsTo(InterBranchTransfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function expiryLot(): BelongsTo
    {
        return $this->belongsTo(ExpiryLot::class);
    }
}
