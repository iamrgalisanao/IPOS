<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryAdjustmentApprovalRule;
use App\Models\InventoryAdjustmentReason;
use App\Models\ManagerApproval;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InventoryAdjustmentApprovalService
{
    public const CONTEXT_VERSION = 'inventory-adjustment-approval-v1';

    public function __construct(protected AuditLogger $auditLogger) {}

    public function preview(BranchInventory $inventory, InventoryAdjustmentReason $reason, float $quantityChange): array
    {
        $rules = $this->activeRules($inventory, $reason);
        $basis = [];
        $matchedRule = null;

        if ($reason->isBidirectional()) {
            $basis[] = 'reason_required';
        }

        foreach ($rules as $rule) {
            $ruleBasis = $this->ruleBasis($rule, $inventory, $quantityChange);
            if ($ruleBasis !== []) {
                $basis = array_values(array_unique(array_merge($basis, $ruleBasis)));
                $matchedRule ??= $rule;
            }
        }

        return [
            'approval_required' => $basis !== [],
            'approval_basis' => $basis,
            'approval_rule' => $matchedRule,
            'approval_rule_version' => $matchedRule?->rule_version,
            'required_permission' => $matchedRule?->required_permission ?? 'inventory.adjustment.approve',
            'requires_distinct_approver' => $matchedRule?->requires_distinct_approver ?? true,
        ];
    }

    public function issue(User $requester, BranchInventory $inventory, InventoryAdjustmentReason $reason, array $command, string $managerEmail, string $managerPassword): ManagerApproval
    {
        $preview = $this->preview($inventory, $reason, (float) $command['quantity_change']);
        if (!$preview['approval_required']) {
            throw new \RuntimeException('Manager approval is not required for this inventory adjustment.');
        }

        $manager = User::where('tenant_id', $inventory->tenant_id)
            ->where('email', $managerEmail)
            ->where('status', 'active')
            ->first();
        $branch = Branch::findOrFail($inventory->branch_id);

        $valid = $manager
            && Hash::check($managerPassword, $manager->password)
            && (!$preview['requires_distinct_approver'] || $manager->id !== $requester->id)
            && $manager->hasPermission($preview['required_permission'])
            && $manager->canAccessBranch($branch);

        if (!$valid) {
            $this->auditLogger->log('inventory_adjustment_approval_rejected', null, metadata: [
                'requesting_user_id' => $requester->id,
                'branch_inventory_id' => $inventory->id,
                'reason_code' => $reason->code,
                'reason' => 'invalid_manager_authorization',
            ]);
            throw new \RuntimeException('Inventory adjustment approval could not be verified.');
        }

        $context = $this->buildContext($requester, $inventory, $reason, $command, $preview);
        $approval = ManagerApproval::create([
            'id' => (string) Str::orderedUuid(),
            'tenant_id' => $inventory->tenant_id,
            'branch_id' => $inventory->branch_id,
            'user_id' => $manager->id,
            'requesting_user_id' => $requester->id,
            'approvable_type' => 'InventoryManualAdjustment',
            'approvable_id' => (string) Str::orderedUuid(),
            'action' => 'approve',
            'metadata' => [
                'approval_basis' => $preview['approval_basis'],
                'reason_id' => $reason->id,
                'reason_uuid' => $reason->reason_uuid,
                'reason_version' => $reason->reason_version,
                'approval_rule_id' => $preview['approval_rule']?->id,
                'approval_rule_version' => $preview['approval_rule']?->rule_version,
            ],
            'approval_rule_id' => $preview['approval_rule']?->id,
            'context_version' => self::CONTEXT_VERSION,
            'context_hmac' => $this->hmac($context),
            'status' => 'issued',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->auditLogger->log('inventory_adjustment_approval_issued', $approval, metadata: [
            'manager_id' => $manager->id,
            'requesting_user_id' => $requester->id,
            'branch_inventory_id' => $inventory->id,
            'reason_code' => $reason->code,
        ]);

        return $approval;
    }

    public function consume(string $approvalId, User $requester, BranchInventory $inventory, InventoryAdjustmentReason $reason, array $command, array $preview): ManagerApproval
    {
        $approval = ManagerApproval::where('id', $approvalId)->lockForUpdate()->first();
        $context = $this->buildContext($requester, $inventory, $reason, $command, $preview);

        $valid = $approval
            && $approval->tenant_id === $inventory->tenant_id
            && $approval->branch_id === $inventory->branch_id
            && $approval->requesting_user_id === $requester->id
            && $approval->approvable_type === 'InventoryManualAdjustment'
            && $approval->status === 'issued'
            && $approval->expires_at?->isFuture()
            && hash_equals((string) $approval->context_hmac, $this->hmac($context));

        if (!$valid) {
            throw new \RuntimeException('Inventory adjustment approval is invalid, expired, already used, or does not match this adjustment.');
        }

        $approval->forceFill([
            'status' => 'consumed',
            'consumed_at' => now(),
            'metadata' => array_merge($approval->metadata ?? [], [
                'consumed_for_client_request_uuid' => $command['client_request_uuid'],
            ]),
        ])->save();

        $this->auditLogger->log('inventory_adjustment_approval_consumed', $approval, metadata: [
            'branch_inventory_id' => $inventory->id,
            'reason_code' => $reason->code,
        ]);

        return $approval;
    }

    public function buildContext(User $requester, BranchInventory $inventory, InventoryAdjustmentReason $reason, array $command, array $preview): array
    {
        return [
            'version' => self::CONTEXT_VERSION,
            'tenant_id' => $inventory->tenant_id,
            'branch_id' => $inventory->branch_id,
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'requesting_user_id' => $requester->id,
            'reason_uuid' => $reason->reason_uuid,
            'reason_code' => $reason->code,
            'reason_version' => $reason->reason_version,
            'reason_schema_version' => $reason->reason_schema_version,
            'direction_policy' => $reason->direction_policy,
            'quantity_change' => $this->decimal($command['quantity_change']),
            'quantity_before' => $this->decimal($inventory->current_stock),
            'projected_quantity_after' => $this->decimal((float) $inventory->current_stock + (float) $command['quantity_change']),
            'approval_basis' => $preview['approval_basis'],
            'approval_rule_id' => $preview['approval_rule']?->id,
            'approval_rule_version' => $preview['approval_rule']?->rule_version,
            'client_request_uuid' => $command['client_request_uuid'],
        ];
    }

    protected function activeRules(BranchInventory $inventory, InventoryAdjustmentReason $reason): Collection
    {
        return InventoryAdjustmentApprovalRule::where('tenant_id', $inventory->tenant_id)
            ->where('is_active', true)
            ->where(function ($query) use ($inventory) {
                $query->whereNull('branch_id')->orWhere('branch_id', $inventory->branch_id);
            })
            ->where(function ($query) use ($reason) {
                $query->whereNull('reason_id')->orWhere('reason_id', $reason->id);
            })
            ->orderBy('priority')
            ->get();
    }

    protected function ruleBasis(InventoryAdjustmentApprovalRule $rule, BranchInventory $inventory, float $quantityChange): array
    {
        $basis = [];
        $absoluteChange = abs($quantityChange);

        if ($rule->minimum_absolute_quantity !== null && $absoluteChange >= (float) $rule->minimum_absolute_quantity) {
            $basis[] = 'quantity_threshold';
        }

        if ($rule->minimum_percentage_of_stock !== null) {
            $before = abs((float) $inventory->current_stock);
            $percentage = $before <= 0.0 ? ($absoluteChange > 0.0 ? 100.0 : 0.0) : ($absoluteChange / $before) * 100;
            if ($percentage >= (float) $rule->minimum_percentage_of_stock) {
                $basis[] = 'percentage_threshold';
            }
        }

        return $basis;
    }

    protected function hmac(array $context): string
    {
        return hash_hmac('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), (string) config('app.key'));
    }

    protected function decimal(float|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }
}
