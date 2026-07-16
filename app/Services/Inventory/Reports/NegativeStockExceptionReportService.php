<?php

namespace App\Services\Inventory\Reports;

use App\Models\InventoryVarianceLog;
use App\Services\Inventory\Reports\Concerns\BuildsInventoryReportMetadata;

class NegativeStockExceptionReportService
{
    use BuildsInventoryReportMetadata;

    public function __construct(
        private readonly ReportWatermarkService $watermarks,
    ) {}

    public function build(InventoryReportFilter $filter, array $branchIds): array
    {
        $watermarks = $this->watermarks->branchWatermarks($branchIds);

        $query = InventoryVarianceLog::query()
            ->where('variance_category', InventoryVarianceLog::CATEGORY_NEGATIVE_STOCK)
            ->whereIn('branch_id', $branchIds)
            ->with(['branch:id,name', 'ingredientProduct:id,name,sku,unit_of_measure', 'product:id,name,sku']);

        if ($status = $filter->get('status')) {
            $query->where('current_status', $status);
        }

        $rows = $query->orderByDesc('created_at')->limit(500)->get()->map(function (InventoryVarianceLog $log) {
            $created = $log->created_at;

            return [
                'branch_id' => $log->branch_id,
                'branch' => $log->branch?->name,
                'product' => $log->ingredientProduct?->name ?? $log->product?->name,
                'source_reference' => $log->source_reference ?? $log->source_id,
                'movement_sequence' => (int) ($log->movement_sequence ?? 0),
                'incremental_shortage_quantity' => (float) $log->incremental_shortage_quantity,
                'resulting_negative_quantity' => (float) $log->resulting_negative_quantity,
                'current_status' => $log->current_status,
                'severity' => ((float) $log->resulting_negative_quantity) > 0 ? 'high' : 'warning',
                'age' => $created ? $created->diffForHumans() : null,
                'recurrence' => 'not_calculated',
                'correction_link_count' => $log->correctionLinks()->count(),
                'latest_status_event' => $log->statusEvents()->latest()->value('to_status'),
                'status_basis' => 'current_at_generated_time',
                'lifecycle_as_of_timestamp' => now()->toIso8601String(),
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'summary' => ['total_rows' => count($rows)],
            'meta' => $this->metadata('negative_stock_exception', $filter, $branchIds, $watermarks, 'source_business_date', 'sequence_bounded', [
                'status_basis' => 'current_at_generated_time',
            ]),
        ];
    }
}
