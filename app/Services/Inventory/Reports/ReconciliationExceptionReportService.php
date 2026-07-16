<?php

namespace App\Services\Inventory\Reports;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Services\Inventory\Reports\Concerns\BuildsInventoryReportMetadata;

class ReconciliationExceptionReportService
{
    use BuildsInventoryReportMetadata;

    public function __construct(
        private readonly ReportWatermarkService $watermarks,
    ) {}

    public function build(InventoryReportFilter $filter, array $branchIds): array
    {
        $watermarks = $this->watermarks->branchWatermarks($branchIds);

        $inventories = BranchInventory::query()
            ->active()
            ->whereIn('branch_id', $branchIds)
            ->with(['branch:id,name', 'product:id,name,sku,unit_of_measure'])
            ->limit(500)
            ->get();

        $rows = $inventories->map(function (BranchInventory $inventory) use ($watermarks) {
            $upper = $this->watermarks->upperSequenceFor($watermarks, (string) $inventory->branch_id);
            $baselineStatus = $this->baselineStatus((string) $inventory->branch_id, (string) $inventory->product_id, $upper);
            $movementDerived = InventoryMovement::query()
                ->where('branch_id', $inventory->branch_id)
                ->where('product_id', $inventory->product_id)
                ->where('movement_sequence', '<=', $upper)
                ->sum('quantity_change');
            $variance = (float) $inventory->current_stock - (float) $movementDerived;
            $status = $baselineStatus === 'missing' || $baselineStatus === 'legacy_unverifiable'
                ? 'indeterminate'
                : (abs($variance) < 0.0001 ? 'reconciled' : 'exception');

            return [
                'branch_id' => $inventory->branch_id,
                'branch' => $inventory->branch?->name,
                'product_id' => $inventory->product_id,
                'product' => $inventory->product?->name,
                'operational_current_stock' => (float) $inventory->current_stock,
                'movement_derived_stock' => round((float) $movementDerived, 4),
                'system_reconciliation_variance' => round($variance, 4),
                'baseline_status' => $baselineStatus,
                'reconciliation_status' => $status,
                'inventory_revision' => (int) ($inventory->inventory_revision ?? 0),
                'last_movement_sequence' => $upper,
                'data_as_of_watermark' => $upper,
                'consistency_status' => 'operational_snapshot',
                'recommended_investigation_entry_point' => 'stock_card',
            ];
        })
            ->filter(fn (array $row) => $filter->get('show_reconciled') ? true : $row['reconciliation_status'] !== 'reconciled')
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'summary' => [
                'total_rows' => count($rows),
                'exceptions' => collect($rows)->where('reconciliation_status', 'exception')->count(),
                'indeterminate' => collect($rows)->where('reconciliation_status', 'indeterminate')->count(),
            ],
            'meta' => $this->metadata('system_reconciliation_exception', $filter, $branchIds, $watermarks, 'generated_at', 'operational_snapshot'),
        ];
    }

    private function baselineStatus(string $branchId, string $productId, int $upper): string
    {
        $baseline = InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('movement_sequence', '<=', $upper)
            ->whereIn('movement_type', ['opening_balance', 'migration_baseline'])
            ->orderBy('movement_sequence')
            ->first();

        if (!$baseline) {
            return 'missing';
        }

        return $baseline->movement_type === 'migration_baseline' ? 'migration_baseline' : 'opening_balance';
    }
}
