<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Services\Inventory\InventoryMovementRecorder;
use App\Services\Inventory\NegativeStockExceptionService;
use App\Services\Inventory\RecipeDeductionService;
use App\Services\Inventory\UnitConversionResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    protected AuditLogger $auditLogger;
    protected TenantContext $tenantContext;
    protected BranchContext $branchContext;
    protected UnitConversionResolver $unitConversionResolver;
    protected InventoryMovementRecorder $movementRecorder;
    protected NegativeStockExceptionService $negativeStockExceptionService;
    protected RecipeDeductionService $recipeDeductionService;

    public function __construct(
        AuditLogger $auditLogger,
        TenantContext $tenantContext,
        BranchContext $branchContext,
        UnitConversionResolver $unitConversionResolver,
        ?InventoryMovementRecorder $movementRecorder = null,
        ?NegativeStockExceptionService $negativeStockExceptionService = null,
        ?RecipeDeductionService $recipeDeductionService = null
    ) {
        $this->auditLogger = $auditLogger;
        $this->tenantContext = $tenantContext;
        $this->branchContext = $branchContext;
        $this->unitConversionResolver = $unitConversionResolver;
        $this->movementRecorder = $movementRecorder ?? App::make(InventoryMovementRecorder::class);
        $this->negativeStockExceptionService = $negativeStockExceptionService ?? App::make(NegativeStockExceptionService::class);
        $this->recipeDeductionService = $recipeDeductionService ?? App::make(RecipeDeductionService::class);
    }

    /**
     * Perform a manual stock adjustment.
     */
    public function adjustStock(BranchInventory $inventory, float $quantityChange, string $reasonCode, ?string $remarks = null): InventoryMovement
    {
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot adjust stock without active TenantContext.');
        }

        // 9. Manual adjustment requires reason code
        if (empty(trim($reasonCode))) {
            throw new \RuntimeException('Manual adjustment requires a valid reason code.');
        }

        // Validate inventory belongs to active tenant
        if ($inventory->tenant_id !== $this->tenantContext->getTenant()->id) {
            throw new \RuntimeException('Cannot adjust stock for inventory belonging to a different tenant.');
        }

        // Validate inventory branch belongs to active branch context if it exists
        if ($this->branchContext->hasBranch() && $inventory->branch_id !== $this->branchContext->getBranchId()) {
            throw new \RuntimeException('Cannot adjust stock for inventory outside the active branch context.');
        }

        // Require permission manage_branch_inventory if authenticated user exists
        $user = auth()->user();
        if ($user && method_exists($user, 'hasPermission') && !$user->hasPermission('manage_branch_inventory')) {
            throw new \RuntimeException('User does not have permission to manage branch inventory.');
        }

        return DB::transaction(function () use ($inventory, $quantityChange, $reasonCode, $remarks, $user) {
            $quantityBefore = $inventory->current_stock;
            $quantityAfter = $quantityBefore + $quantityChange;

            // Block negative resulting stock by default for MVP
            if ($quantityAfter < 0) {
                throw new \RuntimeException('Stock adjustment would result in negative inventory, which is blocked.');
            }

            // Update branch_inventories.current_stock
            $inventory->update(['current_stock' => $quantityAfter]);

            // Create immutable movement log
            return $this->recordMovement($inventory, [
                'movement_type' => 'manual_adjustment',
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason_code' => $reasonCode,
                'remarks' => $remarks,
                'user_id' => $user?->id,
            ]);
        });
    }

    /**
     * Initialize inventory for a product at a specific branch.
     */
    public function initializeInventory(array $data): BranchInventory
    {
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot initialize inventory without active TenantContext.');
        }

        $validator = Validator::make($data, [
            'branch_id' => ['required', 'exists:branches,id'],
            'product_id' => ['required', 'exists:products,id'],
            'current_stock' => ['sometimes', 'numeric', 'min:0'],
            'reorder_level' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $branch = Branch::active()->find($data['branch_id']);
        $product = Product::active()->find($data['product_id']);

        // 5. Tenant/branch/product validation
        if (!$branch || $branch->tenant_id !== $this->tenantContext->getTenant()->id) {
            throw new \RuntimeException('Invalid branch assignment: Branch belongs to a different tenant, does not exist, or is inactive.');
        }

        if (!$product || $product->tenant_id !== $this->tenantContext->getTenant()->id) {
            throw new \RuntimeException('Invalid product assignment: Product belongs to a different tenant, does not exist, or is inactive.');
        }

        // 6. Branch inventory cannot be created for non-inventory-tracked product.
        if (!$product->is_inventory_tracked) {
            throw new \RuntimeException('Cannot initialize inventory for a product that is not inventory-tracked.');
        }

        $inventory = BranchInventory::updateOrCreate(
            ['branch_id' => $data['branch_id'], 'product_id' => $data['product_id']],
            $data
        );

        // Record initial movement if it's new or if current_stock > 0
        if ($inventory->wasRecentlyCreated && $inventory->current_stock > 0) {
            $this->recordMovement($inventory, [
                'movement_type' => 'inventory_opening_balance',
                'quantity_change' => $inventory->current_stock,
                'quantity_before' => 0,
                'quantity_after' => $inventory->current_stock,
                'source_type' => 'inventory_opening_balance',
                'source_id' => $inventory->id,
                'source_reference' => "opening-balance:{$inventory->id}",
                'source_effect_key' => "opening_balance:{$inventory->id}:product:{$inventory->product_id}",
                'reason_code' => 'opening_balance',
                'remarks' => 'Initial inventory initialization'
            ]);
        }

        $this->auditLogger->log(
            action: 'branch_inventory_initialized',
            auditable: $inventory,
            afterValues: $inventory->toArray()
        );

        return $inventory;
    }

    /**
     * Record an inventory movement.
     * Note: This method ONLY logs the movement. Stock updates should be handled by specialized services.
     */
    public function recordMovement(BranchInventory $inventory, array $data): InventoryMovement
    {
        return $this->movementRecorder->record($inventory, $data);
    }
    /**
     * Perform a stock-in / delivery entry.
     */
    public function stockIn(BranchInventory $inventory, float $quantity, ?string $supplierReference = null, ?string $invoiceReference = null, ?string $remarks = null): InventoryMovement
    {
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot record stock-in without active TenantContext.');
        }

        // Validate quantity is positive
        if ($quantity <= 0) {
            throw new \RuntimeException('Stock-in quantity must be a positive number.');
        }

        // Validate inventory belongs to active tenant
        if ($inventory->tenant_id !== $this->tenantContext->getTenant()->id) {
            throw new \RuntimeException('Cannot record stock-in for inventory belonging to a different tenant.');
        }

        // Validate inventory branch belongs to active branch context if it exists
        if ($this->branchContext->hasBranch() && $inventory->branch_id !== $this->branchContext->getBranchId()) {
            throw new \RuntimeException('Cannot record stock-in for inventory outside the active branch context.');
        }

        // Require permission manage_branch_inventory if authenticated user exists
        $user = auth()->user();
        if ($user && method_exists($user, 'hasPermission') && !$user->hasPermission('manage_branch_inventory')) {
            throw new \RuntimeException('User does not have permission to manage branch inventory.');
        }

        return DB::transaction(function () use ($inventory, $quantity, $supplierReference, $invoiceReference, $remarks, $user) {
            $quantityBefore = $inventory->current_stock;
            $quantityAfter = $quantityBefore + $quantity;

            // Update branch_inventories.current_stock
            $inventory->update(['current_stock' => $quantityAfter]);

            // Create immutable movement log
            return $this->recordMovement($inventory, [
                'movement_type' => 'stock_in',
                'quantity_change' => $quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'source_type' => 'stock_in',
                'source_id' => $supplierReference,
                'reference_number' => $invoiceReference,
                'source_reference' => $invoiceReference,
                'source_effect_key' => $supplierReference
                    ? "stock_in:{$supplierReference}:product:{$inventory->product_id}"
                    : null,
                'reason_code' => 'delivery_received',
                'remarks' => $remarks,
                'user_id' => $user?->id,
            ]);
        });
    }

    /**
     * Deduct inventory based on items in a paid sale.
     * This handles idempotency by checking if a movement for this sale already exists.
     */
    public function deductFromSale(\App\Models\Sale $sale): void
    {
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot deduct inventory without active TenantContext.');
        }

        DB::transaction(function () use ($sale) {
            foreach ($sale->items as $item) {
                // Load product with recipes to check for ingredients
                $product = $item->product()->with('recipes')->first();
                
                if ($product && $product->recipes->count() > 0) {
                    // Scenario: Recipe-based deduction (Ingredients)
                    $this->recipeDeductionService->deductSaleItem($sale, $item, $product);
                } else {
                    // Scenario: Standard product deduction
                    if (!$item->is_inventory_tracked) {
                        continue;
                    }

                    $inventory = BranchInventory::where('branch_id', $sale->branch_id)
                        ->where('product_id', $item->product_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$inventory) {
                        throw new \RuntimeException("Inventory record not found for product {$item->product_name} at branch {$sale->branch_id}.");
                    }

                    $this->performDeduction($inventory, (float) $item->quantity, $sale, null, null, $item->id);
                }
            }
        });

        $this->auditLogger->log(
            action: 'inventory_deducted_for_sale',
            auditable: $sale,
            metadata: [
                'sale_id' => $sale->id,
                'item_count' => $sale->items->count()
            ]
        );
    }

    /**
     * Deduct a single component/ingredient for a recipe.
     */
    protected function deductComponent(\App\Models\ProductRecipe $recipe, \App\Models\Sale $sale, float $parentQuantity, ?string $parentProductId = null, ?string $saleItemId = null): void
    {
        $deductQty = (float) $recipe->quantity * $parentQuantity;
        $recipeUnit = $recipe->unit;
        $ingredientUnit = $recipe->ingredient->unit_of_measure;
        $conversionResolution = null;

        // Apply Unit Conversion if units differ
        if ($recipeUnit !== $ingredientUnit) {
            $conversionResolution = $this->unitConversionResolver->resolve(
                quantity: $deductQty,
                fromUnit: $recipeUnit,
                toUnit: $ingredientUnit,
                productId: $recipe->ingredient_id,
                strict: true
            );
            $deductQty = (float) $conversionResolution['value'];
        } else {
            $conversionResolution = $this->unitConversionResolver->resolve(
                quantity: $deductQty,
                fromUnit: $recipeUnit,
                toUnit: $ingredientUnit,
                productId: $recipe->ingredient_id,
                strict: true
            );
        }

        $inventory = BranchInventory::where('branch_id', $sale->branch_id)
            ->where('product_id', $recipe->ingredient_id)
            ->lockForUpdate()
            ->first();

        if (!$inventory) {
            // In a recipe system, if an ingredient isn't stocked at the branch, 
            // it's usually a configuration error. We'll throw to ensure integrity.
            throw new \RuntimeException("Ingredient {$recipe->ingredient->name} not found in inventory for branch {$sale->branch_id}.");
        }

        $this->performDeduction($inventory, $deductQty, $sale, "Recipe component for {$recipe->product->name}", $parentProductId, $saleItemId, $conversionResolution);
    }

    /**
     * Convert quantity from recipe unit to ingredient base unit.
     */
    protected function convertUnit(float $quantity, string $fromUnit, string $toUnit, ?string $productId = null): float
    {
        return (float) $this->unitConversionResolver->convert(
            quantity: $quantity,
            fromUnit: $fromUnit,
            toUnit: $toUnit,
            productId: $productId,
            strict: true
        )['value'];
    }

    /**
     * Internal helper to perform the actual stock decrement and movement logging.
     */
    protected function performDeduction(BranchInventory $inventory, float $quantityChange, \App\Models\Sale $sale, ?string $extraRemarks = null, ?string $parentProductId = null, ?string $saleItemId = null, ?array $conversionResolution = null): void
    {
        $quantityBefore = (float) $inventory->current_stock;
        $quantityAfter = $quantityBefore - $quantityChange;
        $policy = $sale->branch->inventory_deduction_policy ?? 'strict_block';
        if ($policy !== 'allow_negative_with_warning') {
            $policy = 'strict_block';
        }

        $sourceEffectKey = $parentProductId
            ? "sale:{$sale->id}:sale_item:{$saleItemId}:ingredient:{$inventory->product_id}"
            : "sale:{$sale->id}:sale_item:{$saleItemId}:product:{$inventory->product_id}";

        $existing = InventoryMovement::query()
            ->where('tenant_id', $sale->tenant_id)
            ->where('branch_id', $sale->branch_id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('source_effect_key', $sourceEffectKey)
            ->first();

        if ($existing) {
            return;
        }

        if ($quantityAfter < 0 && $policy !== 'allow_negative_with_warning') {
                throw new \RuntimeException("Insufficient stock for product {$inventory->product->name}. Available: {$quantityBefore}, Required: {$quantityChange}.");
        }

        $inventory->update(['current_stock' => $quantityAfter]);

        $remarks = "Deduction for Sale #{$sale->sale_number}";
        if ($extraRemarks) {
            $remarks .= " ({$extraRemarks})";
        }

        $movement = $this->recordMovement($inventory, [
            'movement_type' => 'sale_deduction',
            'quantity_change' => -$quantityChange,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'source_reference' => $sale->sale_number,
            'source_effect_key' => $sourceEffectKey,
            'base_unit_id' => $conversionResolution['to_unit'] ?? $inventory->product->unit_of_measure ?? null,
            'source_unit_id' => $conversionResolution['from_unit'] ?? $inventory->product->unit_of_measure ?? null,
            'source_quantity' => $conversionResolution['source_quantity'] ?? $quantityChange,
            'conversion_snapshot' => $conversionResolution['snapshot'] ?? null,
            'user_id' => $sale->user_id,
            'remarks' => $remarks,
        ]);

        if ($quantityAfter < 0) {
            $this->negativeStockExceptionService->createForSaleDeduction(
                sale: $sale,
                inventory: $inventory,
                movement: $movement,
                quantityRequired: $quantityChange,
                quantityBefore: $quantityBefore,
                quantityAfter: $quantityAfter,
                policy: $policy,
                parentProductId: $parentProductId,
                saleItemId: $saleItemId,
                conversionResolution: $conversionResolution
            );
        }
    }

    /**
     * Get low-stock items for a specific branch.
     * This is a read-only query that respects tenant/branch isolation.
     */
    public function getLowStockItemsForBranch(Branch $branch): \Illuminate\Support\Collection
    {
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot query low stock without active TenantContext.');
        }

        // Validate branch belongs to tenant
        if ($branch->tenant_id !== $this->tenantContext->getTenant()->id) {
            throw new \RuntimeException('Cannot query low stock for a branch belonging to a different tenant.');
        }

        return BranchInventory::where('branch_id', $branch->id)
            ->lowStock()
            ->with('product')
            ->get()
            ->map(function ($inventory) {
                return [
                    'branch_inventory_id' => $inventory->id,
                    'tenant_id' => $inventory->tenant_id,
                    'branch_id' => $inventory->branch_id,
                    'product_id' => $inventory->product_id,
                    'product_name' => $inventory->product->name,
                    'sku' => $inventory->product->sku,
                    'current_stock' => (float) $inventory->current_stock,
                    'reorder_level' => (float) $inventory->reorder_level,
                    'status' => $inventory->isLowStock() ? 'low_stock' : 'healthy',
                ];
            });
    }

    /**
     * Get inventory movements for a specific branch.
     * This is a read-only audit query.
     */
    public function getMovementsForBranch(Branch $branch, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot query movements without active TenantContext.');
        }

        // Validate branch belongs to tenant
        if ($branch->tenant_id !== $this->tenantContext->getTenant()->id) {
            throw new \RuntimeException('Cannot query movements for a branch belonging to a different tenant.');
        }

        $query = InventoryMovement::where('branch_id', $branch->id)
            ->with(['product:id,name,sku', 'inventory:id,current_stock'])
            ->latest();

        if (isset($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        return $query->paginate($filters['per_page'] ?? 50);
    }
}
