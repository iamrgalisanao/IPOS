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
}
