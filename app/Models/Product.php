<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory, HasUuids, BelongsToTenant;
    
    protected $fillable = [
        'tenant_id',
        'product_category_id',
        'name',
        'sku',
        'barcode',
        'selling_price',
        'cost_price',
        'is_discountable',
        'description',
        'unit_of_measure',
        'is_taxable',
        'is_inventory_tracked',
        'tax_category_id',
        'status',
    ];

    protected $casts = [
        'selling_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'is_discountable' => 'boolean',
        'is_taxable' => 'boolean',
        'is_inventory_tracked' => 'boolean',
    ];

    /**
     * Get a point-in-time snapshot base for POS checkout.
     */
    public function getSaleSnapshotBase(): array
    {
        $tax = $this->taxCategory;

        return [
            'product_id' => $this->id,
            'product_name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'unit_of_measure' => $this->unit_of_measure,
            'selling_price' => (float) $this->selling_price,
            'tax_category_id' => $this->tax_category_id,
            'tax_type' => $tax ? $tax->tax_type : null,
            'tax_rate' => $tax ? (float) $tax->rate : 0.0,
            'is_discountable' => $this->is_discountable,
        ];
    }

    /**
     * Scope a query to only include active products.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    public function branchInventories()
    {
        return $this->hasMany(BranchInventory::class);
    }
}
