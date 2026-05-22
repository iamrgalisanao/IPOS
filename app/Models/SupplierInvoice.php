<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_DISCREPANT = 'discrepant';
    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'supplier_id',
        'purchase_order_id',
        'purchase_receiving_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'subtotal',
        'tax_total',
        'total_amount',
        'match_status',
        'notes',
        'created_by',
        'posted_by',
        'posted_at',
        'matching_metadata',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'posted_at' => 'datetime',
        'matching_metadata' => 'array',
    ];

    protected $attributes = [
        'match_status' => self::STATUS_PENDING,
        'subtotal' => 0.0000,
        'tax_total' => 0.0000,
        'total_amount' => 0.0000,
    ];

    public function isPending(): bool
    {
        return $this->match_status === self::STATUS_PENDING;
    }

    public function isMatched(): bool
    {
        return $this->match_status === self::STATUS_MATCHED;
    }

    public function isDiscrepant(): bool
    {
        return $this->match_status === self::STATUS_DISCREPANT;
    }

    public function isPosted(): bool
    {
        return $this->match_status === self::STATUS_POSTED;
    }

    /**
     * Enforce immutability once a supplier invoice reaches the `posted` state.
     */
    protected static function booted(): void
    {
        static::updating(function (self $model) {
            if ($model->getOriginal('match_status') === self::STATUS_POSTED
                && $model->isDirty('match_status')) {
                throw new \RuntimeException(
                    "SupplierInvoice [{$model->id}] is posted and immutable — match_status cannot be changed."
                );
            }
        });
    }

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseReceiving(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiving::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class);
    }
}
