<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\InventoryVarianceLog;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecipeDeductionService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected UnitConversionResolver $unitConversionResolver,
        protected InventoryMovementRecorder $movementRecorder,
        protected NegativeStockExceptionService $negativeStockExceptionService,
        protected AuditLogger $auditLogger
    ) {}

    public function deductSaleItem(Sale $sale, SaleItem $saleItem, Product $parentProduct): RecipeDeductionResult
    {
        $this->assertTenant($sale, $saleItem, $parentProduct);

        return DB::transaction(function () use ($sale, $saleItem, $parentProduct) {
            $batchUuid = $this->batchUuid($sale, $saleItem);
            $existing = $this->existingBatchMovements($sale, $saleItem, $batchUuid);

            if ($existing->isNotEmpty()) {
                return $this->resultFromExisting($sale, $saleItem, $parentProduct, $batchUuid, $existing);
            }

            $legacy = $this->existingLegacyRecipeMovements($sale, $saleItem);
            if ($legacy->isNotEmpty()) {
                return $this->resultFromExisting($sale, $saleItem, $parentProduct, $batchUuid, $legacy, true);
            }

            $plan = $this->plan($sale, $saleItem, $parentProduct, $batchUuid);
            $existingAfterLocks = $this->existingBatchMovements($sale, $saleItem, $batchUuid);
            if ($existingAfterLocks->isNotEmpty()) {
                return $this->resultFromExisting($sale, $saleItem, $parentProduct, $batchUuid, $existingAfterLocks);
            }

            $this->validatePlan($sale, $plan);

            $results = [];

            foreach ($plan as $line) {
                /** @var BranchInventory $inventory */
                $inventory = $line['inventory'];
                $quantityBefore = (float) $inventory->current_stock;
                $quantityAfter = $quantityBefore - $line['resolved_quantity'];
                $snapshot = $this->snapshot($sale, $saleItem, $parentProduct, $line, $quantityBefore, $quantityAfter, count($plan));

                $inventory->update(['current_stock' => $quantityAfter]);

                $movement = $this->movementRecorder->record($inventory, [
                    'movement_type' => 'sale_deduction',
                    'quantity_change' => -$line['resolved_quantity'],
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'sale_item_id' => $saleItem->id,
                    'parent_product_id' => $parentProduct->id,
                    'recipe_line_uuid' => $line['recipe']->recipe_line_uuid,
                    'recipe_batch_uuid' => $batchUuid,
                    'source_type' => 'sale',
                    'source_id' => $sale->id,
                    'source_reference' => $sale->sale_number,
                    'source_effect_key' => $line['source_effect_key'],
                    'base_unit_id' => $line['conversion']['to_unit'],
                    'source_unit_id' => $line['conversion']['from_unit'],
                    'source_quantity' => $line['source_quantity'],
                    'conversion_snapshot' => $line['conversion']['snapshot'],
                    'user_id' => $sale->user_id,
                    'remarks' => "Recipe component for {$parentProduct->name}",
                    'metadata' => [
                        'recipe_deduction_snapshot' => $snapshot,
                    ],
                ]);

                $exceptionId = null;

                if ($quantityAfter < 0) {
                    $exception = $this->negativeStockExceptionService->createForSaleDeduction(
                        sale: $sale,
                        inventory: $inventory,
                        movement: $movement,
                        quantityRequired: $line['resolved_quantity'],
                        quantityBefore: $quantityBefore,
                        quantityAfter: $quantityAfter,
                        policy: $this->policy($sale),
                        parentProductId: $parentProduct->id,
                        saleItemId: $saleItem->id,
                        conversionResolution: $line['conversion']
                    );

                    $exceptionId = $exception->variance->id;
                }

                $results[] = $this->lineResult($line, $movement, $exceptionId, $quantityBefore, $quantityAfter);
            }

            $this->auditLogger->log(
                action: 'inventory_recipe_deduction_recorded',
                auditable: $sale,
                metadata: [
                    'sale_id' => $sale->id,
                    'sale_item_id' => $saleItem->id,
                    'parent_product_id' => $parentProduct->id,
                    'recipe_batch_uuid' => $batchUuid,
                    'movement_ids' => array_column($results, 'movement_id'),
                ]
            );

            return new RecipeDeductionResult(
                recipeBatchUuid: $batchUuid,
                saleId: $sale->id,
                saleItemId: $saleItem->id,
                parentProductId: $parentProduct->id,
                parentQuantity: (float) $saleItem->quantity,
                lines: $results
            );
        });
    }

    protected function plan(Sale $sale, SaleItem $saleItem, Product $parentProduct, string $batchUuid): array
    {
        $recipes = ProductRecipe::query()
            ->active()
            ->with('ingredient')
            ->where('tenant_id', $sale->tenant_id)
            ->where('product_id', $parentProduct->id)
            ->orderBy('ingredient_id')
            ->orderBy('recipe_line_uuid')
            ->get();

        if ($recipes->isEmpty()) {
            throw new RuntimeException('Recipe deduction requires at least one active recipe line.');
        }

        if ($recipes->pluck('ingredient_id')->duplicates()->isNotEmpty()) {
            throw new RuntimeException('Duplicate active ingredient recipe lines are not supported.');
        }

        $lines = [];

        foreach ($recipes as $recipe) {
            $ingredient = $recipe->ingredient;

            if (!$ingredient || $ingredient->tenant_id !== $sale->tenant_id || !$ingredient->is_inventory_tracked || $ingredient->status !== 'active') {
                throw new RuntimeException('Recipe ingredient configuration is invalid.');
            }

            if ($ingredient->recipes()->exists()) {
                throw new RuntimeException('Recursive live recipe deduction is not supported.');
            }

            $sourceQuantity = (float) $recipe->quantity * (float) $saleItem->quantity;
            if ($sourceQuantity <= 0) {
                throw new RuntimeException('Recipe deduction quantity must be positive.');
            }

            $conversion = $this->unitConversionResolver->resolve(
                quantity: $sourceQuantity,
                fromUnit: (string) $recipe->unit,
                toUnit: (string) $ingredient->unit_of_measure,
                productId: $ingredient->id,
                strict: true
            );

            $inventory = BranchInventory::query()
                ->where('tenant_id', $sale->tenant_id)
                ->where('branch_id', $sale->branch_id)
                ->where('product_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            if (!$inventory) {
                throw new RuntimeException("Ingredient {$ingredient->name} not found in inventory for branch {$sale->branch_id}.");
            }

            $lines[] = [
                'recipe' => $recipe,
                'ingredient' => $ingredient,
                'inventory' => $inventory,
                'source_quantity' => $sourceQuantity,
                'resolved_quantity' => (float) $conversion['value'],
                'conversion' => $conversion,
                'source_effect_key' => $this->sourceEffectKey($sale, $saleItem, $recipe, $ingredient),
                'recipe_batch_uuid' => $batchUuid,
            ];
        }

        return $lines;
    }

    protected function validatePlan(Sale $sale, array $plan): void
    {
        if ($this->policy($sale) === 'allow_negative_with_warning') {
            return;
        }

        foreach ($plan as $line) {
            $quantityAfter = (float) $line['inventory']->current_stock - $line['resolved_quantity'];
            if ($quantityAfter < 0) {
                throw new RuntimeException("Insufficient stock for recipe ingredient {$line['ingredient']->name}.");
            }
        }
    }

    protected function existingBatchMovements(Sale $sale, SaleItem $saleItem, string $batchUuid)
    {
        return InventoryMovement::query()
            ->where('tenant_id', $sale->tenant_id)
            ->where('branch_id', $sale->branch_id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('sale_item_id', $saleItem->id)
            ->where('recipe_batch_uuid', $batchUuid)
            ->orderBy('recipe_line_uuid')
            ->get();
    }

    protected function existingLegacyRecipeMovements(Sale $sale, SaleItem $saleItem)
    {
        return InventoryMovement::query()
            ->where('tenant_id', $sale->tenant_id)
            ->where('branch_id', $sale->branch_id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->whereNull('recipe_batch_uuid')
            ->where(function ($query) use ($sale, $saleItem) {
                $query->where('sale_item_id', $saleItem->id)
                    ->orWhere('source_effect_key', 'like', "sale:{$sale->id}:sale_item:{$saleItem->id}:%");
            })
            ->get();
    }

    protected function resultFromExisting(Sale $sale, SaleItem $saleItem, Product $parentProduct, string $batchUuid, $movements, bool $legacy = false): RecipeDeductionResult
    {
        if (!$legacy) {
            $this->assertCompleteExistingBatch($saleItem, $parentProduct, $batchUuid, $movements);
        }

        $lines = $movements->map(function (InventoryMovement $movement) {
            $snapshot = $movement->metadata['recipe_deduction_snapshot'] ?? [];
            $variance = InventoryVarianceLog::query()
                ->where('movement_id', $movement->id)
                ->where('variance_category', InventoryVarianceLog::CATEGORY_NEGATIVE_STOCK)
                ->first();

            return [
                'recipe_line_id' => data_get($snapshot, 'recipe_line.id'),
                'recipe_line_uuid' => $movement->recipe_line_uuid,
                'recipe_version' => data_get($snapshot, 'recipe_line.recipe_version'),
                'ingredient_product_id' => $movement->product_id,
                'source_quantity' => (string) $movement->source_quantity,
                'source_unit' => $movement->source_unit_id,
                'resolved_quantity' => number_format(abs((float) $movement->quantity_change), 4, '.', ''),
                'base_unit' => $movement->base_unit_id,
                'conversion_snapshot' => $movement->conversion_snapshot,
                'quantity_before' => (string) $movement->quantity_before,
                'quantity_after' => (string) $movement->quantity_after,
                'movement_id' => $movement->id,
                'source_effect_key' => $movement->source_effect_key,
                'negative_stock_exception_id' => $variance?->id,
            ];
        })->all();

        return new RecipeDeductionResult(
            recipeBatchUuid: $batchUuid,
            saleId: $sale->id,
            saleItemId: $saleItem->id,
            parentProductId: $parentProduct->id,
            parentQuantity: (float) $saleItem->quantity,
            lines: $lines,
            replayed: true
        );
    }

    protected function assertCompleteExistingBatch(SaleItem $saleItem, Product $parentProduct, string $batchUuid, $movements): void
    {
        $expected = (int) data_get($movements->first()->metadata, 'recipe_deduction_snapshot.recipe_batch_expected_line_count', 0);

        if ($expected <= 0 || $movements->count() !== $expected) {
            throw new RuntimeException('Recipe deduction partial replay detected.');
        }

        foreach ($movements as $movement) {
            $snapshot = $movement->metadata['recipe_deduction_snapshot'] ?? [];

            $checks = [
                [$movement->movement_type, 'sale_deduction'],
                [$movement->sale_item_id, $saleItem->id],
                [$movement->parent_product_id, $parentProduct->id],
                [$movement->recipe_batch_uuid, $batchUuid],
                [$movement->recipe_line_uuid, data_get($snapshot, 'recipe_line.recipe_line_uuid')],
                [$movement->product_id, data_get($snapshot, 'ingredient_snapshot.product_id')],
                [$movement->source_effect_key, data_get($snapshot, 'source_effect_key')],
                [number_format(abs((float) $movement->quantity_change), 4, '.', ''), data_get($snapshot, 'deduction.resolved_quantity')],
                [number_format((float) $movement->source_quantity, 4, '.', ''), data_get($snapshot, 'deduction.source_quantity')],
                [$movement->source_unit_id, data_get($snapshot, 'deduction.source_unit')],
                [$movement->base_unit_id, data_get($snapshot, 'deduction.base_unit')],
                [$movement->conversion_snapshot, data_get($snapshot, 'conversion_snapshot')],
            ];

            foreach ($checks as [$actual, $expectedValue]) {
                if ($actual !== $expectedValue) {
                    throw new RuntimeException('Recipe deduction replay drift detected.');
                }
            }
        }
    }

    protected function snapshot(Sale $sale, SaleItem $saleItem, Product $parentProduct, array $line, float $quantityBefore, float $quantityAfter, int $expectedLineCount): array
    {
        /** @var ProductRecipe $recipe */
        $recipe = $line['recipe'];
        /** @var Product $ingredient */
        $ingredient = $line['ingredient'];

        return [
            'schema_version' => 1,
            'recipe_batch_uuid' => $line['recipe_batch_uuid'],
            'recipe_batch_expected_line_count' => $expectedLineCount,
            'configuration_source' => 'parent_recipe',
            'modifier_context' => null,
            'sale_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'sale_item_id' => $saleItem->id,
            'parent_product_id' => $parentProduct->id,
            'parent_product_snapshot' => [
                'name' => $parentProduct->name,
                'sku' => $parentProduct->sku,
                'sold_quantity' => number_format((float) $saleItem->quantity, 4, '.', ''),
            ],
            'recipe_line' => [
                'id' => $recipe->id,
                'recipe_line_uuid' => $recipe->recipe_line_uuid,
                'recipe_schema_version' => $recipe->recipe_schema_version,
                'recipe_version' => $recipe->recipe_version,
                'ingredient_id' => $recipe->ingredient_id,
                'quantity' => number_format((float) $recipe->quantity, 4, '.', ''),
                'unit' => $recipe->unit,
            ],
            'ingredient_snapshot' => [
                'product_id' => $ingredient->id,
                'name' => $ingredient->name,
                'sku' => $ingredient->sku,
                'base_stock_unit' => $ingredient->unit_of_measure,
            ],
            'deduction' => [
                'recipe_quantity_per_parent' => number_format((float) $recipe->quantity, 4, '.', ''),
                'parent_quantity' => number_format((float) $saleItem->quantity, 4, '.', ''),
                'source_quantity' => number_format($line['source_quantity'], 4, '.', ''),
                'source_unit' => $recipe->unit,
                'resolved_quantity' => number_format($line['resolved_quantity'], 4, '.', ''),
                'base_unit' => $ingredient->unit_of_measure,
                'rounding_mode' => $line['conversion']['rounding_mode'] ?? 'HALF_UP',
                'quantity_before' => number_format($quantityBefore, 4, '.', ''),
                'quantity_delta' => number_format(-$line['resolved_quantity'], 4, '.', ''),
                'quantity_after' => number_format($quantityAfter, 4, '.', ''),
            ],
            'conversion_snapshot' => $line['conversion']['snapshot'],
            'source_effect_key' => $line['source_effect_key'],
        ];
    }

    protected function lineResult(array $line, InventoryMovement $movement, ?string $exceptionId, float $quantityBefore, float $quantityAfter): array
    {
        /** @var ProductRecipe $recipe */
        $recipe = $line['recipe'];

        return [
            'recipe_line_id' => $recipe->id,
            'recipe_line_uuid' => $recipe->recipe_line_uuid,
            'recipe_version' => $recipe->recipe_version,
            'ingredient_product_id' => $line['ingredient']->id,
            'source_quantity' => number_format($line['source_quantity'], 4, '.', ''),
            'source_unit' => $recipe->unit,
            'resolved_quantity' => number_format($line['resolved_quantity'], 4, '.', ''),
            'base_unit' => $line['ingredient']->unit_of_measure,
            'conversion_snapshot' => $line['conversion']['snapshot'],
            'quantity_before' => number_format($quantityBefore, 4, '.', ''),
            'quantity_after' => number_format($quantityAfter, 4, '.', ''),
            'movement_id' => $movement->id,
            'source_effect_key' => $line['source_effect_key'],
            'negative_stock_exception_id' => $exceptionId,
        ];
    }

    protected function sourceEffectKey(Sale $sale, SaleItem $saleItem, ProductRecipe $recipe, Product $ingredient): string
    {
        return implode(':', [
            'sale',
            $sale->id,
            'sale_item',
            $saleItem->id,
            'recipe_line',
            $recipe->recipe_line_uuid,
            'ingredient',
            $ingredient->id,
        ]);
    }

    protected function batchUuid(Sale $sale, SaleItem $saleItem): string
    {
        $hash = md5(implode('|', [$sale->tenant_id, $sale->id, $saleItem->id]));

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hash, 4));
    }

    protected function policy(Sale $sale): string
    {
        $policy = $sale->branch->inventory_deduction_policy ?? 'strict_block';

        return $policy === 'allow_negative_with_warning' ? $policy : 'strict_block';
    }

    protected function assertTenant(Sale $sale, SaleItem $saleItem, Product $parentProduct): void
    {
        $tenantId = $this->tenantContext->getTenantId();

        if (!$tenantId || $sale->tenant_id !== $tenantId || $saleItem->tenant_id !== $tenantId || $parentProduct->tenant_id !== $tenantId) {
            throw new RuntimeException('Recipe deduction tenant scope mismatch.');
        }
    }
}
