<?php

namespace App\Services\Inventory\Reports;

class MovementCategoryClassifier
{
    public const VERSION = 'movement-category-v1';

    public function classify(?string $movementType, float|string|null $quantityChange = null): string
    {
        $type = str($movementType ?? '')->lower()->replace([' ', '-'], '_')->toString();
        $quantity = (float) ($quantityChange ?? 0);

        return match (true) {
            in_array($type, ['sale_deduction', 'sale', 'recipe_deduction', 'ingredient_deduction'], true) => 'sales_out',
            in_array($type, ['sale_refund', 'refund_return', 'void_restoration', 'sale_return'], true) => 'sales_return_in',
            in_array($type, ['stock_in', 'receiving', 'purchase_receipt', 'opening_stock'], true) => 'receiving_in',
            in_array($type, ['supplier_return', 'purchase_return'], true) => 'supplier_return_out',
            in_array($type, ['transfer_in', 'branch_transfer_in'], true) => 'transfer_in',
            in_array($type, ['transfer_out', 'branch_transfer_out'], true) => 'transfer_out',
            in_array($type, ['stocktake_correction', 'stocktake_adjustment'], true) => 'stocktake_correction',
            in_array($type, ['opening_balance'], true) => 'opening_balance',
            in_array($type, ['migration_baseline'], true) => 'migration_baseline',
            in_array($type, ['manual_adjustment', 'adjustment'], true) && $quantity >= 0 => 'adjustment_in',
            in_array($type, ['manual_adjustment', 'adjustment'], true) && $quantity < 0 => 'adjustment_out',
            str_contains($type, 'adjustment') && $quantity >= 0 => 'adjustment_in',
            str_contains($type, 'adjustment') && $quantity < 0 => 'adjustment_out',
            default => 'other',
        };
    }
}
