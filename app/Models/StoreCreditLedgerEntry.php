<?php

namespace App\Models;

use App\Exceptions\StoreCredit\StoreCreditLedgerImmutableException;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class StoreCreditLedgerEntry extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    public const CATEGORY_CREDIT = 'credit';
    public const CATEGORY_DEBIT = 'debit';
    public const CATEGORY_ADJUSTMENT = 'adjustment';
    public const CATEGORY_REVERSAL = 'reversal';
    public const CATEGORY_EXPIRATION = 'expiration';

    public const TYPE_REFUND_CREDIT = 'refund_credit';
    public const TYPE_REDEMPTION_DEBIT = 'redemption_debit';
    public const TYPE_ADMIN_CREDIT_ADJUSTMENT = 'admin_credit_adjustment';
    public const TYPE_ADMIN_DEBIT_ADJUSTMENT = 'admin_debit_adjustment';
    public const TYPE_REVERSAL_CREDIT = 'reversal_credit';
    public const TYPE_REVERSAL_DEBIT = 'reversal_debit';
    public const TYPE_EXPIRATION_DEBIT = 'expiration_debit';
    public const TYPE_FORFEITURE_DEBIT = 'forfeiture_debit';

    public const LEDGER_SCHEMA_VERSION = 1;
    public const FINGERPRINT_VERSION = 1;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'customer_financial_account_id',
        'ledger_sequence',
        'ledger_schema_version',
        'ledger_category',
        'entry_type',
        'direction',
        'amount_centavos',
        'currency_code',
        'source_type',
        'source_id',
        'source_reference',
        'source_snapshot',
        'idempotency_key',
        'request_fingerprint',
        'fingerprint_version',
        'business_date',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'ledger_sequence' => 'integer',
        'ledger_schema_version' => 'integer',
        'amount_centavos' => 'integer',
        'source_snapshot' => 'array',
        'fingerprint_version' => 'integer',
        'business_date' => 'date',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new StoreCreditLedgerImmutableException('Store credit ledger entries are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new StoreCreditLedgerImmutableException('Store credit ledger entries are append-only and cannot be deleted.');
        });
    }

    public static function categoryForEntryType(string $entryType): string
    {
        return match ($entryType) {
            self::TYPE_REFUND_CREDIT => self::CATEGORY_CREDIT,
            self::TYPE_REDEMPTION_DEBIT => self::CATEGORY_DEBIT,
            self::TYPE_ADMIN_CREDIT_ADJUSTMENT,
            self::TYPE_ADMIN_DEBIT_ADJUSTMENT => self::CATEGORY_ADJUSTMENT,
            self::TYPE_REVERSAL_CREDIT,
            self::TYPE_REVERSAL_DEBIT => self::CATEGORY_REVERSAL,
            self::TYPE_EXPIRATION_DEBIT,
            self::TYPE_FORFEITURE_DEBIT => self::CATEGORY_EXPIRATION,
            default => throw new RuntimeException('Unsupported store credit ledger entry type.'),
        };
    }

    public function customerFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerFinancialAccount::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function storeCreditRefundIssuance(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StoreCreditRefundIssuance::class);
    }

    public function storeCreditRedemption(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StoreCreditRedemption::class);
    }
}
