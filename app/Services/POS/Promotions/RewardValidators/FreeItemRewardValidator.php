<?php

namespace App\Services\POS\Promotions\RewardValidators;

use InvalidArgumentException;

class FreeItemRewardValidator
{
    public static function validate(array $rewards): void
    {
        // Free item is valid with or without a target product ID (can match the cheapest item in rule context automatically)
        if (isset($rewards['product_id']) && !is_string($rewards['product_id'])) {
            throw new InvalidArgumentException('FreeItem reward "product_id" must be a string.');
        }
    }
}
