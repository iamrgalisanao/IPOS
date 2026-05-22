<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierReturn extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_POSTED = 'posted';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'supplier_id',
        'purchase_receiving_id',
        'document_number',
        'status',
        'return_date',
        'total_amount',
        'notes',
        'created_by',
        'approved_by',
        'posted_by',
        'cancelled_by',
        'approved_at',
        'posted_at',
        'cancelled_at',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'decimal:4',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'total_amount' => 0.0000,
    ];

    /**
     * Generate a unique document number per tenant and branch context.
     */
    public static function generateDocumentNumber(string $tenantId, string $branchId, string $returnDate): string
    {
        $branch = Branch::where('tenant_id', $tenantId)->findOrFail($branchId);
        $branchCode = strtoupper($branch->branch_code);
        $dateStr = date('Ymd', strtotime($returnDate));
        
        $count = self::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('return_date', $returnDate)
            ->count();
            
        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        $docNumber = "RMA-{$branchCode}-{$dateStr}-{$sequence}";
        
        $exists = self::where('tenant_id', $tenantId)->where('document_number', $docNumber)->exists();
        $attempt = 1;
        while ($exists) {
            $sequence = str_pad($count + 1 + $attempt, 4, '0', STR_PAD_LEFT);
            $docNumber = "RMA-{$branchCode}-{$dateStr}-{$sequence}";
            $exists = self::where('tenant_id', $tenantId)->where('document_number', $docNumber)->exists();
            $attempt++;
        }
        
        return $docNumber;
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

    public function canBeSubmitted(): bool
    {
        return $this->isDraft();
    }

    public function canBeApproved(): bool
    {
        return $this->isPendingApproval();
    }

    public function canBePosted(): bool
    {
        return $this->isApproved();
    }

    public function canBeCancelled(): bool
    {
        return !$this->isTerminal();
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

    public function purchaseReceiving(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiving::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class);
    }
}
