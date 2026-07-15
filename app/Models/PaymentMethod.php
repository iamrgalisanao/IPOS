<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
        'reference_required',
        'strict_reference_mode',
        'settlement_tracking_enabled',
        'is_default',
        'status',
    ];

    protected $casts = [
        'reference_required' => 'boolean',
        'strict_reference_mode' => 'boolean',
        'settlement_tracking_enabled' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (PaymentMethod $method) {
            if (!$method->isActiveStoreCreditTender()) {
                return;
            }

            $exists = self::query()
                ->where('tenant_id', $method->tenant_id)
                ->where('status', 'active')
                ->when($method->exists, fn ($query) => $query->whereKeyNot($method->getKey()))
                ->where(function ($query) {
                    $query->whereRaw('LOWER(code) = ?', ['store_credit'])
                        ->orWhereRaw('LOWER(type) = ?', ['store_credit']);
                })
                ->exists();

            if ($exists) {
                throw new \RuntimeException('Only one active Store Credit payment method may exist per tenant.');
            }
        });
    }

    /**
     * Scope a query to only include active payment methods.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if this payment method is Cash.
     */
    public function isCash(): bool
    {
        return strtolower($this->code) === 'cash';
    }

    public function isStoreCredit(): bool
    {
        return strtolower((string) $this->code) === 'store_credit'
            || strtolower((string) $this->type) === 'store_credit';
    }

    public function isActiveStoreCreditTender(): bool
    {
        return $this->status === 'active' && $this->isStoreCredit();
    }

    /**
     * Relationship to branch overrides.
     */
    public function branchSettings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BranchPaymentMethodSetting::class, 'payment_method_id');
    }

    /**
     * Resolve the active settings for a branch, falling back to global defaults.
     */
    public function getSettingsForBranch(string $branchId): array
    {
        $setting = $this->branchSettings()->where('branch_id', $branchId)->first();

        $isCash = $this->isCash();
        $isCustomOffline = strtolower($this->type) === 'custom_offline' || strtolower($this->type) === 'custom';

        if ($setting) {
            return [
                'id' => $this->id,
                'code' => $this->code,
                'name' => $this->name,
                'type' => $this->type,
                'enabled' => (bool) $setting->enabled,
                'allow_offline' => (bool) $setting->allow_offline,
                'offline_max_limit_centavos' => $setting->offline_max_limit_centavos,
                'requires_reference' => (bool) $setting->requires_reference,
                'sort_order' => (int) $setting->sort_order,
                'offline_policy_note' => $setting->offline_policy_note,
                'gateway_supports_offline_capture' => $isCash || $isCustomOffline,
            ];
        }

        // Fallback defaults
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'enabled' => $this->status === 'active',
            'allow_offline' => $isCash, // cash defaults to true, others false
            'offline_max_limit_centavos' => null,
            'requires_reference' => (bool) $this->reference_required,
            'sort_order' => 0,
            'offline_policy_note' => $isCash ? 'Cash allowed offline by default.' : null,
            'gateway_supports_offline_capture' => $isCash || $isCustomOffline,
        ];
    }
}
