<?php

namespace App\Services\Inventory\Reports;

use App\Models\BranchInventory;
use App\Services\Inventory\Reports\Concerns\BuildsInventoryReportMetadata;

class CurrentStockReportService
{
    use BuildsInventoryReportMetadata;

    public function __construct(
        private readonly ReportWatermarkService $watermarks,
    ) {}

    public function build(InventoryReportFilter $filter, array $branchIds): array
    {
        $watermarks = $this->watermarks->branchWatermarks($branchIds);

        $query = BranchInventory::query()
            ->active()
            ->whereIn('branch_id', $branchIds)
            ->whereHas('product', fn ($query) => $query->where('is_inventory_tracked', true))
            ->with(['branch:id,name', 'product:id,name,sku,barcode,unit_of_measure,product_category_id', 'product.category:id,name']);

        if ($categoryId = $filter->get('category_id')) {
            $query->whereHas('product', fn ($product) => $product->where('product_category_id', $categoryId));
        }

        if ($search = trim((string) $filter->get('search', ''))) {
            $query->whereHas('product', function ($product) use ($search) {
                $product->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($state = $filter->get('stock_state')) {
            match ($state) {
                'negative' => $query->where('current_stock', '<', 0),
                'low' => $query->whereColumn('current_stock', '<=', 'reorder_level')->where('current_stock', '>=', 0),
                'normal' => $query->whereColumn('current_stock', '>', 'reorder_level'),
                default => null,
            };
        }

        $rows = $query
            ->orderByRaw('CASE WHEN current_stock < 0 THEN 0 WHEN current_stock <= reorder_level THEN 1 ELSE 2 END')
            ->orderBy('current_stock')
            ->limit(500)
            ->get()
            ->map(function (BranchInventory $inventory) use ($watermarks) {
                $stock = (float) $inventory->current_stock;
                $reorder = (float) ($inventory->reorder_level ?? 0);

                return [
                    'branch_id' => $inventory->branch_id,
                    'branch' => $inventory->branch?->name,
                    'product_id' => $inventory->product_id,
                    'product' => $inventory->product?->name,
                    'sku' => $inventory->product?->sku,
                    'barcode' => $inventory->product?->barcode,
                    'category' => $inventory->product?->category?->name ?? 'Uncategorized',
                    'current_stock' => round($stock, 4),
                    'reorder_level' => round($reorder, 4),
                    'base_unit' => $inventory->product?->unit_of_measure,
                    'stock_state' => $stock < 0 ? 'negative' : ($stock <= $reorder ? 'low' : 'normal'),
                    'inventory_revision' => (int) ($inventory->inventory_revision ?? 0),
                    'latest_movement_sequence' => $this->watermarks->upperSequenceFor($watermarks, (string) $inventory->branch_id),
                    'consistency_status' => 'best_effort',
                ];
            })
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'summary' => [
                'total_rows' => count($rows),
                'negative_rows' => collect($rows)->where('stock_state', 'negative')->count(),
                'low_rows' => collect($rows)->where('stock_state', 'low')->count(),
            ],
            'meta' => $this->metadata('current_stock', $filter, $branchIds, $watermarks, 'current_operational_state', 'best_effort', [
                'historical_as_of_supported' => false,
            ]),
        ];
    }
}
