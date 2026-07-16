<?php

namespace App\Services\Inventory\Reports;

use App\Models\InventoryMovement;
use App\Services\Inventory\Reports\Concerns\BuildsInventoryReportMetadata;

class StockCardReportService
{
    use BuildsInventoryReportMetadata;

    public function __construct(
        private readonly MovementCategoryClassifier $classifier,
        private readonly ReportWatermarkService $watermarks,
    ) {}

    public function build(InventoryReportFilter $filter, array $branchIds): array
    {
        $branchId = (string) $filter->get('branch_id');
        $productId = (string) $filter->get('product_id');

        if (!$branchId || !$productId) {
            return [
                'rows' => [],
                'summary' => [
                    'total_rows' => 0,
                    'required_filters' => 'branch_id and product_id',
                ],
                'meta' => $this->metadata('stock_card', $filter, $branchIds, [], 'business_date_sequence_ordered', 'sequence_bounded', [
                    'requires_branch_and_product' => true,
                ]),
            ];
        }

        $watermarks = $this->watermarks->branchWatermarks([$branchId]);
        $upper = $this->watermarks->upperSequenceFor($watermarks, $branchId);

        $query = InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('movement_sequence', '<=', $upper)
            ->with(['branch:id,name', 'product:id,name,sku,unit_of_measure']);

        $this->applyDateRange($query, $filter);

        if ($cursor = $filter->get('cursor')) {
            $query->where('movement_sequence', '<', (int) $cursor);
        }

        $rows = $query
            ->orderByDesc('movement_sequence')
            ->limit(min((int) $filter->get('per_page', 50), 100))
            ->get()
            ->map(fn (InventoryMovement $movement) => $this->row($movement))
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'summary' => [
                'total_rows' => count($rows),
                'next_cursor' => collect($rows)->last()['movement_sequence'] ?? null,
            ],
            'meta' => $this->metadata('stock_card', $filter, [$branchId], $watermarks, 'business_date_sequence_ordered', 'sequence_bounded'),
        ];
    }

    private function row(InventoryMovement $movement): array
    {
        $category = $this->classifier->classify($movement->movement_type, $movement->quantity_change);

        return [
            'movement_sequence' => (int) $movement->movement_sequence,
            'business_date' => $movement->business_date?->toDateString(),
            'movement_category' => $category,
            'movement_type' => $movement->movement_type,
            'source_reference' => $movement->source_reference ?? $movement->reference_number ?? $movement->source_id,
            'quantity_before' => (float) $movement->quantity_before,
            'quantity_change' => (float) $movement->quantity_change,
            'quantity_after' => (float) $movement->quantity_after,
            'unit' => $movement->product?->unit_of_measure,
            'reason' => $movement->reason_code,
            'actor' => $movement->user_id,
            'movement_uuid' => $movement->movement_uuid,
            'source_effect_key' => $movement->source_effect_key,
            'conversion_snapshot' => $movement->conversion_snapshot,
            'recipe_snapshot' => $movement->metadata['recipe_snapshot'] ?? $movement->metadata['recipe_deduction_snapshot'] ?? null,
            'chain_status' => $movement->quantity_before === null || $movement->quantity_after === null ? 'legacy_unverifiable' : 'continuous',
        ];
    }

    private function applyDateRange($query, InventoryReportFilter $filter): void
    {
        if ($from = $filter->get('date_from')) {
            $query->whereDate('business_date', '>=', $from);
        }

        if ($to = $filter->get('date_to')) {
            $query->whereDate('business_date', '<=', $to);
        }
    }
}
