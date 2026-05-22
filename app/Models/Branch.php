<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected static function booted()
    {
        parent::booted();

        static::creating(function ($branch) {
            $tenant = $branch->tenant;
            if (!$tenant && $branch->tenant_id) {
                $tenant = Tenant::find($branch->tenant_id);
            }
            if (!$tenant) {
                $tenant = app(\App\Services\TenantContext::class)->getTenant();
            }

            if ($tenant) {
                // Ensure branch limit is not exceeded on new creation
                $currentCount = $tenant->branches()->count();
                if (!$tenant->withinLimit('max_branches', $currentCount)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'branch' => ['Branch limit exceeded for active subscription tier. Please upgrade.']
                    ]);
                }
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'branch_code',
        'status',
        'address',
        'contact_number',
        'timezone',
        'receipt_prefix',
        'receipt_next_number',
        'inventory_deduction_policy',
        'offline_sales_enabled',
    ];

    protected $casts = [
        'offline_sales_enabled' => 'boolean',
    ];

    /**
     * Get the branch timezone, falling back to tenant timezone if blank.
     */
    public function getTimezone(): string
    {
        return $this->timezone ?: $this->tenant->timezone;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shifts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function posLayouts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(PosLayout::class, 'branch_pos_layout')
            ->withPivot([
                'id',
                'active_from',
                'active_until',
                'is_active',
                'published_by',
                'published_at'
            ])
            ->withTimestamps();
    }

    public function cashDrawerEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CashDrawerEvent::class);
    }

    /**
     * Scope a query to only include active branches.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Expiry lots registered under this branch.
     */
    public function expiryLots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExpiryLot::class);
    }
}
