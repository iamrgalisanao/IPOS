<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualRefundRequest extends Model
{
    use HasFactory, HasUuids, \App\Traits\BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'sale_refund_id',
        'original_payment_method',
        'requested_refund_amount',
        'requested_by',
        'approved_by',
        'processed_by',
        'status',
        'customer_refund_channel',
        'customer_reference_details',
        'finance_notes',
    ];

    protected $casts = [
        'requested_refund_amount' => 'decimal:4',
        'customer_reference_details' => 'encrypted',
        'approved_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(SaleRefund::class, 'sale_refund_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
