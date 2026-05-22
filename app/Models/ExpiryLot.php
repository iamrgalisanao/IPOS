<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpiryLot extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'product_id',
        'purchase_receiving_line_id',
        'batch_code',
        'quantity_received',
        'quantity_remaining',
        'expiry_date',
        'status',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:4',
        'quantity_remaining' => 'decimal:4',
        'expiry_date' => 'date',
    ];

    /**
     * Relationship to the Tenant this lot belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Relationship to the Branch this lot belongs to.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Relationship to the Product this lot belongs to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Optional relationship to the PurchaseReceivingLine from which this lot was created.
     */
    public function purchaseReceivingLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceivingLine::class);
    }
}
