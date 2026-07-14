<?php

namespace App\Values\Dining;

use App\Models\DiningTicketItem;

final readonly class BillSplitAllocationPlan
{
    public function __construct(
        public DiningTicketItem $sourceItem,
        public int $childGroupIndex,
        public string $quantity,
        public int $quantityUnits,
        public int $allocatedAmountCentavos,
        public int $promotionDiscountCentavos,
        public int $roundingAdjustmentCentavos,
        public array $promotionAllocationSnapshot,
        public int $allocationSequence,
    ) {
    }
}
