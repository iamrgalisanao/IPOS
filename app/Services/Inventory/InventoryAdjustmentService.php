<?php

namespace App\Services\Inventory;

use App\Models\BranchInventory;
use App\Models\InventoryAdjustmentReason;
use App\Models\InventoryMovement;
use App\Services\AuditLogger;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected AuditLogger $auditLogger,
        protected InventoryMovementRecorder $movementRecorder,
        protected InventoryAdjustmentApprovalService $approvalService,
    ) {}

    public function preview(array $command): array
    {
        [$inventory, $reason, $quantityChange] = $this->resolveCommand($command, lock: false);
        $approval = $this->approvalService->preview($inventory, $reason, $quantityChange);

        return [
            'approval_required' => $approval['approval_required'],
            'approval_basis' => $approval['approval_basis'],
            'reason_name' => $reason->name,
            'reason_category' => $reason->reason_category,
            'reason_code' => $reason->code,
            'reason_version' => $reason->reason_version,
            'approval_rule_version' => $approval['approval_rule']?->rule_version,
            'direction_policy' => $reason->direction_policy,
            'quantity_before' => $this->decimal($inventory->current_stock),
            'requested_quantity' => $this->decimal(abs($quantityChange)),
            'signed_delta' => $this->decimal($quantityChange),
            'inventory_revision' => $inventory->inventory_revision ?? 1,
            'preview_inventory_revision' => $inventory->inventory_revision ?? 1,
            'preview_reason_version' => $reason->reason_version,
            'preview_rule_version' => $approval['approval_rule']?->rule_version,
            'preview_generated_at' => now()->toISOString(),
            'projected_quantity_after' => $this->decimal((float) $inventory->current_stock + $quantityChange),
        ];
    }

    public function adjust(array $command): array
    {
        $actor = Auth::user();
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException('Cannot adjust stock without active TenantContext.');
        }

        $this->assertInventoryContext($command['branch_inventory_id'] ?? null);

        if ($actor && (!$actor->hasPermission('inventory.adjustment.create') && !$actor->hasPermission('manage_branch_inventory'))) {
            throw new \RuntimeException('User does not have permission to manage branch inventory.');
        }

        $clientRequestUuid = (string) ($command['client_request_uuid'] ?? '');
        if (!Str::isUuid($clientRequestUuid)) {
            throw ValidationException::withMessages(['client_request_uuid' => ['A valid client_request_uuid is required.']]);
        }

        $existing = $this->findExistingMovement($clientRequestUuid, (string) $command['product_id']);
        if ($existing) {
            $this->assertReplayMatches($existing, $command);
            if ($actor) {
                $this->auditLogger->log('inventory_adjustment_replay_returned', $existing, metadata: [
                    'client_request_uuid' => $clientRequestUuid,
                ]);
            }

            return ['status' => 'replayed', 'movement' => $existing];
        }

        return DB::transaction(function () use ($command, $actor, $clientRequestUuid) {
            [$inventory, $reason, $quantityChange] = $this->resolveCommand($command, lock: true);
            $quantityBefore = (float) $inventory->current_stock;
            $quantityAfter = round($quantityBefore + $quantityChange, 4);

            if ($quantityAfter < 0) {
                throw new \RuntimeException('Stock adjustment would result in negative inventory, which is blocked.');
            }

            $this->assertOpeningBalanceAllowed($inventory, $reason);

            $preview = $this->approvalService->preview($inventory, $reason, $quantityChange);
            $approval = null;
            if ($preview['approval_required']) {
                if (empty($command['manager_approval_id'])) {
                    throw new \RuntimeException('Manager approval is required for this inventory adjustment.');
                }
                $approval = $this->approvalService->consume($command['manager_approval_id'], $actor, $inventory, $reason, array_merge($command, [
                    'quantity_change' => $quantityChange,
                    'client_request_uuid' => $clientRequestUuid,
                ]), $preview);
            }

            $revisionBefore = (int) ($inventory->inventory_revision ?? 1);
            $revisionAfter = $revisionBefore + 1;
            $metadata = $this->metadata($inventory, $reason, $command, $quantityChange, $quantityBefore, $quantityAfter, $revisionBefore, $revisionAfter, $preview, $approval?->id);

            $inventory->update([
                'current_stock' => $this->decimal($quantityAfter),
                'inventory_revision' => $revisionAfter,
            ]);

            $movement = $this->movementRecorder->record($inventory->fresh(), [
                'movement_type' => $reason->is_opening_balance ? 'inventory_opening_balance' : 'manual_adjustment',
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'source_type' => $reason->is_opening_balance ? 'inventory_opening_balance' : 'manual_adjustment',
                'source_id' => $clientRequestUuid,
                'source_reference' => "inventory-adjustment:{$clientRequestUuid}",
                'source_effect_key' => $this->sourceEffectKey($clientRequestUuid, $inventory->product_id),
                'user_id' => $actor?->id,
                'reason_code' => $reason->code,
                'remarks' => $metadata['remarks_normalized'],
                'metadata' => $metadata,
            ]);

            if ($actor) {
                $this->auditLogger->log('inventory_adjustment_posted', $movement, metadata: [
                    'branch_inventory_id' => $inventory->id,
                    'product_id' => $inventory->product_id,
                    'movement_id' => $movement->id,
                    'movement_sequence' => $movement->movement_sequence,
                    'reason_code' => $reason->code,
                    'reason_version' => $reason->reason_version,
                    'quantity_before' => $this->decimal($quantityBefore),
                    'quantity_change' => $this->decimal($quantityChange),
                    'quantity_after' => $this->decimal($quantityAfter),
                    'inventory_revision_before' => $revisionBefore,
                    'inventory_revision_after' => $revisionAfter,
                    'approval_id' => $approval?->id,
                    'approval_basis' => $preview['approval_basis'],
                    'client_request_uuid' => $clientRequestUuid,
                ]);
            }

            return ['status' => 'posted', 'movement' => $movement];
        });
    }

    protected function resolveCommand(array $command, bool $lock): array
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException('Cannot adjust stock without active TenantContext.');
        }

        $this->assertInventoryContext($command['branch_inventory_id'] ?? null);

        $inventoryQuery = BranchInventory::where('tenant_id', $this->tenantContext->getTenantId())
            ->where('id', $command['branch_inventory_id'] ?? null);
        if ($this->branchContext->hasBranch()) {
            $inventoryQuery->where('branch_id', $this->branchContext->getBranchId());
        }
        if ($lock) {
            $inventoryQuery->lockForUpdate();
        }
        $inventory = $inventoryQuery->firstOrFail();

        $reason = InventoryAdjustmentReason::where('tenant_id', $inventory->tenant_id)
            ->where('code', strtoupper(trim((string) ($command['reason_code'] ?? ''))))
            ->where('is_active', true)
            ->first();
        if (!$reason && ($command['allow_legacy_reason'] ?? false)) {
            $reason = $this->legacyReason($inventory, $command);
        }
        if (!$reason) {
            throw new \RuntimeException('Invalid or inactive inventory adjustment reason code.');
        }

        if ($reason->requires_notes && $this->normalizeRemarks($command['remarks'] ?? null) === null) {
            throw ValidationException::withMessages(['remarks' => ['Remarks are required for this adjustment reason.']]);
        }

        $quantityChange = $this->deriveQuantityChange($reason, $command);
        if (abs($quantityChange) < 0.0001) {
            throw ValidationException::withMessages(['quantity_change' => ['Adjustment quantity cannot be zero.']]);
        }

        return [$inventory, $reason, $quantityChange];
    }

    protected function assertInventoryContext(?string $branchInventoryId): void
    {
        $candidateInventory = BranchInventory::withoutGlobalScopes()->where('id', $branchInventoryId)->first();
        if ($candidateInventory && $candidateInventory->tenant_id !== $this->tenantContext->getTenantId()) {
            throw new \RuntimeException('Cannot adjust stock for inventory belonging to a different tenant.');
        }
        if ($candidateInventory && $this->branchContext->hasBranch() && $candidateInventory->branch_id !== $this->branchContext->getBranchId()) {
            throw new \RuntimeException('Cannot adjust stock for inventory outside the active branch context.');
        }
    }

    protected function deriveQuantityChange(InventoryAdjustmentReason $reason, array $command): float
    {
        if (isset($command['requested_quantity'])) {
            $quantity = abs((float) $command['requested_quantity']);
            $direction = $command['requested_direction'] ?? null;
            if ($reason->isIncrease()) {
                return $quantity;
            }
            if ($reason->isDecrease()) {
                return -$quantity;
            }
            if ($reason->direction_policy === InventoryAdjustmentReason::DIRECTION_OPENING_BALANCE) {
                return $quantity;
            }
            return $direction === 'decrease' ? -$quantity : $quantity;
        }

        $quantityChange = (float) ($command['quantity_change'] ?? 0);
        if ($reason->isIncrease() && $quantityChange <= 0) {
            throw new \RuntimeException('Selected reason only allows stock increases.');
        }
        if ($reason->isDecrease() && $quantityChange >= 0) {
            throw new \RuntimeException('Selected reason only allows stock decreases.');
        }
        if ($reason->direction_policy === InventoryAdjustmentReason::DIRECTION_OPENING_BALANCE && $quantityChange < 0) {
            throw new \RuntimeException('Opening balance cannot decrease stock.');
        }

        return $quantityChange;
    }

    protected function assertOpeningBalanceAllowed(BranchInventory $inventory, InventoryAdjustmentReason $reason): void
    {
        if (!$reason->is_opening_balance) {
            return;
        }

        $hasMovement = InventoryMovement::where('tenant_id', $inventory->tenant_id)
            ->where('branch_id', $inventory->branch_id)
            ->where('product_id', $inventory->product_id)
            ->exists();
        if ($hasMovement) {
            $this->auditLogger->log('inventory_adjustment_opening_balance_blocked', $inventory, metadata: [
                'product_id' => $inventory->product_id,
            ]);
            throw new \RuntimeException('Opening balance is blocked after committed inventory movement exists.');
        }
    }

    protected function metadata(BranchInventory $inventory, InventoryAdjustmentReason $reason, array $command, float $quantityChange, float $quantityBefore, float $quantityAfter, int $revisionBefore, int $revisionAfter, array $preview, ?string $approvalId): array
    {
        $remarks = $this->normalizeRemarks($command['remarks'] ?? null);
        $metadata = [
            'schema' => 'manual_adjustment_v1',
            'client_request_uuid' => $command['client_request_uuid'],
            'adjustment_reason_id' => $reason->id,
            'reason_uuid' => $reason->reason_uuid,
            'reason_code' => $reason->code,
            'reason_name' => $reason->name,
            'reason_category' => $reason->reason_category,
            'reason_version' => $reason->reason_version,
            'reason_schema_version' => $reason->reason_schema_version,
            'direction_policy' => $reason->direction_policy,
            'requires_notes' => (bool) $reason->requires_notes,
            'evidence_required' => (bool) $reason->evidence_required,
            'approval_required' => (bool) $preview['approval_required'],
            'approval_id' => $approvalId,
            'approval_basis' => $preview['approval_basis'],
            'approval_rule_id' => $preview['approval_rule']?->id,
            'approval_rule_version' => $preview['approval_rule']?->rule_version,
            'requested_quantity_input' => $this->decimal($command['requested_quantity'] ?? abs($quantityChange)),
            'requested_direction' => $command['requested_direction'] ?? ($quantityChange < 0 ? 'decrease' : 'increase'),
            'requested_quantity_change' => $this->decimal($quantityChange),
            'quantity_before' => $this->decimal($quantityBefore),
            'quantity_after' => $this->decimal($quantityAfter),
            'inventory_revision_before' => $revisionBefore,
            'inventory_revision_after' => $revisionAfter,
            'performed_by' => Auth::id(),
            'performed_at' => now()->toISOString(),
            'remarks_normalized' => $remarks,
        ];
        $metadata['command_fingerprint'] = $this->fingerprint($inventory, $reason, $quantityChange, $remarks, $command['client_request_uuid']);

        return $metadata;
    }

    protected function findExistingMovement(string $clientRequestUuid, ?string $productId): ?InventoryMovement
    {
        return InventoryMovement::where('tenant_id', $this->tenantContext->getTenantId())
            ->whereIn('source_type', ['manual_adjustment', 'inventory_opening_balance'])
            ->where('source_id', $clientRequestUuid)
            ->where('source_effect_key', $this->sourceEffectKey($clientRequestUuid, $productId))
            ->first();
    }

    protected function legacyReason(BranchInventory $inventory, array $command): InventoryAdjustmentReason
    {
        $code = strtoupper(trim((string) ($command['reason_code'] ?? 'LEGACY_ADJUSTMENT')));
        $quantityChange = (float) ($command['quantity_change'] ?? 0);

        return InventoryAdjustmentReason::firstOrCreate([
            'tenant_id' => $inventory->tenant_id,
            'code' => $code,
            'active_slot' => 'active',
        ], [
            'reason_uuid' => (string) Str::orderedUuid(),
            'name' => str_replace('_', ' ', $code),
            'reason_category' => $quantityChange >= 0
                ? InventoryAdjustmentReason::CATEGORY_FOUND_STOCK
                : InventoryAdjustmentReason::CATEGORY_DATA_CORRECTION,
            'direction_policy' => $quantityChange >= 0
                ? InventoryAdjustmentReason::DIRECTION_INCREASE
                : InventoryAdjustmentReason::DIRECTION_DECREASE,
            'requires_notes' => false,
            'evidence_required' => false,
            'is_opening_balance' => false,
            'is_active' => true,
            'active_slot' => 'active',
            'reason_version' => 1,
            'reason_schema_version' => 1,
        ]);
    }

    protected function assertReplayMatches(InventoryMovement $movement, array $command): void
    {
        $metadata = $movement->metadata ?? [];
        $fingerprint = $metadata['command_fingerprint'] ?? null;
        if (!$fingerprint) {
            throw new \RuntimeException('Inventory adjustment replay drift detected.');
        }

        $requestedReasonCode = strtoupper(trim((string) ($command['reason_code'] ?? '')));
        $postedReasonCode = strtoupper(trim((string) ($metadata['reason_code'] ?? '')));
        if ($requestedReasonCode !== $postedReasonCode) {
            if (Auth::user()) {
                $this->auditLogger->log('inventory_adjustment_replay_drift_rejected', $movement, metadata: [
                    'client_request_uuid' => $command['client_request_uuid'] ?? null,
                ]);
            }
            throw new \RuntimeException('Inventory adjustment replay drift detected.');
        }

        $reason = InventoryAdjustmentReason::where('tenant_id', $movement->tenant_id)
            ->where('reason_uuid', $metadata['reason_uuid'] ?? null)
            ->where('reason_version', $metadata['reason_version'] ?? null)
            ->first();
        if (!$reason) {
            $reason = new InventoryAdjustmentReason([
                'reason_uuid' => $metadata['reason_uuid'] ?? null,
                'code' => $metadata['reason_code'] ?? null,
                'reason_version' => $metadata['reason_version'] ?? 1,
            ]);
        }

        $candidate = hash('sha256', json_encode([
            'tenant_id' => $movement->tenant_id,
            'branch_id' => $movement->branch_id,
            'branch_inventory_id' => $movement->branch_inventory_id,
            'product_id' => $movement->product_id,
            'quantity_change' => $this->decimal($command['quantity_change'] ?? $movement->quantity_change),
            'reason_uuid' => $metadata['reason_uuid'] ?? $reason->reason_uuid,
            'reason_version' => (int) ($metadata['reason_version'] ?? $reason->reason_version),
            'remarks' => $this->normalizeRemarks($command['remarks'] ?? null),
            'client_request_uuid' => $command['client_request_uuid'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        if (!hash_equals($fingerprint, $candidate)) {
            if (Auth::user()) {
                $this->auditLogger->log('inventory_adjustment_replay_drift_rejected', $movement, metadata: [
                    'client_request_uuid' => $command['client_request_uuid'] ?? null,
                ]);
            }
            throw new \RuntimeException('Inventory adjustment replay drift detected.');
        }
    }

    protected function fingerprint(BranchInventory $inventory, InventoryAdjustmentReason $reason, float $quantityChange, ?string $remarks, string $clientRequestUuid): string
    {
        return hash('sha256', json_encode([
            'tenant_id' => $inventory->tenant_id,
            'branch_id' => $inventory->branch_id,
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'quantity_change' => $this->decimal($quantityChange),
            'reason_uuid' => $reason->reason_uuid,
            'reason_version' => (int) $reason->reason_version,
            'remarks' => $remarks,
            'client_request_uuid' => $clientRequestUuid,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function sourceEffectKey(string $clientRequestUuid, ?string $productId): string
    {
        return "manual_adjustment:{$clientRequestUuid}:product:{$productId}";
    }

    protected function normalizeRemarks(?string $remarks): ?string
    {
        if ($remarks === null) {
            return null;
        }

        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $remarks));

        return $normalized === '' ? null : $normalized;
    }

    protected function decimal(float|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }
}
