<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductCompositionReportService
{
    public function __construct(
        protected UnitConversionResolver $unitConversionResolver
    ) {}

    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $rows = $this->buildRows($filters);

        $page = LengthAwarePaginator::resolveCurrentPage();
        $total = $rows->count();
        $items = $rows->forPage($page, $perPage)->values();

        $request = app('request');

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $request->query(),
            ]
        );
    }

    public function exportRows(array $filters): Collection
    {
        return $this->buildRows($filters);
    }

    public function buildRows(array $filters): Collection
    {
        $expansionMode = $filters['expansion_mode'] ?? 'direct_only';
        $maxDepth = (int) ($filters['max_depth'] ?? 5);

        $parents = Product::query()
            ->active()
            ->where('is_sellable', true)
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                        ->orWhere('sku', 'like', '%' . $search . '%');
                });
            })
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query->where('product_category_id', $categoryId))
            ->when($filters['product_type'] ?? null, fn ($query, $productType) => $query->where('product_type', $productType))
            ->with([
                'recipes.ingredient' => function ($query) {
                    $query->select('id', 'name', 'sku', 'unit_of_measure', 'product_type', 'cost_price', 'status');
                },
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);

        $recipeMap = ProductRecipe::query()
            ->with([
                'ingredient' => function ($query) {
                    $query->select('id', 'name', 'sku', 'unit_of_measure', 'product_type', 'cost_price', 'status');
                },
            ])
            ->get()
            ->groupBy('product_id');

        $ingredientIds = $recipeMap
            ->flatMap(fn (Collection $recipes) => $recipes->pluck('ingredient_id'))
            ->filter()
            ->unique()
            ->values();

        $branchInventoryMap = collect();
        if (!empty($filters['branch_id']) && $ingredientIds->isNotEmpty()) {
            $branchInventoryMap = BranchInventory::query()
                ->active()
                ->where('branch_id', $filters['branch_id'])
                ->whereIn('product_id', $ingredientIds)
                ->get(['product_id', 'current_stock', 'reorder_level', 'average_cost'])
                ->keyBy('product_id');
        }

        $rows = collect();

        foreach ($parents as $parent) {
            foreach ($parent->recipes as $recipe) {
                $this->appendRecipeRows(
                    rows: $rows,
                    parent: $parent,
                    recipe: $recipe,
                    recipeMap: $recipeMap,
                    branchInventoryMap: $branchInventoryMap,
                    filters: $filters,
                    expansionMode: $expansionMode,
                    maxDepth: $maxDepth,
                    depth: 0,
                    multiplier: 1.0,
                    pathNames: [$parent->name],
                    visitedProductIds: [$parent->id]
                );
            }
        }

        $bottlenecks = $rows
            ->groupBy('parent_product_id')
            ->map(function (Collection $group) {
                $coverages = $group
                    ->pluck('coverage_ingredient_parent_units')
                    ->filter(fn ($value) => $value !== null)
                    ->values();

                return $coverages->isEmpty() ? null : $coverages->min();
            });

        $rows = $rows
            ->map(function (array $row) use ($bottlenecks): array {
                $row['coverage_parent_bottleneck_units'] = $bottlenecks->get($row['parent_product_id']);

                return $row;
            })
            ->sortBy([
                ['parent_product_name', 'asc'],
                ['depth', 'asc'],
                ['path_signature', 'asc'],
                ['ingredient_name', 'asc'],
            ])
            ->values();

        return $rows;
    }

    private function appendRecipeRows(
        Collection $rows,
        Product $parent,
        ProductRecipe $recipe,
        Collection $recipeMap,
        Collection $branchInventoryMap,
        array $filters,
        string $expansionMode,
        int $maxDepth,
        int $depth,
        float $multiplier,
        array $pathNames,
        array $visitedProductIds
    ): void
    {
        $ingredient = $recipe->ingredient;
        if (!$ingredient) {
            return;
        }

        $baseUnit = (string) ($ingredient->unit_of_measure ?: $recipe->unit);
        $conversion = $this->unitConversionResolver->convert(
            quantity: (float) $recipe->quantity,
            fromUnit: (string) $recipe->unit,
            toUnit: $baseUnit,
            productId: $ingredient->id
        );

        $effectiveQtyBase = $conversion['missing'] ? null : $multiplier * (float) $conversion['value'];

        $childRecipes = $recipeMap->get($ingredient->id, collect());
        $nextPathNames = [...$pathNames, $ingredient->name];
        $rowWarnings = [];
        $recursionStatus = 'ok';

        if ($conversion['missing']) {
            $rowWarnings[] = 'missing_conversion_rule';
        }

        $hasNestedRecipes = $childRecipes->isNotEmpty();
        if ($expansionMode === 'flatten_subrecipes' && $hasNestedRecipes) {
            if (in_array($ingredient->id, $visitedProductIds, true)) {
                $recursionStatus = 'cycle_detected';
                $rowWarnings[] = 'cycle_detected';
            } elseif (($depth + 1) >= $maxDepth) {
                $recursionStatus = 'max_depth_reached';
                $rowWarnings[] = 'max_depth_reached';
            }
        }

        $inventory = $branchInventoryMap->get($ingredient->id);
        $branchStock = $inventory ? (float) $inventory->current_stock : null;
        $branchReorder = $inventory ? (float) $inventory->reorder_level : null;
        $branchAvgCost = $inventory ? $inventory->average_cost : null;
        $fallbackCost = $ingredient->cost_price;

        $selectedCost = null;
        $costStatus = null;

        if (!empty($filters['branch_id'])) {
            if ($branchAvgCost !== null) {
                $selectedCost = (float) $branchAvgCost;
                $costStatus = $selectedCost === 0.0 ? 'zero_cost_suspicious' : 'ok';
            } elseif ($fallbackCost !== null) {
                $selectedCost = (float) $fallbackCost;
                $costStatus = 'fallback_used';
            } else {
                $costStatus = 'missing';
            }
        }

        $coverageIngredient = null;
        if (!empty($filters['branch_id']) && $effectiveQtyBase && $effectiveQtyBase > 0 && $branchStock !== null) {
            $coverageIngredient = $branchStock / $effectiveQtyBase;
        }

        $rows->push([
            'parent_product_id' => $parent->id,
            'parent_product_name' => $parent->name,
            'parent_product_sku' => $parent->sku,
            'ingredient_id' => $ingredient->id,
            'ingredient_name' => $ingredient->name,
            'ingredient_sku' => $ingredient->sku,
            'ingredient_product_type' => $ingredient->product_type,
            'direct_quantity' => (float) $recipe->quantity,
            'direct_unit' => $recipe->unit,
            'effective_quantity_base' => $effectiveQtyBase,
            'ingredient_base_unit' => $baseUnit,
            'depth' => $depth,
            'path_signature' => implode(' > ', $nextPathNames),
            'conversion_status' => $conversion['missing'] ? 'missing_rule' : 'ok',
            'mode_semantics' => $expansionMode === 'flatten_subrecipes' ? 'planning_only' : 'matches_live_deduction',
            'branch_current_stock' => $branchStock,
            'branch_reorder_level' => $branchReorder,
            'branch_average_cost' => $branchAvgCost !== null ? (float) $branchAvgCost : null,
            'fallback_cost_price' => $fallbackCost !== null ? (float) $fallbackCost : null,
            'cost_status' => $costStatus,
            'effective_cost_per_parent_unit' => ($effectiveQtyBase !== null && $selectedCost !== null)
                ? (float) bcmul((string) $effectiveQtyBase, (string) $selectedCost, 4)
                : null,
            'coverage_ingredient_parent_units' => $coverageIngredient,
            'coverage_parent_bottleneck_units' => null,
            'recursion_status' => $recursionStatus,
            'row_warnings' => $rowWarnings,
        ]);

        if (
            $expansionMode !== 'flatten_subrecipes'
            || !$hasNestedRecipes
            || $conversion['missing']
            || $recursionStatus !== 'ok'
            || $effectiveQtyBase === null
        ) {
            return;
        }

        foreach ($childRecipes as $childRecipe) {
            $this->appendRecipeRows(
                rows: $rows,
                parent: $parent,
                recipe: $childRecipe,
                recipeMap: $recipeMap,
                branchInventoryMap: $branchInventoryMap,
                filters: $filters,
                expansionMode: $expansionMode,
                maxDepth: $maxDepth,
                depth: $depth + 1,
                multiplier: $effectiveQtyBase,
                pathNames: $nextPathNames,
                visitedProductIds: [...$visitedProductIds, $ingredient->id]
            );
        }
    }
}
