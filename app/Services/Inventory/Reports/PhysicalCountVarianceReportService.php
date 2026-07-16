<?php

namespace App\Services\Inventory\Reports;

use App\Models\StocktakeLine;
use App\Services\Inventory\Reports\Concerns\BuildsInventoryReportMetadata;

class PhysicalCountVarianceReportService
{
    use BuildsInventoryReportMetadata;

    public function __construct(
        private readonly ReportWatermarkService $watermarks,
    ) {}

    public function build(InventoryReportFilter $filter, array $branchIds): array
    {
        $watermarks = $this->watermarks->branchWatermarks($branchIds);

        $query = StocktakeLine::query()
            ->whereIn('branch_id', $branchIds)
            ->where(function ($line) {
                $line->whereNotNull('physical_count_variance_quantity')
                    ->orWhereNotNull('variance_quantity');
            })
            ->with(['session:id,stocktake_number,status,stocktake_operation_mode,stocktake_scope_type', 'product:id,name,sku,unit_of_measure']);

        $rows = $query->orderByDesc('created_at')->limit(500)->get()->map(fn (StocktakeLine $line) => [
            'stocktake_number' => $line->session?->stocktake_number,
            'branch_id' => $line->branch_id,
            'product' => $line->product?->name,
            'counted_quantity' => (float) $line->counted_quantity,
            'expected_at_count_time' => (float) ($line->expected_quantity_at_count_time ?? $line->expected_quantity),
            'physical_count_variance' => (float) ($line->physical_count_variance_quantity ?? $line->variance_quantity),
            'posted_variance' => (float) ($line->posted_variance_quantity ?? 0),
            'posting_outcome' => $line->posting_outcome,
            'evidence_quality' => $line->posting_evidence_quality ?? ($line->count_snapshot_uuid ? 'complete' : 'legacy'),
            'count_snapshot_uuid' => $line->count_snapshot_uuid,
            'physically_counted_at' => $line->physically_counted_at?->toIso8601String(),
            'count_recorded_at' => $line->count_recorded_at?->toIso8601String(),
            'operation_mode' => $line->session?->stocktake_operation_mode,
            'scope_type' => $line->session?->stocktake_scope_type,
        ])->values()->all();

        return [
            'rows' => $rows,
            'summary' => ['total_rows' => count($rows)],
            'meta' => $this->metadata('physical_count_variance', $filter, $branchIds, $watermarks, 'count_or_post_date', 'sequence_bounded'),
        ];
    }
}
