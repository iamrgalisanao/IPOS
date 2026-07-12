<?php

namespace App\Services\POS;

use App\Models\ApprovalRule;
use App\Models\DiscountType;

class ApprovalRuleResolver
{
    public function resolve(string $tenantId, string $branchId, DiscountType $discountType): array
    {
        $branchRule = ApprovalRule::where('tenant_id', $tenantId)
            ->where('scope_key', 'branch:' . $branchId)
            ->where('action', ApprovalRule::ACTION_STATUTORY_DISCOUNT)
            ->first();
        $tenantRule = ApprovalRule::where('tenant_id', $tenantId)
            ->where('scope_key', 'tenant')
            ->where('action', ApprovalRule::ACTION_STATUTORY_DISCOUNT)
            ->first();
        $rule = $branchRule ?: $tenantRule;
        $configuredRequired = (bool) $tenantRule?->always_require_approval
            || (bool) $branchRule?->always_require_approval;
        $ruleVersion = hash('sha256', json_encode([
            'discount_type_minimum' => (bool) $discountType->requires_approval,
            'tenant_rule' => $tenantRule ? [$tenantRule->id, $tenantRule->updated_at?->toISOString()] : null,
            'branch_rule' => $branchRule ? [$branchRule->id, $branchRule->updated_at?->toISOString()] : null,
        ]));

        return [
            'required' => (bool) $discountType->requires_approval || $configuredRequired,
            'rule_id' => $rule?->id,
            'rule_version' => $ruleVersion,
            'source' => $branchRule ? 'branch' : ($tenantRule ? 'tenant' : 'discount_type'),
        ];
    }
}
