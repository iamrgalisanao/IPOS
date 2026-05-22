<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SENT = 'sent';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'supplier_id',
        'master_purchase_order_id',
        'po_number',
        'status',
        'order_date',
        'expected_delivery_date',
        'total_estimated_amount',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'sent_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'total_estimated_amount' => 'decimal:4',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // Status Helper checks
    public static function generatePoNumber(string $tenantId, string $branchId, string $orderDate): string
    {
        $branch = Branch::where('tenant_id', $tenantId)->findOrFail($branchId);
        $branchCode = strtoupper($branch->branch_code);
        $dateStr = date('Ymd', strtotime($orderDate));
        
        $count = self::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('order_date', $orderDate)
            ->count();
            
        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        $poNumber = "PO-{$branchCode}-{$dateStr}-{$sequence}";
        
        $exists = self::where('tenant_id', $tenantId)->where('po_number', $poNumber)->exists();
        $attempt = 1;
        while ($exists) {
            $sequence = str_pad($count + 1 + $attempt, 4, '0', STR_PAD_LEFT);
            $poNumber = "PO-{$branchCode}-{$dateStr}-{$sequence}";
            $exists = self::where('tenant_id', $tenantId)->where('po_number', $poNumber)->exists();
            $attempt++;
        }
        
        return $poNumber;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isCancelled();
    }

    // State Transitions Allowed checks
    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    public function canBeSubmitted(): bool
    {
        return $this->isDraft();
    }

    public function canBeApproved(): bool
    {
        return $this->isPendingApproval();
    }

    public function canBeSent(): bool
    {
        return $this->isApproved();
    }

    public function canBeCompleted(): bool
    {
        return $this->isSent();
    }

    public function canBeCancelled(): bool
    {
        return !$this->isTerminal();
    }

    // Relationships
    public function masterPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(MasterPurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }
}
