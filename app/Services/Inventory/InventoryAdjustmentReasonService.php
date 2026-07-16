<?php

namespace App\Services\Inventory;

use App\Models\InventoryAdjustmentReason;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentReasonService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected AuditLogger $auditLogger,
    ) {}

    public function create(array $data): InventoryAdjustmentReason
    {
        $this->validateReasonData($data);

        $reason = InventoryAdjustmentReason::create([
            'reason_uuid' => (string) Str::orderedUuid(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => $data['description'] ?? null,
            'reason_category' => $data['reason_category'],
            'direction_policy' => $data['direction_policy'],
            'requires_notes' => (bool) ($data['requires_notes'] ?? false),
            'evidence_required' => (bool) ($data['evidence_required'] ?? false),
            'is_opening_balance' => (bool) ($data['is_opening_balance'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'active_slot' => ($data['is_active'] ?? true) ? 'active' : null,
            'reason_version' => 1,
            'reason_schema_version' => 1,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->auditLogger->log('inventory_adjustment_reason_created', $reason, afterValues: $reason->toArray());

        return $reason;
    }

    public function replace(InventoryAdjustmentReason $reason, array $data): InventoryAdjustmentReason
    {
        abort_unless($reason->tenant_id === $this->tenantContext->getTenantId(), 403);
        if (!$reason->is_active) {
            throw new \RuntimeException('Inactive historical adjustment reason versions cannot be edited.');
        }

        $merged = array_merge($reason->only([
            'code',
            'name',
            'description',
            'reason_category',
            'direction_policy',
            'requires_notes',
            'evidence_required',
            'is_opening_balance',
            'sort_order',
        ]), $data);
        $merged['is_active'] = true;

        $this->validateReasonData($merged, $reason);

        $before = $reason->toArray();
        $newReason = DB::transaction(function () use ($reason, $merged) {
            $reason->update(['is_active' => false, 'active_slot' => null, 'updated_by' => Auth::id()]);

            return InventoryAdjustmentReason::create([
                'reason_uuid' => $reason->reason_uuid,
                'tenant_id' => $reason->tenant_id,
                'code' => strtoupper(trim((string) $merged['code'])),
                'name' => trim((string) $merged['name']),
                'description' => $merged['description'] ?? null,
                'reason_category' => $merged['reason_category'],
                'direction_policy' => $merged['direction_policy'],
                'requires_notes' => (bool) ($merged['requires_notes'] ?? false),
                'evidence_required' => (bool) ($merged['evidence_required'] ?? false),
                'is_opening_balance' => (bool) ($merged['is_opening_balance'] ?? false),
                'is_active' => (bool) ($merged['is_active'] ?? true),
                'active_slot' => ($merged['is_active'] ?? true) ? 'active' : null,
                'reason_version' => ((int) $reason->reason_version) + 1,
                'reason_schema_version' => 1,
                'supersedes_reason_id' => $reason->id,
                'sort_order' => (int) ($merged['sort_order'] ?? 0),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
        });

        $this->auditLogger->log('inventory_adjustment_reason_updated', $newReason, beforeValues: $before, afterValues: $newReason->toArray());

        return $newReason;
    }

    public function deactivate(InventoryAdjustmentReason $reason): void
    {
        abort_unless($reason->tenant_id === $this->tenantContext->getTenantId(), 403);

        $before = ['is_active' => (bool) $reason->is_active];
        $reason->update(['is_active' => false, 'active_slot' => null, 'updated_by' => Auth::id()]);

        $this->auditLogger->log('inventory_adjustment_reason_deactivated', $reason, beforeValues: $before, afterValues: ['is_active' => false]);
    }

    public function validateReasonData(array $data, ?InventoryAdjustmentReason $existing = null): void
    {
        $category = (string) ($data['reason_category'] ?? '');
        $direction = (string) ($data['direction_policy'] ?? '');

        if (in_array($category, InventoryAdjustmentReason::RESERVED_CATEGORIES, true)) {
            throw ValidationException::withMessages([
                'reason_category' => ['Manual adjustments cannot use reserved workflow categories.'],
            ]);
        }

        if (!in_array($category, InventoryAdjustmentReason::ALLOWED_CATEGORIES, true)) {
            throw ValidationException::withMessages(['reason_category' => ['Invalid adjustment reason category.']]);
        }

        if (!in_array($direction, InventoryAdjustmentReason::ALLOWED_DIRECTIONS, true)) {
            throw ValidationException::withMessages(['direction_policy' => ['Invalid adjustment direction policy.']]);
        }

        if (($data['is_opening_balance'] ?? false) && (
            $direction !== InventoryAdjustmentReason::DIRECTION_OPENING_BALANCE
            || $category !== InventoryAdjustmentReason::CATEGORY_OPENING_BALANCE
        )) {
            throw ValidationException::withMessages([
                'is_opening_balance' => ['Opening balance reasons must use opening balance category and direction.'],
            ]);
        }

        if ($direction === InventoryAdjustmentReason::DIRECTION_BIDIRECTIONAL && !($data['requires_notes'] ?? false)) {
            throw ValidationException::withMessages([
                'requires_notes' => ['Bidirectional reasons must require notes.'],
            ]);
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $duplicate = InventoryAdjustmentReason::where('tenant_id', $this->tenantContext->getTenantId())
            ->where('code', $code)
            ->when($existing, fn ($query) => $query->where('reason_uuid', '!=', $existing->reason_uuid))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['code' => ['This adjustment reason code already exists.']]);
        }
    }
}
