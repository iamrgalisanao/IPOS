<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxCategory extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'tax_type',
        'rate',
        'is_default',
        'status',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_default' => 'boolean',
    ];

    /**
     * Scope a query to only include active tax categories.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
