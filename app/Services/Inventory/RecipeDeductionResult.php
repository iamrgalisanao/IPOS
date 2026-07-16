<?php

namespace App\Services\Inventory;

class RecipeDeductionResult
{
    public function __construct(
        public readonly string $recipeBatchUuid,
        public readonly string $saleId,
        public readonly string $saleItemId,
        public readonly string $parentProductId,
        public readonly float $parentQuantity,
        public readonly array $lines,
        public readonly bool $replayed = false
    ) {}

    public function movementIds(): array
    {
        return array_values(array_filter(array_map(
            fn (array $line) => $line['movement_id'] ?? null,
            $this->lines
        )));
    }

    public function negativeStockExceptionIds(): array
    {
        return array_values(array_filter(array_map(
            fn (array $line) => $line['negative_stock_exception_id'] ?? null,
            $this->lines
        )));
    }

    public function toArray(): array
    {
        return [
            'recipe_batch_uuid' => $this->recipeBatchUuid,
            'sale_id' => $this->saleId,
            'sale_item_id' => $this->saleItemId,
            'parent_product_id' => $this->parentProductId,
            'parent_quantity' => number_format($this->parentQuantity, 4, '.', ''),
            'lines' => $this->lines,
            'replayed' => $this->replayed,
            'movement_ids' => $this->movementIds(),
            'negative_stock_exception_ids' => $this->negativeStockExceptionIds(),
        ];
    }
}
