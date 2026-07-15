<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryMovementRecorder
{
    public const SCHEMA_VERSION = 1;

    public const MOVEMENT_TYPES = [
        'stock_in',
        'manual_adjustment',
        'sale_deduction',
        'void_reversal',
        'refund_return',
        'stock_correction',
        'inventory_opening_balance',
        'inventory_migration_baseline',
        'supplier_receiving',
        'supplier_return',
        'ibt_dispatch',
        'ibt_receipt',
    ];

    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    public function record(BranchInventory $inventory, array $data, bool $allowMigrationBaseline = false): InventoryMovement
    {
        return DB::transaction(function () use ($inventory, $data, $allowMigrationBaseline) {
            $this->validateContext($inventory);

            $payload = $this->preparePayload($inventory, $data);

            if ($payload['movement_type'] === 'inventory_migration_baseline' && !$allowMigrationBaseline) {
                throw new \RuntimeException('Migration baseline movements can only be created by the Epic 40 migration backfill.');
            }

            $existing = $this->findExistingSourceEffect($payload);
            if ($existing) {
                $this->assertReplayMatches($existing, $payload);

                return $existing;
            }

            $payload['movement_sequence'] = $payload['movement_sequence']
                ?? $this->nextSequence($payload['tenant_id'], $payload['branch_id']);

            return InventoryMovement::create($payload);
        });
    }

    protected function validateContext(BranchInventory $inventory): void
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException('Cannot record inventory movement without active TenantContext.');
        }

        if ($this->tenantContext->hasTenant() && $inventory->tenant_id !== $this->tenantContext->getTenantId()) {
            throw new \RuntimeException('Cannot record movement for inventory belonging to a different tenant.');
        }

        if ($this->branchContext->hasBranch() && $inventory->branch_id !== $this->branchContext->getBranchId()) {
            throw new \RuntimeException('Cannot record movement for inventory outside the active branch context.');
        }
    }

    protected function preparePayload(BranchInventory $inventory, array $data): array
    {
        $validator = Validator::make($data, [
            'movement_type' => ['required', 'string', Rule::in(self::MOVEMENT_TYPES)],
            'quantity_change' => ['required', 'numeric'],
            'quantity_before' => ['required', 'numeric'],
            'quantity_after' => ['required', 'numeric'],
            'movement_uuid' => ['sometimes', 'nullable', 'string'],
            'movement_schema_version' => ['sometimes', 'nullable', 'integer'],
            'movement_sequence' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'base_unit_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source_unit_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source_quantity' => ['sometimes', 'nullable', 'numeric'],
            'conversion_snapshot' => ['sometimes', 'nullable', 'array'],
            'business_date' => ['sometimes', 'nullable', 'date'],
            'posted_at' => ['sometimes', 'nullable', 'date'],
            'source_type' => ['sometimes', 'nullable', 'string'],
            'source_id' => ['sometimes', 'nullable', 'string'],
            'reference_number' => ['sometimes', 'nullable', 'string'],
            'source_reference' => ['sometimes', 'nullable', 'string'],
            'source_effect_key' => ['sometimes', 'nullable', 'string', 'max:160'],
            'original_movement_id' => ['sometimes', 'nullable', 'exists:inventory_movements,id'],
            'user_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'reason_code' => ['sometimes', 'nullable', 'string'],
            'remarks' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $quantityBefore = (float) $data['quantity_before'];
        $quantityChange = (float) $data['quantity_change'];
        $quantityAfter = (float) $data['quantity_after'];

        if (number_format($quantityBefore + $quantityChange, 4, '.', '') !== number_format($quantityAfter, 4, '.', '')) {
            throw new \RuntimeException('Inventory consistency error: quantity_after must equal quantity_before + quantity_change.');
        }

        $sourceReference = $data['source_reference'] ?? $data['reference_number'] ?? null;

        return array_merge($data, [
            'movement_uuid' => $data['movement_uuid'] ?? $this->newMovementUuid(),
            'movement_schema_version' => $data['movement_schema_version'] ?? self::SCHEMA_VERSION,
            'tenant_id' => $inventory->tenant_id,
            'branch_id' => $inventory->branch_id,
            'product_id' => $inventory->product_id,
            'branch_inventory_id' => $inventory->id,
            'quantity_change' => number_format($quantityChange, 4, '.', ''),
            'quantity_before' => number_format($quantityBefore, 4, '.', ''),
            'quantity_after' => number_format($quantityAfter, 4, '.', ''),
            'business_date' => $data['business_date'] ?? now()->toDateString(),
            'posted_at' => $data['posted_at'] ?? now(),
            'source_reference' => $sourceReference,
            'reference_number' => $data['reference_number'] ?? $sourceReference,
            'source_effect_key' => $data['source_effect_key'] ?? $this->defaultSourceEffectKey($inventory, $data),
        ]);
    }

    protected function findExistingSourceEffect(array $payload): ?InventoryMovement
    {
        if (empty($payload['source_type']) || empty($payload['source_id']) || empty($payload['source_effect_key'])) {
            return null;
        }

        return InventoryMovement::where('tenant_id', $payload['tenant_id'])
            ->where('branch_id', $payload['branch_id'])
            ->where('source_type', $payload['source_type'])
            ->where('source_id', $payload['source_id'])
            ->where('source_effect_key', $payload['source_effect_key'])
            ->first();
    }

    protected function assertReplayMatches(InventoryMovement $existing, array $payload): void
    {
        $checks = [
            'movement_type',
            'product_id',
            'quantity_change',
            'quantity_before',
            'quantity_after',
            'original_movement_id',
        ];

        foreach ($checks as $field) {
            $existingValue = $existing->{$field};
            $payloadValue = $payload[$field] ?? null;

            if (in_array($field, ['quantity_change', 'quantity_before', 'quantity_after'], true)) {
                $existingValue = number_format((float) $existingValue, 4, '.', '');
                $payloadValue = number_format((float) $payloadValue, 4, '.', '');
            }

            if ((string) $existingValue !== (string) $payloadValue) {
                throw new \RuntimeException('Inventory movement replay drift detected for source effect.');
            }
        }
    }

    protected function nextSequence(string $tenantId, string $branchId): int
    {
        $sequence = DB::table('inventory_movement_sequences')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if (!$sequence) {
            DB::table('inventory_movement_sequences')->insert([
                'id' => (string) Str::orderedUuid(),
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'last_sequence' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('inventory_movement_sequences')
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();
        }

        $next = ((int) $sequence->last_sequence) + 1;

        DB::table('inventory_movement_sequences')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->update([
                'last_sequence' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }

    protected function defaultSourceEffectKey(BranchInventory $inventory, array $data): ?string
    {
        if (empty($data['source_type']) || empty($data['source_id'])) {
            return null;
        }

        return implode(':', [
            $this->slugSourceType((string) $data['source_type']),
            $data['source_id'],
            'product',
            $inventory->product_id,
        ]);
    }

    protected function slugSourceType(string $sourceType): string
    {
        return str_replace('\\', '_', strtolower($sourceType));
    }

    protected function newMovementUuid(): string
    {
        if (method_exists(Str::class, 'uuid7')) {
            return (string) Str::uuid7();
        }

        return (string) Str::orderedUuid();
    }
}
