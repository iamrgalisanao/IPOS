<?php

namespace App\Services\Inventory\Reports;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\UnitConversion;
use App\Services\Inventory\Reports\Concerns\BuildsInventoryReportMetadata;

class InventoryIntegrityReportService
{
    use BuildsInventoryReportMetadata;

    public function __construct(
        private readonly ReportWatermarkService $watermarks,
    ) {}

    public function build(InventoryReportFilter $filter, array $branchIds): array
    {
        $watermarks = $this->watermarks->branchWatermarks($branchIds);
        $type = $filter->get('type', 'all');
        $configurationRows = in_array($type, ['all', 'configuration'], true) ? $this->configurationRows($branchIds) : [];
        $integrityRows = in_array($type, ['all', 'integrity'], true) ? $this->integrityRows($branchIds, $watermarks) : [];
        $rows = array_values(array_merge($configurationRows, $integrityRows));

        return [
            'rows' => $rows,
            'summary' => [
                'total_rows' => count($rows),
                'configuration_gaps' => collect($rows)->where('report_group', 'configuration')->count(),
                'integrity_exceptions' => collect($rows)->where('report_group', 'integrity')->count(),
            ],
            'meta' => $this->metadata('inventory_integrity', $filter, $branchIds, $watermarks, 'generated_at', 'best_effort'),
        ];
    }

    private function configurationRows(array $branchIds): array
    {
        $trackedProducts = Product::query()
            ->where('is_inventory_tracked', true)
            ->where('status', 'active')
            ->get(['id', 'name', 'sku', 'unit_of_measure']);
        $existing = BranchInventory::query()
            ->whereIn('branch_id', $branchIds)
            ->get(['branch_id', 'product_id'])
            ->map(fn ($row) => $row->branch_id.':'.$row->product_id)
            ->all();

        $missingInventory = [];
        foreach ($branchIds as $branchId) {
            foreach ($trackedProducts as $product) {
                if (!in_array($branchId.':'.$product->id, $existing, true)) {
                    $missingInventory[] = $this->configurationRow('MISSING_BRANCH_INVENTORY', 'blocking', $branchId, $product->id, $product->name, 'Inventory administrator', 'branch_inventory_setup');
                }
            }
        }

        $recipeGaps = ProductRecipe::query()
            ->where('is_active', true)
            ->whereDoesntHave('ingredient.branchInventories', fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->with(['product:id,name', 'ingredient:id,name'])
            ->limit(100)
            ->get()
            ->map(fn (ProductRecipe $recipe) => $this->configurationRow(
                'RECIPE_INGREDIENT_MISSING_BRANCH_INVENTORY',
                'blocking',
                $branchIds[0] ?? null,
                $recipe->ingredient_id,
                $recipe->ingredient?->name,
                'Inventory administrator',
                'recipe_setup',
            ))
            ->all();

        return array_values(array_merge($missingInventory, $recipeGaps));
    }

    private function integrityRows(array $branchIds, array $watermarks): array
    {
        $rows = [];

        $negativeWithoutException = BranchInventory::query()
            ->whereIn('branch_id', $branchIds)
            ->where('current_stock', '<', 0)
            ->with(['branch:id,name', 'product:id,name,sku'])
            ->limit(100)
            ->get();

        foreach ($negativeWithoutException as $inventory) {
            $rows[] = [
                'report_group' => 'integrity',
                'exception_code' => 'NEGATIVE_STOCK_REVIEW_REQUIRED',
                'severity' => 'high',
                'branch_id' => $inventory->branch_id,
                'branch' => $inventory->branch?->name,
                'product_id' => $inventory->product_id,
                'product' => $inventory->product?->name,
                'evidence_summary' => 'Current stock is negative and should be tied to negative-stock exception evidence.',
                'chain_status' => null,
                'baseline_status' => null,
                'owner_type' => 'Inventory auditor',
                'recommended_investigation_entry_point' => 'negative_stock_exceptions',
            ];
        }

        $movements = InventoryMovement::query()
            ->whereIn('branch_id', $branchIds)
            ->where(function ($query) {
                $query->whereNull('quantity_before')->orWhereNull('quantity_after')->orWhereNull('source_effect_key');
            })
            ->with(['branch:id,name', 'product:id,name,sku'])
            ->limit(100)
            ->get();

        foreach ($movements as $movement) {
            $rows[] = [
                'report_group' => 'integrity',
                'exception_code' => $movement->source_effect_key ? 'MOVEMENT_CHAIN_LEGACY_UNVERIFIABLE' : 'MISSING_SOURCE_EFFECT_KEY',
                'severity' => $movement->source_effect_key ? 'warning' : 'high',
                'branch_id' => $movement->branch_id,
                'branch' => $movement->branch?->name,
                'product_id' => $movement->product_id,
                'product' => $movement->product?->name,
                'evidence_summary' => 'Movement evidence needs investigation.',
                'chain_status' => $movement->quantity_before === null || $movement->quantity_after === null ? 'legacy_unverifiable' : 'continuous',
                'baseline_status' => null,
                'owner_type' => 'Support/engineering',
                'recommended_investigation_entry_point' => 'stock_card',
            ];
        }

        return $rows;
    }

    private function configurationRow(string $code, string $severity, ?string $branchId, ?string $productId, ?string $product, string $owner, string $capability): array
    {
        return [
            'report_group' => 'configuration',
            'gap_code' => $code,
            'severity' => $severity,
            'affected_capability' => $capability,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'product' => $product,
            'evidence' => $code,
            'recommended_setup_page' => $capability,
            'owner_type' => $owner,
            'remediation_capability' => $capability,
            'remediation_permission' => 'manage_products',
        ];
    }
}
