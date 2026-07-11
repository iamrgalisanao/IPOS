<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchPaymentMethodSetting extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'payment_method_id',
        'enabled',
        'allow_offline',
        'offline_max_limit_centavos',
        'requires_reference',
        'sort_order',
        'offline_policy_note',
    ];

    protected $casts = [
        'enabled'                    => 'boolean',
        'allow_offline'              => 'boolean',
        'offline_max_limit_centavos' => 'integer',
        'requires_reference'         => 'boolean',
        'sort_order'                 => 'integer',
    ];

    /**
     * Relationship to the parent PaymentMethod.
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
