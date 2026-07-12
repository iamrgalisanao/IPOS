<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrinterProfile extends Model
{
    use HasFactory, HasUuids, BelongsToTenant, BelongsToBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'connection_type',
        'identifier',
        'paper_width',
        'role',
        'template_type',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Terminals assigned to this printer profile.
     */
    public function salesMachineProfiles(): HasMany
    {
        return $this->hasMany(SalesMachineProfile::class, 'printer_profile_id');
    }

    /**
     * Scope active profiles.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope default profiles.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
