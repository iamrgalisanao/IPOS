<?php

namespace App\Models;

use App\Exceptions\StoreCredit\StoreCreditRedemptionImmutableException;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCreditRedemption extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const SNAPSHOT_VERSION = 1;
    public const PAYMENT_SCHEMA_VERSION = 1;
    public const CUSTOMER_ACCOUNT_SCHEMA_VERSION = 1;
    public const AUTHORIZATION_SCHEMA_VERSION = 1;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'sale_payment_id',
        'customer_financial_account_id',
        'store_credit_ledger_entry_id',
        'amount_centavos',
        'currency_code',
        'idempotency_key',
        'authorized_balance_centavos',
        'source_snapshot',
        'redeemed_by',
        'redeemed_at',
    ];

    protected $casts = [
        'amount_centavos' => 'integer',
        'authorized_balance_centavos' => 'integer',
        'source_snapshot' => 'array',
        'redeemed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new StoreCreditRedemptionImmutableException('Store credit redemptions are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new StoreCreditRedemptionImmutableException('Store credit redemptions are append-only and cannot be deleted.');
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class);
    }

    public function customerFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerFinancialAccount::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(StoreCreditLedgerEntry::class, 'store_credit_ledger_entry_id');
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }
}
