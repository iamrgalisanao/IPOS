<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\Product;

/**
 * RecipeCostingService
 *
 * Computes the Weighted Average Cost (WAC) of a composite product's recipe
 * by summing each ingredient's (quantity × branch WAC) contribution.
 *
 * Design boundaries:
 * - Uses BranchInventory.average_cost (WAC), which is updated on every
 *   purchase receiving and inter-branch transfer posting.
 * - Falls back to Product.cost_price when no branch WAC is available.
 * - Does NOT perform recursive sub-recipe expansion; only direct recipe
 *   components are costed (see Epic 32 parking note on recursive deduction).
 * - Applies the same unit conversion chain as the POS deduction engine.
 */
class RecipeCostingService
{
    public function __construct(
        private readonly UnitConversionResolver $converter
    ) {}

    /**
     * Compute the estimated recipe cost for one unit of the given composite product.
     *
     * @param  Product     $product   The composite product whose recipe will be costed.
     * @param  string|null $branchId  When provided, branch WAC is preferred over catalog cost.
     * @return array{
     *   total_cost: float|null,
     *   currency: string,
     *   branch_id: string|null,
     *   ingredients: array<int, array{
     *     ingredient_id: string,
     *     name: string,
     *     sku: string,
     *     recipe_quantity: float,
     *     recipe_unit: string,
     *     ingredient_uom: string,
     *     converted_quantity: float,
     *     unit_cost: float|null,
     *     cost_source: string,
     *     line_cost: float|null,
     *     conversion_missing: bool,
     *   }>,
     *   has_missing_costs: bool,
     *   has_missing_conversions: bool,
     * }
     */
    public function compute(Product $product, ?string $branchId = null): array
    {
        $product->loadMissing('recipes.ingredient');

        $rows = [];
        $totalCost = 0.0;
        $hasMissingCosts = false;
        $hasMissingConversions = false;

        foreach ($product->recipes as $recipe) {
            $ingredient = $recipe->ingredient;

            if (!$ingredient) {
                continue;
            }

            $recipeQty  = (float) $recipe->quantity;
            $recipeUnit = $recipe->unit;
            $ingredientUom = $ingredient->unit_of_measure;

            // Convert recipe quantity to the ingredient's stocked unit.
            $converted = $this->converter->convert(
                quantity: $recipeQty,
                fromUnit: $recipeUnit,
                toUnit: $ingredientUom,
                productId: $ingredient->id,
                strict: false
            );

            $convertedQty      = $converted['value'];
            $conversionMissing = $converted['missing'];

            if ($conversionMissing) {
                $hasMissingConversions = true;
            }

            // Resolve unit cost: prefer branch WAC, fallback to catalog cost_price.
            [$unitCost, $costSource] = $this->resolveUnitCost($ingredient, $branchId);

            if ($unitCost === null) {
                $hasMissingCosts = true;
            }

            $lineCost = ($unitCost !== null && !$conversionMissing)
                ? round($convertedQty * $unitCost, 4)
                : null;

            if ($lineCost !== null) {
                $totalCost += $lineCost;
            } else {
                $hasMissingCosts = true;
            }

            $rows[] = [
                'ingredient_id'      => $ingredient->id,
                'name'               => $ingredient->name,
                'sku'                => $ingredient->sku,
                'recipe_quantity'    => $recipeQty,
                'recipe_unit'        => $recipeUnit,
                'ingredient_uom'     => $ingredientUom,
                'converted_quantity' => round($convertedQty, 4),
                'unit_cost'          => $unitCost,
                'cost_source'        => $costSource,
                'line_cost'          => $lineCost,
                'conversion_missing' => $conversionMissing,
            ];
        }

        return [
            'total_cost'              => ($hasMissingCosts || $hasMissingConversions) ? null : round($totalCost, 4),
            'currency'                => 'PHP',
            'branch_id'               => $branchId,
            'ingredients'             => $rows,
            'has_missing_costs'       => $hasMissingCosts,
            'has_missing_conversions' => $hasMissingConversions,
        ];
    }

    /**
     * Resolve the unit cost for an ingredient.
     *
     * Priority:
     * 1. Branch WAC (BranchInventory.average_cost) when branch_id is given and > 0.
     * 2. Product catalog cost_price.
     * 3. null when no cost is available.
     *
     * @return array{float|null, string}  [cost, source_label]
     */
    private function resolveUnitCost(Product $ingredient, ?string $branchId): array
    {
        if ($branchId) {
            $branchInventory = BranchInventory::where('product_id', $ingredient->id)
                ->where('branch_id', $branchId)
                ->first();

            if ($branchInventory && $branchInventory->average_cost !== null && (float) $branchInventory->average_cost > 0) {
                return [(float) $branchInventory->average_cost, 'branch_wac'];
            }
        }

        if ($ingredient->cost_price !== null && (float) $ingredient->cost_price > 0) {
            return [(float) $ingredient->cost_price, 'catalog_cost'];
        }

        return [null, 'none'];
    }
}
