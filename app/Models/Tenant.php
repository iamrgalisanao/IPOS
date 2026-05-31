<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'status',
        'currency',
        'timezone',
        'tax_mode',
        'receipt_header',
        'receipt_footer',
        'business_registration_number',
        'subscription_metadata',
        'offline_sales_enabled',
        'default_cash_drawer_limit',
    ];

    protected $attributes = [
        'currency' => 'PHP',
        'timezone' => 'Asia/Manila',
        'tax_mode' => 'exclusive',
    ];

    protected $casts = [
        'subscription_metadata' => 'array',
        'offline_sales_enabled' => 'boolean',
        'default_cash_drawer_limit' => 'decimal:4',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function cashDrawerEvents(): HasMany
    {
        return $this->hasMany(CashDrawerEvent::class);
    }

    /**
     * Determine if the tenant has access to a specific feature.
     */
    public function hasFeature(string $feature): bool
    {
        $metadata = $this->subscription_metadata ?? [];
        $plan = $metadata['plan'] ?? config('subscriptions.default_tier', 'basic');
        
        // Load tier features from configuration, fallback to default basic tier if plan is unrecognized
        $tierConfig = config("subscriptions.tiers.{$plan}") ?? config('subscriptions.tiers.' . config('subscriptions.default_tier', 'basic'));
        $features = $tierConfig['features'] ?? [];

        // Check for tenant-specific feature overrides
        if (isset($metadata['features'][$feature])) {
            return (bool) $metadata['features'][$feature];
        }

        return (bool) ($features[$feature] ?? false);
    }

    /**
     * Determine if a resource count is strictly within the tenant's permitted limits.
     * Evaluates if the current count is strictly less than the allowed threshold.
     */
    public function withinLimit(string $limit, int $currentCount): bool
    {
        $metadata = $this->subscription_metadata ?? [];
        $plan = $metadata['plan'] ?? config('subscriptions.default_tier', 'basic');

        // Load tier limits from configuration
        $tierConfig = config("subscriptions.tiers.{$plan}") ?? config('subscriptions.tiers.' . config('subscriptions.default_tier', 'basic'));
        $limits = $tierConfig['limits'] ?? [];

        // Check for tenant-specific limit overrides
        if (isset($metadata['limits'][$limit])) {
            $allowed = (int) $metadata['limits'][$limit];
        } else {
            $allowed = (int) ($limits[$limit] ?? 0);
        }

        return $currentCount < $allowed;
    }

    /**
     * Expiry lots registered for this tenant.
     */
    public function expiryLots(): HasMany
    {
        return $this->hasMany(ExpiryLot::class);
    }

    /**
     * Supplier invoices linked to this tenant.
     */
    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }
}

