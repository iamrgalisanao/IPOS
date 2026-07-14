<?php

namespace App\Services\Dining;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\ServiceArea;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DiningLayoutService
{
    public function __construct(
        private readonly DiningLayoutMetadataValidator $validator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function createServiceArea(array $data, User $actor): ServiceArea
    {
        $branch = $this->resolveBranch($data['branch_id'], $actor);
        $name = trim($data['name']);
        $metadata = $this->validator->validateLayout($data['layout_metadata'] ?? []);

        return DB::transaction(function () use ($data, $actor, $branch, $name, $metadata) {
            $area = ServiceArea::create([
                'branch_id' => $branch->id,
                'name' => $name,
                'normalized_name' => $this->normalizeName($name),
                'layout_metadata' => $metadata,
                'layout_revision' => 1,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->auditLogger->log('SERVICE_AREA_CREATED', $area, null, $this->areaAuditPayload($area));

            return $area;
        });
    }

    public function updateServiceArea(ServiceArea $area, array $data, User $actor): ServiceArea
    {
        $this->assertBranchAccess($area->branch, $actor);
        $before = $this->areaAuditPayload($area);

        return DB::transaction(function () use ($area, $data, $actor, $before) {
            if (array_key_exists('name', $data)) {
                $area->name = trim($data['name']);
                $area->normalized_name = $this->normalizeName($area->name);
            }

            if (array_key_exists('layout_metadata', $data)) {
                $area->layout_metadata = $this->validator->validateLayout($data['layout_metadata']);
            }

            $area->updated_by = $actor->id;
            $area->save();

            $this->auditLogger->log('SERVICE_AREA_UPDATED', $area, $before, $this->areaAuditPayload($area));

            return $area;
        });
    }

    public function deleteServiceArea(ServiceArea $area, User $actor): void
    {
        $this->assertBranchAccess($area->branch, $actor);

        if ($area->tables()->exists()) {
            throw new ConflictHttpException('Service areas containing tables cannot be deleted. Deactivate or remove tables first.');
        }

        $before = $this->areaAuditPayload($area);

        DB::transaction(function () use ($area, $before) {
            $area->delete();
            $this->auditLogger->log('SERVICE_AREA_DELETED', null, $before, null, metadata: [
                'service_area_id' => $area->id,
            ]);
        });
    }

    public function setServiceAreaActivation(ServiceArea $area, bool $active, User $actor): ServiceArea
    {
        $this->assertBranchAccess($area->branch, $actor);
        $before = $this->areaAuditPayload($area);

        return DB::transaction(function () use ($area, $active, $actor, $before) {
            if (!$active && $this->serviceAreaHasActiveTicket($area)) {
                throw new ConflictHttpException('Service area with an active dining ticket cannot be deactivated.');
            }

            $area->is_active = $active;
            $area->updated_by = $actor->id;
            $area->save();

            $this->auditLogger->log(
                $active ? 'SERVICE_AREA_UPDATED' : 'SERVICE_AREA_DEACTIVATED',
                $area,
                $before,
                $this->areaAuditPayload($area)
            );

            return $area;
        });
    }

    public function createDiningTable(ServiceArea $area, array $data, User $actor): DiningTable
    {
        $this->assertBranchAccess($area->branch, $actor);
        $layout = $this->validator->validateLayout($area->layout_metadata);
        $this->validator->validateCapacity((int) $data['capacity']);
        $position = $this->validator->validatePosition($data['position_metadata'] ?? [], $layout);

        return DB::transaction(function () use ($area, $data, $actor, $position) {
            $table = DiningTable::create([
                'branch_id' => $area->branch_id,
                'service_area_id' => $area->id,
                'table_number' => trim((string) $data['table_number']),
                'capacity' => (int) $data['capacity'],
                'operational_state' => $data['operational_state'] ?? DiningTable::STATE_AVAILABLE,
                'position_metadata' => $position,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->auditLogger->log('DINING_TABLE_CREATED', $table, null, $this->tableAuditPayload($table));

            return $table;
        });
    }

    public function updateDiningTable(ServiceArea $area, DiningTable $table, array $data, User $actor): DiningTable
    {
        $this->assertTableBelongsToArea($area, $table);
        $this->assertBranchAccess($area->branch, $actor);
        $before = $this->tableAuditPayload($table);
        $layout = $this->validator->validateLayout($area->layout_metadata);

        return DB::transaction(function () use ($table, $data, $actor, $layout, $before) {
            foreach (['table_number', 'operational_state'] as $field) {
                if (array_key_exists($field, $data)) {
                    $table->{$field} = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                }
            }

            if (array_key_exists('capacity', $data)) {
                $this->validator->validateCapacity((int) $data['capacity']);
                $table->capacity = (int) $data['capacity'];
            }

            if (array_key_exists('position_metadata', $data)) {
                $table->position_metadata = $this->validator->validatePosition($data['position_metadata'], $layout);
            }

            $table->updated_by = $actor->id;
            $table->save();

            $this->auditLogger->log('DINING_TABLE_UPDATED', $table, $before, $this->tableAuditPayload($table));

            return $table;
        });
    }

    public function deleteDiningTable(ServiceArea $area, DiningTable $table, User $actor): void
    {
        $this->assertTableBelongsToArea($area, $table);
        $this->assertBranchAccess($area->branch, $actor);

        if ($this->tableHasHistoricalReference($table)) {
            throw new ConflictHttpException('Referenced dining tables cannot be deleted. Deactivate the table instead.');
        }

        $before = $this->tableAuditPayload($table);

        DB::transaction(function () use ($table, $before) {
            $table->forceDelete();
            $this->auditLogger->log('DINING_TABLE_DELETED', null, $before, null, metadata: [
                'dining_table_id' => $table->id,
            ]);
        });
    }

    public function setDiningTableActivation(ServiceArea $area, DiningTable $table, bool $active, User $actor): DiningTable
    {
        $this->assertTableBelongsToArea($area, $table);
        $this->assertBranchAccess($area->branch, $actor);
        $before = $this->tableAuditPayload($table);

        return DB::transaction(function () use ($area, $table, $active, $actor, $before) {
            if ($active && !$area->is_active) {
                throw new ConflictHttpException('Cannot reactivate a dining table under an inactive service area.');
            }

            if (!$active && $this->tableHasActiveTicket($table)) {
                throw new ConflictHttpException('Dining table with an active ticket cannot be deactivated.');
            }

            $table->is_active = $active;
            $table->updated_by = $actor->id;
            $table->save();

            $this->auditLogger->log(
                $active ? 'DINING_TABLE_UPDATED' : 'DINING_TABLE_DEACTIVATED',
                $table,
                $before,
                $this->tableAuditPayload($table)
            );

            return $table;
        });
    }

    public function saveLayout(ServiceArea $area, array $data, User $actor): ServiceArea
    {
        $this->assertBranchAccess($area->branch, $actor);

        return DB::transaction(function () use ($area, $data, $actor) {
            /** @var ServiceArea $lockedArea */
            $lockedArea = ServiceArea::query()->whereKey($area->id)->lockForUpdate()->firstOrFail();

            if ((int) $data['expected_layout_revision'] !== $lockedArea->layout_revision) {
                throw new ConflictHttpException(json_encode([
                    'code' => 'LAYOUT_REVISION_CONFLICT',
                    'message' => 'The layout was updated by another user.',
                    'current_layout_revision' => $lockedArea->layout_revision,
                ]));
            }

            $beforeRevision = $lockedArea->layout_revision;
            $layout = $this->validator->validateLayout($data['layout_metadata']);
            $tableChanges = [];

            foreach ($data['tables'] ?? [] as $tablePayload) {
                $table = DiningTable::query()
                    ->where('service_area_id', $lockedArea->id)
                    ->where('branch_id', $lockedArea->branch_id)
                    ->whereKey($tablePayload['id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $before = $table->position_metadata;
                $position = $this->validator->validatePosition($tablePayload['position_metadata'] ?? [], $layout, "tables.{$table->id}.position_metadata");
                $table->position_metadata = $position;
                $table->updated_by = $actor->id;
                $table->save();

                $tableChanges[] = [
                    'id' => $table->id,
                    'before' => $before,
                    'after' => $position,
                ];
            }

            $lockedArea->layout_metadata = $layout;
            $lockedArea->layout_revision++;
            $lockedArea->updated_by = $actor->id;
            $lockedArea->save();

            $this->auditLogger->log('DINING_LAYOUT_SAVED', $lockedArea, null, null, metadata: [
                'service_area_id' => $lockedArea->id,
                'previous_layout_revision' => $beforeRevision,
                'layout_revision' => $lockedArea->layout_revision,
                'affected_table_ids' => array_column($tableChanges, 'id'),
                'table_changes' => $tableChanges,
            ]);

            return $lockedArea->fresh(['tables']);
        });
    }

    public function normalizeName(string $name): string
    {
        return Str::of($name)->lower()->squish()->toString();
    }

    public function assertBranchAccess(Branch $branch, User $actor): void
    {
        if (!$actor->canAccessBranch($branch)) {
            abort(404);
        }
    }

    public function resolveBranch(string $branchId, User $actor): Branch
    {
        $branch = Branch::query()->whereKey($branchId)->firstOrFail();
        $this->assertBranchAccess($branch, $actor);

        return $branch;
    }

    private function assertTableBelongsToArea(ServiceArea $area, DiningTable $table): void
    {
        if ($table->service_area_id !== $area->id || $table->branch_id !== $area->branch_id) {
            abort(404);
        }
    }

    private function areaAuditPayload(ServiceArea $area): array
    {
        return [
            'id' => $area->id,
            'branch_id' => $area->branch_id,
            'name' => $area->name,
            'normalized_name' => $area->normalized_name,
            'layout_revision' => $area->layout_revision,
            'is_active' => $area->is_active,
        ];
    }

    private function tableAuditPayload(DiningTable $table): array
    {
        return [
            'id' => $table->id,
            'branch_id' => $table->branch_id,
            'service_area_id' => $table->service_area_id,
            'table_number' => $table->table_number,
            'capacity' => $table->capacity,
            'operational_state' => $table->operational_state,
            'position_metadata' => $table->position_metadata,
            'is_active' => $table->is_active,
        ];
    }

    private function serviceAreaHasActiveTicket(ServiceArea $area): bool
    {
        return $area->tables()
            ->whereHas('ticketMappings', function ($query) {
                $query->whereNull('detached_at')
                    ->where('role', \App\Models\DiningTicketTable::ROLE_PRIMARY)
                    ->whereHas('ticket', fn ($ticketQuery) => $ticketQuery->whereIn('status', \App\Models\DiningTicket::ACTIVE_STATUSES));
            })
            ->exists();
    }

    private function tableHasActiveTicket(DiningTable $table): bool
    {
        return $table->ticketMappings()
            ->whereNull('detached_at')
            ->where('role', \App\Models\DiningTicketTable::ROLE_PRIMARY)
            ->whereHas('ticket', fn ($query) => $query->whereIn('status', \App\Models\DiningTicket::ACTIVE_STATUSES))
            ->exists();
    }

    private function tableHasHistoricalReference(DiningTable $table): bool
    {
        return $table->ticketMappings()->exists();
    }
}
