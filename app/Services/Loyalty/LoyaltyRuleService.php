<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyRule;
use App\Services\TenantContext;

class LoyaltyRuleService
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function activeEarningRule(?string $branchId = null): LoyaltyRule
    {
        return $this->activeRule(LoyaltyRule::TYPE_EARNING, null, $branchId);
    }

    public function activeRedemptionRule(?string $code = null, ?string $branchId = null): LoyaltyRule
    {
        return $this->activeRule(LoyaltyRule::TYPE_REDEMPTION, $code, $branchId);
    }

    public function earningPoints(int $eligibleAmountCentavos, LoyaltyRule $rule): int
    {
        $config = $rule->configuration ?? [];
        $points = max(1, (int) ($config['points_per_currency_unit'] ?? 1));
        $unitCentavos = max(1, (int) ($config['currency_unit_centavos'] ?? 100));

        return intdiv($eligibleAmountCentavos * $points, $unitCentavos);
    }

    public function redemptionBenefitCentavos(int $points, LoyaltyRule $rule, int $maxBenefitCentavos): int
    {
        $config = $rule->configuration ?? [];
        $pointsPerCentavo = max(1, (int) ($config['points_per_centavo'] ?? 1));
        $benefit = intdiv($points, $pointsPerCentavo);

        return min($benefit, max(0, $maxBenefitCentavos));
    }

    private function activeRule(string $type, ?string $code, ?string $branchId): LoyaltyRule
    {
        $tenantId = $this->tenantContext->getTenantId();

        $query = LoyaltyRule::query()
            ->where('tenant_id', $tenantId)
            ->where('rule_type', $type)
            ->where('status', LoyaltyRule::STATUS_ACTIVE)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });

        if ($code) {
            $query->where('code', $code);
        }

        $rule = $query
            ->orderByRaw('branch_id is null')
            ->orderByDesc('priority')
            ->orderByDesc('rule_version')
            ->first();

        if ($rule) {
            return $rule;
        }

        if ($code) {
            throw new \RuntimeException('The requested loyalty rule is not active for this tenant or branch.');
        }

        return $this->createDefaultRule($tenantId, $type);
    }

    private function createDefaultRule(string $tenantId, string $type): LoyaltyRule
    {
        $code = $type === LoyaltyRule::TYPE_EARNING
            ? 'DEFAULT_EARNING_V1'
            : 'POINTS_FOR_DISCOUNT_V1';

        return LoyaltyRule::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'branch_id' => null,
                'code' => $code,
                'rule_version' => 1,
            ],
            [
                'rule_type' => $type,
                'status' => LoyaltyRule::STATUS_ACTIVE,
                'priority' => 0,
                'configuration' => $type === LoyaltyRule::TYPE_EARNING
                    ? ['points_per_currency_unit' => 1, 'currency_unit_centavos' => 100]
                    : ['points_per_centavo' => 1],
            ]
        );
    }
}
