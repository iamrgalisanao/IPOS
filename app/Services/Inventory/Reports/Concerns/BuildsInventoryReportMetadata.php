<?php

namespace App\Services\Inventory\Reports\Concerns;

use App\Services\Inventory\Reports\InventoryReportFilter;
use App\Services\Inventory\Reports\MovementCategoryClassifier;

trait BuildsInventoryReportMetadata
{
    protected function metadata(
        string $reportType,
        InventoryReportFilter $filter,
        array $branchIds,
        array $watermarks,
        string $dateBasis,
        string $consistencyLevel,
        array $extra = [],
    ): array {
        return array_merge([
            'report_type' => $reportType,
            'generated_at' => now()->toIso8601String(),
            'date_basis' => $dateBasis,
            'branch_scope' => $branchIds,
            'filter_fingerprint' => $filter->fingerprint(),
            'branch_watermarks' => $watermarks,
            'data_as_of_movement_sequence' => collect($watermarks)->max('latest_movement_sequence') ?? 0,
            'data_as_of_timestamp' => collect($watermarks)->pluck('latest_movement_posted_at')->filter()->max(),
            'consistency_level' => $consistencyLevel,
            'movement_category_mapping_version' => MovementCategoryClassifier::VERSION,
        ], $extra);
    }
}
