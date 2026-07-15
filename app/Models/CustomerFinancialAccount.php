<?php

namespace App\Models;

use App\Exceptions\Customers\CustomerFinancialAccountCurrencyImmutableException;
use App\Exceptions\Customers\CustomerFinancialAccountOwnershipImmutableException;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerFinancialAccount extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'status',
        'currency_code',
        'opened_at',
        'suspended_at',
        'closed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'suspended_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (CustomerFinancialAccount $account) {
            if ($account->isDirty('customer_id')) {
                throw new CustomerFinancialAccountOwnershipImmutableException('Customer financial account ownership is immutable.');
            }

            if ($account->isDirty('currency_code')) {
                throw new CustomerFinancialAccountCurrencyImmutableException('Customer financial account currency is immutable.');
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function storeCreditLedgerEntries(): HasMany
    {
        return $this->hasMany(StoreCreditLedgerEntry::class)
            ->orderBy('ledger_sequence');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', self::STATUS_CLOSED);
    }
}
