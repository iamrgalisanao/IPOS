<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\InventoryVarianceLog;
use App\Models\InventoryVarianceStatusEvent;

class NegativeStockExceptionResult
{
    public function __construct(
        public InventoryMovement $movement,
        public InventoryVarianceLog $variance,
        public InventoryVarianceStatusEvent $statusEvent,
        public bool $replayed,
        public float $quantityBefore,
        public float $quantityAfter,
        public float $incrementalShortageQuantity,
        public float $resultingNegativeQuantity,
        public string $severity
    ) {}
}
