<?php

namespace App\Services\Dining;

use App\Models\DiningTable;
use App\Models\SalesMachineProfile;
use App\Models\ServiceArea;
use Illuminate\Support\Collection;

class DiningFloorReadModel
{
    public function __construct(
        private readonly DiningTableStatusResolver $statusResolver,
    ) {
    }

    public function forBranch(string $tenantId, string $branchId, ?SalesMachineProfile $terminal = null): array
    {
        $serviceAreas = ServiceArea::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with(['tables' => function ($query) {
                $query
                    ->where('is_active', true)
                    ->orderBy('table_number')
                    ->with([
                        'serviceArea',
                        'activeTicketMappings.ticket',
                    ]);
            }])
            ->orderBy('name')
            ->get();

        $allTables = $serviceAreas->flatMap(fn (ServiceArea $serviceArea) => $serviceArea->tables);
        $statuses = $this->statusResolver->resolveMany($allTables);

        return [
            'schema_version' => 1,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'terminal_id' => $terminal?->id,
            'cache_key' => $this->cacheKey($tenantId, $branchId, $terminal?->id),
            'generated_at' => now()->toIso8601String(),
            'layout_revision' => $this->layoutRevision($serviceAreas),
            'occupancy_revision' => $this->occupancyRevision($allTables, $statuses),
            'service_areas' => $serviceAreas
                ->map(fn (ServiceArea $serviceArea) => $this->serviceAreaPayload($serviceArea, $statuses))
                ->values()
                ->all(),
        ];
    }

    public function cacheKey(string $tenantId, string $branchId, ?string $terminalId): string
    {
        return sprintf('ipos:dining-floor-map:v1:%s:%s:%s', $tenantId, $branchId, $terminalId ?: 'terminalless');
    }

    private function serviceAreaPayload(ServiceArea $serviceArea, Collection $statuses): array
    {
        return [
            'id' => $serviceArea->id,
            'name' => $serviceArea->name,
            'layout_metadata' => $serviceArea->layout_metadata,
            'layout_revision' => $serviceArea->layout_revision,
            'tables' => $serviceArea->tables
                ->map(fn (DiningTable $table) => $this->tablePayload($table, $statuses->get($table->id)))
                ->values()
                ->all(),
        ];
    }

    private function tablePayload(DiningTable $table, mixed $status): array
    {
        $statusPayload = $status?->toArray() ?? [];

        return array_merge([
            'id' => $table->id,
            'service_area_id' => $table->service_area_id,
            'table_number' => $table->table_number,
            'capacity' => $table->capacity,
            'operational_state' => $table->operational_state,
            'position_metadata' => $table->position_metadata,
        ], $statusPayload);
    }

    private function layoutRevision(Collection $serviceAreas): string
    {
        return hash('sha256', json_encode($serviceAreas->map(fn (ServiceArea $serviceArea) => [
            'id' => $serviceArea->id,
            'layout_revision' => $serviceArea->layout_revision,
            'layout_metadata' => $serviceArea->layout_metadata,
            'updated_at' => optional($serviceArea->updated_at)->toJSON(),
            'tables' => $serviceArea->tables->map(fn (DiningTable $table) => [
                'id' => $table->id,
                'table_number' => $table->table_number,
                'capacity' => $table->capacity,
                'position_metadata' => $table->position_metadata,
                'updated_at' => optional($table->updated_at)->toJSON(),
            ])->values()->all(),
        ])->values()->all(), JSON_THROW_ON_ERROR));
    }

    private function occupancyRevision(Collection $tables, Collection $statuses): string
    {
        return hash('sha256', json_encode($tables->map(fn (DiningTable $table) => [
            'id' => $table->id,
            'status' => $statuses->get($table->id)?->status,
            'status_reason' => $statuses->get($table->id)?->reason,
            'active_ticket' => $statuses->get($table->id)?->activeTicket,
            'operational_state' => $table->operational_state,
        ])->values()->all(), JSON_THROW_ON_ERROR));
    }
}
