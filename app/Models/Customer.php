<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ANONYMIZED = 'anonymized';

    protected $fillable = [
        'tenant_id',
        'display_name',
        'normalized_display_name',
        'email',
        'phone',
        'external_reference',
        'status',
        'metadata',
        'anonymized_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'anonymized_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::forceDeleting(function (Customer $customer) {
            if ($customer->financialAccount()->exists()) {
                throw new \RuntimeException('Customers with financial accounts cannot be physically deleted.');
            }
        });
    }

    public function financialAccount(): HasOne
    {
        return $this->hasOne(CustomerFinancialAccount::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
