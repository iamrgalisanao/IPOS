<?php

namespace App\Services\Inventory;

use App\Models\UnitConversion;
use App\Services\TenantContext;

class UnitConversionResolver
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    public function convert(float $quantity, string $fromUnit, string $toUnit, ?string $productId = null, bool $strict = false): array
    {
        return $this->resolve($quantity, $fromUnit, $toUnit, $productId, $strict);
    }

    public function resolve(float $quantity, string $fromUnit, string $toUnit, ?string $productId = null, bool $strict = false): array
    {
        if ($fromUnit === $toUnit) {
            return $this->result(
                quantity: $quantity,
                value: $quantity,
                resolvedBy: 'identity',
                missing: false,
                requestedFromUnit: $fromUnit,
                requestedToUnit: $toUnit,
                conversionPath: 'identity'
            );
        }

        $normalizedFrom = UnitConversion::normalizeUnit($fromUnit);
        $normalizedTo = UnitConversion::normalizeUnit($toUnit);
        $factors = $this->metricFactors();

        if (isset($factors[$normalizedFrom], $factors[$normalizedTo])
            && $factors[$normalizedFrom]['kind'] === $factors[$normalizedTo]['kind']
        ) {
            return $this->metricResult($quantity, $fromUnit, $toUnit, $factors[$normalizedFrom], $factors[$normalizedTo]);
        }

        if ($productId) {
            $productRule = $this->activeRuleQuery()
                ->where('product_id', $productId)
                ->where('normalized_from_unit', $normalizedFrom)
                ->where('normalized_to_unit', $normalizedTo)
                ->first();

            if ($productRule) {
                return $this->ruleResult($quantity, $fromUnit, $toUnit, $productRule, 'product_rule', false);
            }

            $productInverseRule = $this->activeRuleQuery()
                ->where('product_id', $productId)
                ->where('normalized_from_unit', $normalizedTo)
                ->where('normalized_to_unit', $normalizedFrom)
                ->first();

            if ($productInverseRule) {
                return $this->ruleResult($quantity, $fromUnit, $toUnit, $productInverseRule, 'product_rule', true);
            }
        }

        $tenantRule = $this->activeRuleQuery()
            ->whereNull('product_id')
            ->where('normalized_from_unit', $normalizedFrom)
            ->where('normalized_to_unit', $normalizedTo)
            ->first();

        if ($tenantRule && $this->ruleAllowedForResolution($tenantRule)) {
            return $this->ruleResult($quantity, $fromUnit, $toUnit, $tenantRule, 'tenant_rule', false);
        }

        $tenantInverseRule = $this->activeRuleQuery()
            ->whereNull('product_id')
            ->where('normalized_from_unit', $normalizedTo)
            ->where('normalized_to_unit', $normalizedFrom)
            ->first();

        if ($tenantInverseRule && $this->ruleAllowedForResolution($tenantInverseRule)) {
            return $this->ruleResult($quantity, $fromUnit, $toUnit, $tenantInverseRule, 'tenant_rule', true);
        }

        if ($strict) {
            throw new \RuntimeException("No active unit conversion rule found from {$fromUnit} to {$toUnit}" . ($productId ? " for product ID {$productId}" : "") . ".");
        }

        return $this->result(
            quantity: $quantity,
            value: $quantity,
            resolvedBy: 'missing',
            missing: true,
            requestedFromUnit: $fromUnit,
            requestedToUnit: $toUnit,
            conversionPath: 'missing'
        );
    }

    protected function activeRuleQuery()
    {
        return UnitConversion::query()
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->where('active_slot', 'active')
            ->where('is_active', true);
    }

    protected function metricFactors(): array
    {
        return [
            'kg' => ['kind' => 'mass', 'factor' => 1.0],
            'kilogram' => ['kind' => 'mass', 'factor' => 1.0],
            'gram' => ['kind' => 'mass', 'factor' => 0.001],
            'g' => ['kind' => 'mass', 'factor' => 0.001],
            'liter' => ['kind' => 'volume', 'factor' => 1.0],
            'litre' => ['kind' => 'volume', 'factor' => 1.0],
            'l' => ['kind' => 'volume', 'factor' => 1.0],
            'ml' => ['kind' => 'volume', 'factor' => 0.001],
            'piece' => ['kind' => 'count', 'factor' => 1.0],
            'pc' => ['kind' => 'count', 'factor' => 1.0],
            'pcs' => ['kind' => 'count', 'factor' => 1.0],
            'unit' => ['kind' => 'count', 'factor' => 1.0],
            'dozen' => ['kind' => 'count', 'factor' => 12.0],
            'gross' => ['kind' => 'count', 'factor' => 144.0],
            'pair' => ['kind' => 'count', 'factor' => 2.0],
        ];
    }

    protected function metricResult(float $quantity, string $fromUnit, string $toUnit, array $fromFactor, array $toFactor): array
    {
        $baseQuantity = $quantity * $fromFactor['factor'];
        $value = $baseQuantity / $toFactor['factor'];

        return $this->result(
            quantity: $quantity,
            value: $value,
            resolvedBy: 'metric_fallback',
            missing: false,
            requestedFromUnit: $fromUnit,
            requestedToUnit: $toUnit,
            conversionPath: 'metric',
            sourceKind: $fromFactor['kind'],
            targetKind: $toFactor['kind'],
            factorNumerator: (string) ($fromFactor['factor'] / $toFactor['factor']),
            factorDenominator: '1'
        );
    }

    protected function ruleAllowedForResolution(UnitConversion $rule): bool
    {
        if ($rule->product_id) {
            return true;
        }

        if ($rule->source_unit_kind !== $rule->target_unit_kind) {
            return false;
        }

        foreach ([$rule->from_unit, $rule->to_unit] as $unit) {
            [$kind] = UnitConversion::classifyUnit($unit);
            if ($kind === 'package' && !UnitConversion::isCanonicalPackageUnit($unit)) {
                return false;
            }
        }

        return true;
    }

    protected function ruleResult(float $quantity, string $fromUnit, string $toUnit, UnitConversion $rule, string $resolvedBy, bool $inverted): array
    {
        $factorNumerator = (float) ($rule->factor_numerator ?: $rule->conversion_factor);
        $factorDenominator = (float) ($rule->factor_denominator ?: 1);

        if ($inverted) {
            [$factorNumerator, $factorDenominator] = [$factorDenominator, $factorNumerator];
        }

        $value = $quantity * $factorNumerator / $factorDenominator;

        return $this->result(
            quantity: $quantity,
            value: $value,
            resolvedBy: $resolvedBy,
            missing: false,
            requestedFromUnit: $fromUnit,
            requestedToUnit: $toUnit,
            conversionPath: $inverted ? 'inverse' : 'direct',
            rule: $rule,
            wasInverted: $inverted,
            sourceKind: $inverted ? $rule->target_unit_kind : $rule->source_unit_kind,
            targetKind: $inverted ? $rule->source_unit_kind : $rule->target_unit_kind,
            factorNumerator: (string) $factorNumerator,
            factorDenominator: (string) $factorDenominator
        );
    }

    private function result(
        float $quantity,
        float $value,
        string $resolvedBy,
        bool $missing,
        string $requestedFromUnit,
        string $requestedToUnit,
        string $conversionPath,
        ?UnitConversion $rule = null,
        bool $wasInverted = false,
        ?string $sourceKind = null,
        ?string $targetKind = null,
        ?string $factorNumerator = null,
        ?string $factorDenominator = null
    ): array {
        $roundedValue = round($value, 4, PHP_ROUND_HALF_UP);
        $normalizedFrom = UnitConversion::normalizeUnit($requestedFromUnit);
        $normalizedTo = UnitConversion::normalizeUnit($requestedToUnit);
        $sourceKind ??= UnitConversion::classifyUnit($requestedFromUnit)[0];
        $targetKind ??= UnitConversion::classifyUnit($requestedToUnit)[0];
        $factorNumerator ??= '1';
        $factorDenominator ??= '1';

        $snapshot = [
            'conversion_schema_version' => 1,
            'resolution_source' => $resolvedBy,
            'conversion_rule_uuid' => $rule?->conversion_uuid,
            'conversion_rule_version' => $rule?->version,
            'requested_from_unit' => $requestedFromUnit,
            'requested_to_unit' => $requestedToUnit,
            'normalized_from_unit' => $normalizedFrom,
            'normalized_to_unit' => $normalizedTo,
            'configured_from_unit' => $rule?->from_unit,
            'configured_to_unit' => $rule?->to_unit,
            'was_inverted' => $wasInverted,
            'conversion_path' => $conversionPath,
            'source_unit_kind' => $sourceKind,
            'target_unit_kind' => $targetKind,
            'source_quantity' => number_format($quantity, 8, '.', ''),
            'factor_numerator' => number_format((float) $factorNumerator, 8, '.', ''),
            'factor_denominator' => number_format((float) $factorDenominator, 8, '.', ''),
            'unrounded_resolved_quantity' => number_format($value, 8, '.', ''),
            'resolved_quantity' => number_format($roundedValue, 4, '.', ''),
            'rounding_mode' => 'HALF_UP',
            'quantity_scale' => 4,
            'product_id' => $rule?->product_id,
            'tenant_id' => $this->tenantContext->getTenantId(),
        ];

        return [
            'value' => $roundedValue,
            'source_quantity' => $quantity,
            'resolved_quantity' => $roundedValue,
            'resolved_by' => $resolvedBy,
            'missing' => $missing,
            'from_unit' => $requestedFromUnit,
            'to_unit' => $requestedToUnit,
            'normalized_from_unit' => $normalizedFrom,
            'normalized_to_unit' => $normalizedTo,
            'source_unit_kind' => $sourceKind,
            'target_unit_kind' => $targetKind,
            'conversion_rule_uuid' => $rule?->conversion_uuid,
            'conversion_rule_version' => $rule?->version,
            'conversion_schema_version' => 1,
            'factor_numerator' => $factorNumerator,
            'factor_denominator' => $factorDenominator,
            'configured_from_unit' => $rule?->from_unit,
            'configured_to_unit' => $rule?->to_unit,
            'requested_from_unit' => $requestedFromUnit,
            'requested_to_unit' => $requestedToUnit,
            'was_inverted' => $wasInverted,
            'conversion_path' => $conversionPath,
            'unrounded_resolved_quantity' => $value,
            'rounded_resolved_quantity' => $roundedValue,
            'rounding_mode' => 'HALF_UP',
            'quantity_scale' => 4,
            'product_id' => $rule?->product_id,
            'tenant_id' => $this->tenantContext->getTenantId(),
            'snapshot' => $snapshot,
        ];
    }
}
