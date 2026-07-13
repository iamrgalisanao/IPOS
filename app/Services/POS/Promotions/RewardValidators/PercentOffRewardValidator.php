<?php

namespace App\Services\POS\Promotions\RewardValidators;

use InvalidArgumentException;

class PercentOffRewardValidator
{
    public static function validate(array $rewards): void
    {
        if (!isset($rewards['percent']) || !is_numeric($rewards['percent']) || $rewards['percent'] < 0 || $rewards['percent'] > 100) {
            throw new InvalidArgumentException('PercentOff reward must include a numeric "percent" value between 0 and 100.');
        }
    }
}
