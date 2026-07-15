<?php

namespace App\Models;

use App\Exceptions\StoreCredit\StoreCreditRefundImmutableException;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCreditRefundIssuance extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const SNAPSHOT_VERSION = 1;
    public const REFUND_SCHEMA_VERSION = 1;
    public const CUSTOMER_ACCOUNT_SCHEMA_VERSION = 1;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'sale_refund_id',
        'customer_financial_account_id',
        'store_credit_ledger_entry_id',
        'amount_centavos',
        'currency_code',
        'idempotency_key',
        'source_snapshot',
        'issued_by',
        'issued_at',
    ];

    protected $casts = [
        'amount_centavos' => 'integer',
        'source_snapshot' => 'array',
        'issued_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new StoreCreditRefundImmutableException('Store credit refund issuance records are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new StoreCreditRefundImmutableException('Store credit refund issuance records are append-only and cannot be deleted.');
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(SaleRefund::class, 'sale_refund_id');
    }

    public function customerFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerFinancialAccount::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(StoreCreditLedgerEntry::class, 'store_credit_ledger_entry_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
