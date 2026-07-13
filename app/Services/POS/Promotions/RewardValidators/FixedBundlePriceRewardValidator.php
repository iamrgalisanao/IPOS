<?php

namespace App\Services\POS\Promotions\RewardValidators;

use InvalidArgumentException;

class FixedBundlePriceRewardValidator
{
    public static function validate(array $rewards): void
    {
        if (!isset($rewards['bundle_price_centavos']) || !is_int($rewards['bundle_price_centavos']) || $rewards['bundle_price_centavos'] < 0) {
            throw new InvalidArgumentException('FixedBundlePrice reward must include a positive integer "bundle_price_centavos".');
        }
    }
}
