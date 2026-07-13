<?php

namespace App\Services\POS\Promotions\RuleValidators;

use InvalidArgumentException;

class BogoRuleValidator
{
    public static function validate(array $conditions): void
    {
        if (!isset($conditions['buy_qty']) || !is_int($conditions['buy_qty']) || $conditions['buy_qty'] < 1) {
            throw new InvalidArgumentException('BOGO condition must include a positive integer "buy_qty".');
        }

        if (!isset($conditions['reward_qty']) || !is_int($conditions['reward_qty']) || $conditions['reward_qty'] < 1) {
            throw new InvalidArgumentException('BOGO condition must include a positive integer "reward_qty".');
        }

        $hasBuyProducts = isset($conditions['buy_product_ids']) && is_array($conditions['buy_product_ids']);
        $hasBuyCategories = isset($conditions['buy_category_ids']) && is_array($conditions['buy_category_ids']);

        if (!$hasBuyProducts && !$hasBuyCategories) {
            throw new InvalidArgumentException('BOGO condition must specify "buy_product_ids" or "buy_category_ids" array.');
        }

        $hasRewardProducts = isset($conditions['reward_product_ids']) && is_array($conditions['reward_product_ids']);
        $hasRewardCategories = isset($conditions['reward_category_ids']) && is_array($conditions['reward_category_ids']);

        if (!$hasRewardProducts && !$hasRewardCategories) {
            throw new InvalidArgumentException('BOGO condition must specify "reward_product_ids" or "reward_category_ids" array.');
        }
    }
}
