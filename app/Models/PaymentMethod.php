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
}
