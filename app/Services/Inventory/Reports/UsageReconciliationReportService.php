<?php

namespace App\Services\Inventory\Reports;

use App\Models\InventoryMovement;
use App\Services\Inventory\Reports\Concerns\BuildsInventoryReportMetadata;

class UsageReconciliationReportService
{
    use BuildsInventoryReportMetadata;

    public function __construct(
        private readonly MovementCategoryClassifier $classifier,
        private readonly ReportWatermarkService $watermarks,
    ) {}

    public function build(InventoryReportFilter $filter, array $branchIds): array
    {
        $watermarks = $this->watermarks->branchWatermarks($branchIds);
        $dateFrom = $filter->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $filter->get('date_to', now()->toDateString());

        $query = InventoryMovement::query()
            ->whereIn('branch_id', $branchIds)
            ->whereDate('business_date', '>=', $dateFrom)
            ->whereDate('business_date', '<=', $dateTo)
            ->where('quantity_change', '<', 0)
            ->with(['branch:id,name', 'product:id,name,sku,unit_of_measure']);

        $rows = $query->orderBy('branch_id')->orderBy('product_id')->limit(500)->get()
            ->groupBy(fn ($movement) => $movement->branch_id.':'.$movement->product_id)
            ->map(function ($items) {
                $first = $items->first();
                $saleDriven = $items->filter(fn ($movement) => $this->classifier->classify($movement->movement_type, $movement->quantity_change) === 'sales_out');
                $nonSale = $items->reject(fn ($movement) => $this->classifier->classify($movement->movement_type, $movement->quantity_change) === 'sales_out');

                return [
                    'branch_id' => $first->branch_id,
                    'branch' => $first->branch?->name,
                    'product_id' => $first->product_id,
                    'product' => $first->product?->name,
                    'expected_sale_driven_usage' => null,
                    'expected_usage_status' => 'unavailable',
                    'recorded_sale_driven_deductions' => round(abs($saleDriven->sum('quantity_change')), 4),
                    'non_sale_inventory_effects' => round(abs($nonSale->sum('quantity_change')), 4),
                    'stocktake_correction_impact' => round(abs($items->filter(fn ($movement) => $this->classifier->classify($movement->movement_type, $movement->quantity_change) === 'stocktake_correction')->sum('quantity_change')), 4),
                    'manual_adjustment_impact' => round(abs($items->filter(fn ($movement) => str_contains((string) $movement->movement_type, 'adjustment'))->sum('quantity_change')), 4),
                    'expected_versus_recorded_variance' => null,
                    'evidence_quality' => 'partial',
                    'independent_expected_evidence_source' => null,
                ];
            })
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'summary' => ['total_rows' => count($rows)],
            'meta' => $this->metadata('expected_versus_recorded_inventory_usage', $filter, $branchIds, $watermarks, 'business_date', 'sequence_bounded', [
                'expected_usage_status' => 'unavailable_when_independent_evidence_missing',
            ]),
        ];
    }
}
