<?php

namespace App\Services\POS\Promotions\RuleValidators;

use InvalidArgumentException;

class ComboPackageRuleValidator
{
    public static function validate(array $conditions): void
    {
        if (!isset($conditions['required_items']) || !is_array($conditions['required_items']) || empty($conditions['required_items'])) {
            throw new InvalidArgumentException('Combo package condition must specify a non-empty "required_items" array.');
        }

        foreach ($conditions['required_items'] as $index => $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException("Item at index {$index} in combo package required_items must be an object/array.");
            }

            $hasProduct = isset($item['product_id']) && is_string($item['product_id']);
            $hasCategory = isset($item['category_id']) && is_string($item['category_id']);

            if (!$hasProduct && !$hasCategory) {
                throw new InvalidArgumentException("Item at index {$index} in combo package required_items must specify product_id or category_id.");
            }

            if (!isset($item['qty']) || (!is_int($item['qty']) && !is_numeric($item['qty'])) || $item['qty'] <= 0) {
                throw new InvalidArgumentException("Item at index {$index} in combo package required_items must specify a positive quantity.");
            }
        }
    }
}
