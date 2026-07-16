<?php

namespace App\Services\Inventory\Reports;

use App\Models\InventoryMovement;
use App\Services\Inventory\Reports\Concerns\BuildsInventoryReportMetadata;
use Illuminate\Support\Facades\DB;

class MovementSummaryReportService
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

        $periodMovements = InventoryMovement::query()
            ->whereIn('branch_id', $branchIds)
            ->whereDate('business_date', '>=', $dateFrom)
            ->whereDate('business_date', '<=', $dateTo)
            ->with(['branch:id,name', 'product:id,name,sku,unit_of_measure']);

        $this->applyWatermarkBounds($periodMovements, $watermarks);

        if ($productId = $filter->get('product_id')) {
            $periodMovements->where('product_id', $productId);
        }

        $movements = $periodMovements->orderBy('branch_id')->orderBy('product_id')->orderBy('movement_sequence')->get();
        $groups = $movements->groupBy(fn ($movement) => $movement->branch_id.':'.$movement->product_id);

        $rows = $groups->map(function ($items) use ($dateFrom, $watermarks) {
            $first = $items->first();
            $branchId = (string) $first->branch_id;
            $upper = $this->watermarks->upperSequenceFor($watermarks, $branchId);
            $opening = $this->openingStock($branchId, (string) $first->product_id, $dateFrom, $upper);
            $stockIn = $items->sum(fn ($movement) => max((float) $movement->quantity_change, 0));
            $stockOut = abs($items->sum(fn ($movement) => min((float) $movement->quantity_change, 0)));
            $net = $stockIn - $stockOut;

            return [
                'branch_id' => $branchId,
                'branch' => $first->branch?->name,
                'product_id' => $first->product_id,
                'product' => $first->product?->name,
                'sku' => $first->product?->sku,
                'opening_stock' => round($opening['opening_stock'], 4),
                'opening_stock_basis' => $opening['opening_stock_basis'],
                'stock_in' => round($stockIn, 4),
                'stock_out' => round($stockOut, 4),
                'net_movement' => round($net, 4),
                'movement_derived_closing_stock' => round($opening['opening_stock'] + $net, 4),
                'movement_count' => $items->count(),
                'first_movement_sequence' => (int) $items->min('movement_sequence'),
                'last_movement_sequence' => (int) $items->max('movement_sequence'),
                'evidence_quality' => $opening['evidence_quality'],
                'summary_calculation_basis' => 'business_date_activity',
                'ledger_as_of_sequence' => $upper,
                'movement_category_mapping_version' => MovementCategoryClassifier::VERSION,
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'summary' => [
                'total_rows' => count($rows),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'meta' => $this->metadata('movement_summary', $filter, $branchIds, $watermarks, 'business_date', 'sequence_bounded', [
                'summary_calculation_basis' => 'business_date_activity',
            ]),
        ];
    }

    private function openingStock(string $branchId, string $productId, string $dateFrom, int $upper): array
    {
        $baseline = InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('movement_sequence', '<=', $upper)
            ->whereIn('movement_type', ['opening_balance', 'migration_baseline'])
            ->orderByDesc('movement_sequence')
            ->first();

        if (!$baseline) {
            return [
                'opening_stock' => 0.0,
                'opening_stock_basis' => 'no_prior_movement',
                'evidence_quality' => 'unavailable',
            ];
        }

        $deltaBeforePeriod = InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('movement_sequence', '>', (int) $baseline->movement_sequence)
            ->where('movement_sequence', '<=', $upper)
            ->whereDate('business_date', '<', $dateFrom)
            ->sum(DB::raw('CAST(quantity_change AS DECIMAL(18,4))'));

        return [
            'opening_stock' => (float) $baseline->quantity_after + (float) $deltaBeforePeriod,
            'opening_stock_basis' => $baseline->movement_type === 'migration_baseline' ? 'migration_baseline' : 'opening_balance',
            'evidence_quality' => 'complete',
        ];
    }

    private function applyWatermarkBounds($query, array $watermarks): void
    {
        $query->where(function ($bounded) use ($watermarks) {
            foreach ($watermarks as $watermark) {
                $bounded->orWhere(function ($branchQuery) use ($watermark) {
                    $branchQuery
                        ->where('branch_id', $watermark['branch_id'])
                        ->where('movement_sequence', '<=', (int) $watermark['latest_movement_sequence']);
                });
            }
        });
    }
}
