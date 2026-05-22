<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryService
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
                'movement_type' => 'stock_in',
                'quantity_change' => $inventory->current_stock,
                'quantity_before' => 0,
                'quantity_after' => $inventory->current_stock,
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
        if (!$this->tenantContext->getTenant()) {
            throw new \RuntimeException('Cannot record inventory movement without active TenantContext.');
        }

        // 14. Movement cannot be recorded for inventory from another tenant
        if ($inventory->tenant_id !== $this->tenantContext->getTenant()->id) {
            throw new \RuntimeException('Cannot record movement for inventory belonging to a different tenant.');
        }

        $validator = Validator::make($data, [
            // 5. Movement type must be controlled/valid (Approved Vocabulary)
            'movement_type' => ['required', 'string', Rule::in([
                'stock_in', 
                'manual_adjustment', 
                'sale_deduction', 
                'void_reversal', 
                'refund_return', 
                'stock_correction'
            ])],
            'quantity_change' => ['required', 'numeric'],
            'quantity_before' => ['required', 'numeric'],
            'quantity_after' => ['required', 'numeric'],
            'source_type' => ['sometimes', 'nullable', 'string'],
            'source_id' => ['sometimes', 'nullable', 'string'],
            'original_movement_id' => ['sometimes', 'nullable', 'exists:inventory_movements,id'],
            'user_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'reason_code' => ['sometimes', 'nullable', 'string'],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // 6. quantity_after equals quantity_before + quantity_change
        // Normalize comparison using number_format to 4 decimals
        $calculatedAfter = number_format($data['quantity_before'] + $data['quantity_change'], 4, '.', '');
        $providedAfter = number_format($data['quantity_after'], 4, '.', '');
        
        if ($providedAfter !== $calculatedAfter) {
            throw new \RuntimeException("Inventory consistency error: quantity_after ({$providedAfter}) must equal quantity_before ({$data['quantity_before']}) + quantity_change ({$data['quantity_change']}).");
        }

        // 2, 3, 4. Auto-capture tenant_id, branch_id, product_id from BranchInventory
        $movement = InventoryMovement::create(array_merge($data, [
            'tenant_id' => $inventory->tenant_id,
            'branch_id' => $inventory->branch_id,
            'product_id' => $inventory->product_id,
            'branch_inventory_id' => $inventory->id,
        ]));

        return $movement;
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

        // 1. Idempotency Guard: Check if movements for this sale already exist
        $exists = InventoryMovement::where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->exists();

        if ($exists) {
            return;
        }

        DB::transaction(function () use ($sale) {
            foreach ($sale->items as $item) {
                // Load product with recipes to check for ingredients
                $product = $item->product()->with('recipes')->first();
                
                if ($product && $product->recipes->count() > 0) {
                    // Scenario: Recipe-based deduction (Ingredients)
                    foreach ($product->recipes as $recipe) {
                        $this->deductComponent($recipe, $sale, $item->quantity, $product->id);
                    }
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

                    $this->performDeduction($inventory, (float) $item->quantity, $sale);
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
    protected function deductComponent(\App\Models\ProductRecipe $recipe, \App\Models\Sale $sale, float $parentQuantity, ?string $parentProductId = null): void
    {
        $deductQty = (float) $recipe->quantity * $parentQuantity;
        $recipeUnit = $recipe->unit;
        $ingredientUnit = $recipe->ingredient->unit_of_measure;

        // Apply Unit Conversion if units differ
        if ($recipeUnit !== $ingredientUnit) {
            $deductQty = $this->convertUnit($deductQty, $recipeUnit, $ingredientUnit, $recipe->ingredient_id);
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

        $this->performDeduction($inventory, $deductQty, $sale, "Recipe component for {$recipe->product->name}", $parentProductId);
    }

    /**
     * Convert quantity from recipe unit to ingredient base unit.
     */
    protected function convertUnit(float $quantity, string $fromUnit, string $toUnit, ?string $productId = null): float
    {
        if ($fromUnit === $toUnit) {
            return $quantity;
        }

        $tenant = $this->tenantContext->getTenant();
        if ($tenant) {
            // 1. Check Product-Specific Active Conversion
            if ($productId) {
                $conversion = \App\Models\UnitConversion::where('tenant_id', $tenant->id)
                    ->where('product_id', $productId)
                    ->where('from_unit', $fromUnit)
                    ->where('to_unit', $toUnit)
                    ->where('is_active', true)
                    ->first();
                if ($conversion) {
                    return $quantity * (float) $conversion->conversion_factor;
                }
            }

            // 2. Check Global Tenant Active Conversion
            $conversion = \App\Models\UnitConversion::where('tenant_id', $tenant->id)
                ->whereNull('product_id')
                ->where('from_unit', $fromUnit)
                ->where('to_unit', $toUnit)
                ->where('is_active', true)
                ->first();
            if ($conversion) {
                return $quantity * (float) $conversion->conversion_factor;
            }
        }

        // 3. Fallback to standard metric conversions
        $factors = [
            'kg' => 1,
            'gram' => 0.001,
            'liter' => 1,
            'ml' => 0.001,
            'piece' => 1,
        ];

        if (isset($factors[$fromUnit]) && isset($factors[$toUnit])) {
            $baseQuantity = $quantity * $factors[$fromUnit];
            return $baseQuantity / $factors[$toUnit];
        }

        // If no conversion is possible, throw an error
        throw new \RuntimeException("No active unit conversion rule found from {$fromUnit} to {$toUnit}" . ($productId ? " for product ID {$productId}" : "") . ".");
    }

    /**
     * Internal helper to perform the actual stock decrement and movement logging.
     */
    protected function performDeduction(BranchInventory $inventory, float $quantityChange, \App\Models\Sale $sale, ?string $extraRemarks = null, ?string $parentProductId = null): void
    {
        $quantityBefore = (float) $inventory->current_stock;
        $quantityAfter = $quantityBefore - $quantityChange;
        $policy = $sale->branch->inventory_deduction_policy ?? 'strict_block';
        if ($policy !== 'allow_negative_with_warning') {
            $policy = 'strict_block';
        }

        if ($quantityAfter < 0) {
            if ($policy === 'allow_negative_with_warning') {
                $shortage = abs($quantityAfter);
                
                \App\Models\InventoryVarianceLog::create([
                    'tenant_id' => $sale->tenant_id,
                    'branch_id' => $sale->branch_id,
                    'sale_id' => $sale->id,
                    'product_id' => $parentProductId,
                    'ingredient_id' => $inventory->product_id,
                    'required_quantity' => $quantityChange,
                    'available_quantity_before' => $quantityBefore,
                    'shortage_quantity' => $shortage,
                    'resulting_quantity' => $quantityAfter,
                    'unit' => $inventory->product->unit_of_measure ?? 'piece',
                    'policy' => $policy,
                    'reason' => 'POS Checkout stock shortage deduction.',
                    'metadata' => [
                        'sale_number' => $sale->sale_number,
                        'recipe_parent_id' => $parentProductId,
                    ],
                    'created_by' => $sale->user_id,
                ]);

                if ($this->auditLogger) {
                    $this->auditLogger->log(
                        action: 'inventory_negative_deduction_warning',
                        auditable: $sale,
                        metadata: [
                            'sale_id' => $sale->id,
                            'product_id' => $inventory->product_id,
                            'shortage_quantity' => $shortage,
                            'available_quantity_before' => $quantityBefore,
                            'required_quantity' => $quantityChange,
                        ]
                    );
                }
            } else {
                throw new \RuntimeException("Insufficient stock for product {$inventory->product->name}. Available: {$quantityBefore}, Required: {$quantityChange}.");
            }
        }

        $inventory->update(['current_stock' => $quantityAfter]);

        $remarks = "Deduction for Sale #{$sale->sale_number}";
        if ($extraRemarks) {
            $remarks .= " ({$extraRemarks})";
        }

        $this->recordMovement($inventory, [
            'movement_type' => 'sale_deduction',
            'quantity_change' => -$quantityChange,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'user_id' => $sale->user_id,
            'remarks' => $remarks,
        ]);
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
