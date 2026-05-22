<?php

namespace App\Services\Inventory;

use App\Models\UnitConversion;

class UnitConversionResolver
{
    public function convert(float $quantity, string $fromUnit, string $toUnit, ?string $productId = null, bool $strict = false): array
    {
        if ($fromUnit === $toUnit) {
            return $this->result($quantity, 'identity', false, $fromUnit, $toUnit);
        }

        if ($productId) {
            $productRule = UnitConversion::query()
                ->where('product_id', $productId)
                ->where('from_unit', $fromUnit)
                ->where('to_unit', $toUnit)
                ->where('is_active', true)
                ->first();

            if ($productRule) {
                return $this->result(
                    $quantity * (float) $productRule->conversion_factor,
                    'product_rule',
                    false,
                    $fromUnit,
                    $toUnit
                );
            }
        }

        $globalRule = UnitConversion::query()
            ->whereNull('product_id')
            ->where('from_unit', $fromUnit)
            ->where('to_unit', $toUnit)
            ->where('is_active', true)
            ->first();

        if ($globalRule) {
            return $this->result(
                $quantity * (float) $globalRule->conversion_factor,
                'global_rule',
                false,
                $fromUnit,
                $toUnit
            );
        }

        $factors = [
            'kg' => 1.0,
            'gram' => 0.001,
            'liter' => 1.0,
            'ml' => 0.001,
            'piece' => 1.0,
        ];

        if (isset($factors[$fromUnit], $factors[$toUnit])) {
            $baseQuantity = $quantity * $factors[$fromUnit];

            return $this->result($baseQuantity / $factors[$toUnit], 'metric_fallback', false, $fromUnit, $toUnit);
        }

        if ($strict) {
            throw new \RuntimeException("No active unit conversion rule found from {$fromUnit} to {$toUnit}" . ($productId ? " for product ID {$productId}" : "") . ".");
        }

        return $this->result($quantity, 'missing', true, $fromUnit, $toUnit);
    }

    private function result(float $value, string $resolvedBy, bool $missing, string $fromUnit, string $toUnit): array
    {
        return [
            'value' => $value,
            'resolved_by' => $resolvedBy,
            'missing' => $missing,
            'from_unit' => $fromUnit,
            'to_unit' => $toUnit,
        ];
    }
}
