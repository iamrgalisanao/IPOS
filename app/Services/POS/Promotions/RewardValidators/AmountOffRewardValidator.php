<?php

namespace App\Services\POS\Promotions\RewardValidators;

use InvalidArgumentException;

class AmountOffRewardValidator
{
    public static function validate(array $rewards): void
    {
        if (!isset($rewards['amount_centavos']) || !is_int($rewards['amount_centavos']) || $rewards['amount_centavos'] < 0) {
            throw new InvalidArgumentException('AmountOff reward must include a positive integer "amount_centavos".');
        }
    }
}
