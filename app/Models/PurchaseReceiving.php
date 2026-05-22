<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReceiving extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'supplier_id',
        'purchase_order_id',
        'receiving_number',
        'status',
        'delivery_ref_number',
        'received_at',
        'total_received_amount',
        'notes',
        'received_by',
        'posted_by',
        'posted_at',
        'cancelled_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'total_received_amount' => 'decimal:4',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Generate unique GRV number scoped by tenant, branch, and date.
     */
    public static function generateReceivingNumber(string $tenantId, string $branchId, string $receivedAtDate): string
    {
        $branch = Branch::where('tenant_id', $tenantId)->findOrFail($branchId);
        $branchCode = strtoupper($branch->branch_code);
        $dateStr = date('Ymd', strtotime($receivedAtDate));
        
        $count = self::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->whereDate('received_at', $receivedAtDate)
            ->count();
            
        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        $receivingNumber = "GRV-{$branchCode}-{$dateStr}-{$sequence}";
        
        $exists = self::where('tenant_id', $tenantId)->where('receiving_number', $receivingNumber)->exists();
        $attempt = 1;
        while ($exists) {
            $sequence = str_pad($count + 1 + $attempt, 4, '0', STR_PAD_LEFT);
            $receivingNumber = "GRV-{$branchCode}-{$dateStr}-{$sequence}";
            $exists = self::where('tenant_id', $tenantId)->where('receiving_number', $receivingNumber)->exists();
            $attempt++;
        }
        
        return $receivingNumber;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTerminal(): bool
    {
        return $this->isPosted() || $this->isCancelled();
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    public function canBePosted(): bool
    {
        return $this->isDraft();
    }

    public function canBeCancelled(): bool
    {
        return $this->isDraft();
    }

    // Relationships
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceivingLine::class);
    }
}
