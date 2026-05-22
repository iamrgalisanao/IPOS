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

    public function isVatable(): bool
    {
        return $this->tax_type === 'vatable';
    }

    public function isExempt(): bool
    {
        return $this->tax_type === 'exempt';
    }

    public function isZeroRated(): bool
    {
        return $this->tax_type === 'zero-rated';
    }

    public function isNonVat(): bool
    {
        return $this->tax_type === 'non-vat';
    }

    public function isVatBearing(): bool
    {
        return $this->isVatable();
    }

    public function birCode(): string
    {
        return match ($this->tax_type) {
            'vatable' => 'VAT',
            'exempt' => 'EXM',
            'zero-rated' => 'ZRO',
            'non-vat' => 'NONVAT',
            default => strtoupper($this->code),
        };
    }

    public function displayLabel(): string
    {
        return match ($this->tax_type) {
            'vatable' => 'VATable Sale',
            'exempt' => 'VAT-Exempt Sale',
            'zero-rated' => 'Zero-Rated Sale',
            'non-vat' => 'Non-VAT Sale',
            default => $this->name,
        };
    }
}
