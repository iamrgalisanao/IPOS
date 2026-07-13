<?php

namespace App\Services\POS\Promotions\RuleValidators;

use InvalidArgumentException;

class TieredDiscountRuleValidator
{
    public static function validate(array $conditions): void
    {
        $hasMinSpend = isset($conditions['min_spend_centavos']) && is_int($conditions['min_spend_centavos']) && $conditions['min_spend_centavos'] >= 0;
        $hasMinQty = isset($conditions['min_qty']) && (is_int($conditions['min_qty']) || is_numeric($conditions['min_qty'])) && $conditions['min_qty'] >= 0;

        if (!$hasMinSpend && !$hasMinQty) {
            throw new InvalidArgumentException('Tiered discount condition must specify "min_spend_centavos" or "min_qty".');
        }
    }
}
