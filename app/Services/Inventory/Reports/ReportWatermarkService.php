<?php

namespace App\Services\Inventory\Reports;

use App\Models\InventoryMovement;
use Illuminate\Support\Carbon;

class ReportWatermarkService
{
    public function branchWatermarks(array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $rows = InventoryMovement::query()
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('branch_id, MAX(movement_sequence) as latest_movement_sequence, MAX(posted_at) as latest_movement_posted_at')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        return collect($branchIds)
            ->map(function (string $branchId) use ($rows) {
                $row = $rows->get($branchId);

                return [
                    'branch_id' => $branchId,
                    'latest_movement_sequence' => $row ? (int) ($row->latest_movement_sequence ?? 0) : 0,
                    'latest_movement_posted_at' => $row?->latest_movement_posted_at
                        ? Carbon::parse($row->latest_movement_posted_at)->toIso8601String()
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    public function upperSequenceFor(array $watermarks, string $branchId): int
    {
        foreach ($watermarks as $watermark) {
            if (($watermark['branch_id'] ?? null) === $branchId) {
                return (int) ($watermark['latest_movement_sequence'] ?? 0);
            }
        }

        return 0;
    }
}
