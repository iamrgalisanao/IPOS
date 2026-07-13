<?php

namespace App\Services\POS;

class PromotionCalculationResult
{
    public function __construct(
        public int $originalSubtotalCentavos,
        public int $promotionDiscountCentavos,
        public int $promotionAdjustedSubtotalCentavos,
        public array $appliedPromotions,
        public array $adjustedLines,
        public string $promotionRulesVersionHash
    ) {}
}
