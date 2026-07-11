<?php

namespace App\Services;

use App\Models\Product;
use App\Models\BranchInventory;
use App\Models\ExpiryLot;
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
            ->where('is_sellable', true)
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
            $query->with(['expiryLots' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->where('status', 'active')
                    ->where('quantity_remaining', '>', 0);
            }]);
            $query->with(['branchProductPricings' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->where('status', 'active');
            }]);
        }

        return $query->get()->map(function (Product $product) {
            return $this->shapeForPOS($product);
        });
    }

    /**
     * Get specific products by IDs shaped for POS.
     */
    public function getByIds(array $ids): Collection
    {
        $tenantId = $this->tenantContext->getTenantId();
        if (!$tenantId) {
            throw new \RuntimeException('Cannot fetch products without active TenantContext.');
        }

        $query = Product::with(['category', 'taxCategory'])
            ->whereIn('id', $ids)
            ->where('tenant_id', $tenantId);

        if ($this->branchContext->hasBranch()) {
            $branchId = $this->branchContext->getBranchId();
            $query->with(['branchInventories' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            }]);
            $query->with(['expiryLots' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->where('status', 'active')
                    ->where('quantity_remaining', '>', 0);
            }]);
            $query->with(['branchProductPricings' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->where('status', 'active');
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
            'product_category_id' => $product->product_category_id,
            'display_name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit_of_measure' => $product->unit_of_measure,
            'category_name' => $product->category?->name,
            'selling_price' => (float) ($product->branchProductPricings->first()?->selling_price ?? $product->selling_price),
            'tax_category_id' => $product->tax_category_id,
            'tax_type' => $tax ? $tax->tax_type : null,
            'tax_rate' => $tax ? (float) $tax->rate : 0.0,
            'is_inventory_tracked' => (bool) $product->is_inventory_tracked,
            'expiry_tracking_enabled' => (bool) $product->expiry_tracking_enabled,
            'is_discountable' => (bool) $product->is_discountable,
            'status' => $product->status,
        ];

        // Inventory Stock Data
        if ($product->is_inventory_tracked) {
            $currentStock = $branchInventory ? (float) $branchInventory->current_stock : 0.0;
            $reorderLevel = $branchInventory ? (float) $branchInventory->reorder_level : null;
            $expirySummary = $this->summarizeExpiryLots($product);
            $availableToSell = $product->expiry_tracking_enabled
                ? $expirySummary['unexpired_quantity']
                : $currentStock;

            $payload['current_stock'] = $branchInventory ? $currentStock : null;
            $payload['reorder_level'] = $reorderLevel;
            $payload['unexpired_stock'] = $product->expiry_tracking_enabled ? $expirySummary['unexpired_quantity'] : null;
            $payload['expired_stock'] = $product->expiry_tracking_enabled ? $expirySummary['expired_quantity'] : null;
            $payload['next_expiry_date'] = $expirySummary['next_expiry_date'];
            $payload['available_to_sell'] = max($availableToSell, 0);
            $payload['stock_available'] = $payload['available_to_sell'] > 0;
            $payload['stock_state'] = $this->resolveStockState(
                $product,
                $branchInventory,
                $currentStock,
                $availableToSell,
                $reorderLevel,
                $expirySummary
            );
        } else {
            $payload['current_stock'] = null;
            $payload['reorder_level'] = null;
            $payload['unexpired_stock'] = null;
            $payload['expired_stock'] = null;
            $payload['next_expiry_date'] = null;
            $payload['available_to_sell'] = null;
            $payload['stock_tracking'] = 'not_tracked';
            $payload['stock_available'] = true; // Non-tracked is always "available" for sale
            $payload['stock_state'] = 'normal';
        }

        return $payload;
    }

    protected function summarizeExpiryLots(Product $product): array
    {
        if (!$product->expiry_tracking_enabled) {
            return [
                'unexpired_quantity' => 0.0,
                'expired_quantity' => 0.0,
                'next_expiry_date' => null,
                'near_expiry' => false,
            ];
        }

        $today = now()->toDateString();
        $nearExpiryCutoff = now()->addDays(7)->toDateString();
        $lots = $product->relationLoaded('expiryLots')
            ? $product->expiryLots
            : ExpiryLot::where('product_id', $product->id)
                ->when($this->branchContext->hasBranch(), fn ($query) => $query->where('branch_id', $this->branchContext->getBranchId()))
                ->where('status', 'active')
                ->where('quantity_remaining', '>', 0)
                ->get();

        $unexpiredLots = $lots->filter(fn (ExpiryLot $lot) => $lot->expiry_date->toDateString() > $today);
        $nextExpiryDate = $unexpiredLots
            ->sortBy(fn (ExpiryLot $lot) => $lot->expiry_date->toDateString())
            ->first()
            ?->expiry_date
            ?->toDateString();

        return [
            'unexpired_quantity' => (float) $unexpiredLots->sum(fn (ExpiryLot $lot) => (float) $lot->quantity_remaining),
            'expired_quantity' => (float) $lots
                ->filter(fn (ExpiryLot $lot) => $lot->expiry_date->toDateString() <= $today)
                ->sum(fn (ExpiryLot $lot) => (float) $lot->quantity_remaining),
            'next_expiry_date' => $nextExpiryDate,
            'near_expiry' => $nextExpiryDate !== null && $nextExpiryDate <= $nearExpiryCutoff,
        ];
    }

    protected function resolveStockState(
        Product $product,
        ?BranchInventory $branchInventory,
        float $currentStock,
        float $availableToSell,
        ?float $reorderLevel,
        array $expirySummary
    ): string {
        if (!$branchInventory || $currentStock <= 0 || $availableToSell <= 0) {
            return $product->expiry_tracking_enabled && $currentStock > 0
                ? 'expired'
                : 'out_of_stock';
        }

        if ($product->expiry_tracking_enabled && $expirySummary['near_expiry']) {
            return 'near_expiry';
        }

        if ($reorderLevel !== null && $reorderLevel > 0 && $currentStock <= $reorderLevel) {
            $criticalThreshold = max(1, $reorderLevel / 2);

            return $currentStock <= $criticalThreshold ? 'critical_stock' : 'low_stock';
        }

        return 'normal';
    }
}
