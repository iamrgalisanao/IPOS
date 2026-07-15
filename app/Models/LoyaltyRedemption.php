<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRedemption extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_FAILED = 'failed';

    public const SNAPSHOT_VERSION = 1;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sale_id',
        'customer_financial_account_id',
        'loyalty_rule_id',
        'loyalty_ledger_entry_id',
        'points',
        'benefit_centavos',
        'authorized_balance_points',
        'status',
        'idempotency_key',
        'rule_snapshot',
        'source_snapshot',
        'redeemed_by',
        'authorized_at',
        'redeemed_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'points' => 'integer',
        'benefit_centavos' => 'integer',
        'authorized_balance_points' => 'integer',
        'rule_snapshot' => 'array',
        'source_snapshot' => 'array',
        'authorized_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customerFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerFinancialAccount::class);
    }

    public function loyaltyRule(): BelongsTo
    {
        return $this->belongsTo(LoyaltyRule::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LoyaltyLedgerEntry::class, 'loyalty_ledger_entry_id');
    }
}
