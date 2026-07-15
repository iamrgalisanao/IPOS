<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyLedgerSequence extends Model
{
    use HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_financial_account_id',
        'next_sequence',
    ];

    protected $casts = [
        'next_sequence' => 'integer',
    ];

    public function customerFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerFinancialAccount::class);
    }
}
