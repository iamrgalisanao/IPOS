<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterPurchaseOrder extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SPLIT = 'split';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'master_po_number',
        'status',
        'order_date',
        'expected_delivery_date',
        'total_estimated_amount',
        'notes',
        'created_by',
        'approved_by',
        'cancelled_by',
        'approved_at',
        'split_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'order_date'              => 'date',
        'expected_delivery_date'  => 'date',
        'total_estimated_amount'  => 'decimal:4',
        'approved_at'             => 'datetime',
        'split_at'                => 'datetime',
        'completed_at'            => 'datetime',
        'cancelled_at'            => 'datetime',
    ];

    protected $attributes = [
        'status'                 => self::STATUS_DRAFT,
        'total_estimated_amount' => 0.0000,
    ];

    // Status helpers
    public function isDraft(): bool            { return $this->status === self::STATUS_DRAFT; }
    public function isPendingApproval(): bool   { return $this->status === self::STATUS_PENDING_APPROVAL; }
    public function isApproved(): bool          { return $this->status === self::STATUS_APPROVED; }
    public function isSplit(): bool             { return $this->status === self::STATUS_SPLIT; }
    public function isCompleted(): bool         { return $this->status === self::STATUS_COMPLETED; }
    public function isCancelled(): bool         { return $this->status === self::STATUS_CANCELLED; }

    public function isTerminal(): bool
    {
        return $this->isSplit() || $this->isCompleted() || $this->isCancelled();
    }

    /**
     * Post-split immutability guard — lines and allocations cannot be mutated once split.
     */
    protected static function booted(): void
    {
        static::updating(function (self $model) {
            $immutableAfterSplit = ['supplier_id', 'master_po_number', 'order_date'];
            if ($model->getOriginal('status') === self::STATUS_SPLIT) {
                foreach ($immutableAfterSplit as $field) {
                    if ($model->isDirty($field)) {
                        throw new \RuntimeException(
                            "MasterPurchaseOrder [{$model->id}] is split — field '{$field}' is immutable."
                        );
                    }
                }
            }
        });
    }

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MasterPurchaseOrderLine::class);
    }

    public function childPurchaseOrders(): \Illuminate\Database\Eloquent\Builder
    {
        return PurchaseOrder::where('tenant_id', $this->tenant_id)
            ->where('master_purchase_order_id', $this->id);
    }
}
