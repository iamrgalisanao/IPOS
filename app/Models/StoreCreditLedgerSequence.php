<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCreditLedgerSequence extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

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
