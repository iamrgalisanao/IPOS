<?php

namespace App\Services;

use App\Models\Product;
use App\Models\BranchInventory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    protected AuditLogger $auditLogger;
    protected TenantContext $tenantContext;
    protected BranchContext $branchContext;

    public function __construct(AuditLogger $auditLogger, TenantContext $tenantContext, BranchContext $branchContext)
    {
        $this->auditLogger = $auditLogger;
        $this->tenantContext = $tenantContext;
        $this->branchContext = $branchContext;
    }

    /**
     * Create a new product category.
     */
    public function createCategory(array $data): \App\Models\ProductCategory
    {
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot create category without active TenantContext.');
        }

        $category = \App\Models\ProductCategory::create($data);

        $this->auditLogger->log(
            action: 'product_category_created',
            auditable: $category,
            afterValues: $category->toArray()
        );

        return $category;
    }

    /**
     * Create a new product master record.
     */
    public function createProduct(array $data): \App\Models\Product
    {
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot create product without active TenantContext.');
        }

        $validator = Validator::make($data, [
            'product_category_id' => ['required', 'exists:product_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Validate category belongs to same tenant
        $category = \App\Models\ProductCategory::find($data['product_category_id']);
        if (!$category || $category->tenant_id !== $this->tenantContext->getTenant()->id) {
            throw new \RuntimeException('Invalid category assignment: Category belongs to a different tenant or does not exist.');
        }

        $product = \App\Models\Product::create($data);

        $this->auditLogger->log(
            action: 'product_created',
            auditable: $product,
            afterValues: $product->toArray()
        );

        return $product;
    }

    /**
     * Search for products ready for POS display.
     */
    public function search(string $searchTerm = '', ?string $categoryId = null): Collection
    {
        $tenantId = $this->tenantContext->getTenantId();
        if (!$tenantId) {
            throw new \RuntimeException('Cannot search catalog without active TenantContext.');
        }

        $query = Product::with(['category', 'taxCategory'])
            ->active()
            ->where('tenant_id', $tenantId);

        // Filter by category if provided
        if ($categoryId) {
            $query->where('product_category_id', $categoryId);
        }

        // Search by name, SKU, or Barcode (case-insensitive)
        if (!empty(trim($searchTerm))) {
            $term = strtolower(trim($searchTerm));
            $query->where(function (Builder $q) use ($term) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                  ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$term}%"])
                  ->orWhereRaw('LOWER(barcode) LIKE ?', ["%{$term}%"]);
            });
        }

        // Branch Stock Visibility
        if ($this->branchContext->hasBranch()) {
            $branchId = $this->branchContext->getBranchId();
            
            // We do NOT filter out products without branch inventory anymore.
            // Instead, we Eager Load the inventory for the specific branch.
            $query->with(['branchInventories' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            }]);
        }

        return $query->get()->map(function (Product $product) {
            return $this->shapeForPOS($product);
        });
    }

    /**
     * Shape the product payload for POS readiness.
     */
    protected function shapeForPOS(Product $product): array
    {
        $branchInventory = $product->branchInventories->first();
        $tax = $product->taxCategory;

        $payload = [
            'product_id' => $product->id,
            'display_name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit_of_measure' => $product->unit_of_measure,
            'category_name' => $product->category?->name,
            'selling_price' => (float) $product->selling_price,
            'tax_category_id' => $product->tax_category_id,
            'tax_type' => $tax ? $tax->tax_type : null,
            'tax_rate' => $tax ? (float) $tax->rate : 0.0,
            'is_inventory_tracked' => (bool) $product->is_inventory_tracked,
            'is_discountable' => (bool) $product->is_discountable,
            'status' => $product->status,
        ];

        // Inventory Stock Data
        if ($product->is_inventory_tracked) {
            $payload['current_stock'] = $branchInventory ? (float) $branchInventory->current_stock : null;
            $payload['stock_available'] = $branchInventory ? ($branchInventory->current_stock > 0) : false;
        } else {
            $payload['current_stock'] = null;
            $payload['stock_tracking'] = 'not_tracked';
            $payload['stock_available'] = true; // Non-tracked is always "available" for sale
        }

        return $payload;
    }
}
