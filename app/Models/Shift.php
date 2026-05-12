<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSING_SUBMITTED = 'closing_submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'cashier_id',
        'opened_by',
        'approved_by',
        'closed_by',
        'status',
        'opening_cash_amount',
        'counted_cash_amount',
        'expected_cash_amount',
        'variance_amount',
        'opened_at',
        'closing_submitted_at',
        'approved_at',
        'closed_at',
        'closing_notes',
        'manager_notes',
    ];

    protected $casts = [
        'opening_cash_amount' => 'decimal:4',
        'counted_cash_amount' => 'decimal:4',
        'expected_cash_amount' => 'decimal:4',
        'variance_amount' => 'decimal:4',
        'opened_at' => 'datetime',
        'closing_submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function cashDrawerEvents(): HasMany
    {
        return $this->hasMany(CashDrawerEvent::class);
    }

    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }
}
