<?php

namespace App\Services\POS;

use App\Models\DiscountType;
use App\Models\Product;
use App\Models\ProductDiscountEligibility;
use App\Models\SaleItem;
use App\Models\SaleStatutoryDiscount;
use Illuminate\Support\Collection;

/**
 * Statutory Discount Calculation Engine
 *
 * Implements the BIR-compliant discount pipeline:
 *   Gross Eligible Amount
 *   → Less VAT (if applicable)
 *   → Discountable Base
 *   → Statutory Discount Amount
 *   → VAT-Exempt Amount
 *   → Final Net Payable
 *
 * This service is the single source of truth for statutory discount computation.
 * All UI, receipt, e-journal, and Z-reading consumers should call this service
 * instead of recalculating independently.
 *
 * Reference: docs/implementation-plans/statutory-discount-engine.md
 */
class StatutoryDiscountService
{
    /**
     * Standard Philippine VAT rate (12%).
     */
    public const VAT_RATE = 12.0;

    /**
     * Calculate a statutory discount for a collection of cart line items.
     *
     * @param Collection $cartItems  Collection of line items with product, quantity, unit_price, tax_bucket
     * @param DiscountType $discountType  The statutory discount type to apply
     * @param array $options {
     *   @var int $eligible_person_count  Number of eligible beneficiaries (default: 1)
     *   @var int $total_pax_count        Total guests in the transaction (for pax validation)
     *   @var string $application_mode    standard|line_item|portion|memc
     *   @var float $memc_base_value      MEMC base value (if mode is memc)
     *   @var array $beneficiaries        Beneficiary identity metadata
     * }
     * @return array {
     *   @var float $gross_eligible_amount
     *   @var float $vat_amount_removed
     *   @var float $discountable_base
     *   @var float $discount_amount
     *   @var float $vat_exempt_amount
     *   @var float $net_payable
     *   @var array $line_items           Per-line breakdown
     *   @var array $calculation_snapshot  Immutable computation record
     *   @var bool $is_valid
     *   @var array $errors
     * }
     */
    public function calculate(Collection $cartItems, DiscountType $discountType, array $options = []): array
    {
        $errors = [];
        $eligiblePersonCount = (int) ($options['eligible_person_count'] ?? 1);
        $totalPaxCount = $options['total_pax_count'] ?? null;
        $applicationMode = $options['application_mode'] ?? 'standard';
        $memcBaseValue = (float) ($options['memc_base_value'] ?? 0);
        $beneficiaries = $options['beneficiaries'] ?? [];

        // ---- Validation ----
        if (!$discountType->is_active) {
            $errors[] = 'Discount type is not active.';
        }

        if ($discountType->requires_identity && empty($beneficiaries)) {
            $errors[] = 'Beneficiary identity is required for this discount type.';
        }

        if ($totalPaxCount !== null && $eligiblePersonCount > $totalPaxCount) {
            $errors[] = "Eligible person count ({$eligiblePersonCount}) cannot exceed total pax count ({$totalPaxCount}).";
        }

        if (!empty($errors)) {
            return $this->invalidResult($errors);
        }

        // ---- Filter eligible line items ----
        $eligibleItems = $this->filterEligibleItems($cartItems, $discountType, $applicationMode);

        if ($eligibleItems->isEmpty()) {
            $errors[] = 'No eligible items for this discount type.';
            return $this->invalidResult($errors);
        }

        // ---- Compute per-line amounts ----
        $lineBreakdown = [];
        $grossEligibleAmount = 0.0;
        $vatAmountRemoved = 0.0;
        $discountableBase = 0.0;
        $discountAmount = 0.0;
        $vatExemptAmount = 0.0;
        $netPayable = 0.0;

        $rate = (float) $discountType->default_rate;
        $isVatExempt = $discountType->vat_treatment === 'exempt';

        foreach ($eligibleItems as $item) {
            $lineGross = (float) $item['line_subtotal'];
            $lineVatAmount = 0.0;
            $lineDiscountableBase = $lineGross;

            // Step 1: Less VAT — Remove VAT from gross to get discountable base
            // For VAT-inclusive pricing: Net = Gross / (1 + rate), VAT = Gross - Net
            if ($isVatExempt && $this->isVatableItem($item)) {
                $lineNetAmount = $lineGross / (1 + (self::VAT_RATE / 100));
                $lineVatAmount = $lineGross - $lineNetAmount;
                $lineDiscountableBase = $lineNetAmount;
                $vatAmountRemoved += $lineVatAmount;
            }

            // Step 2: Apply discount rate to discountable base
            $lineDiscountAmount = $lineDiscountableBase * $rate;
            $lineNetPayable = $lineDiscountableBase - $lineDiscountAmount;

            // Step 3: VAT-exempt amount is the portion that was VAT (now exempt)
            $lineVatExemptAmount = $isVatExempt ? $lineVatAmount : 0.0;

            $grossEligibleAmount += $lineGross;
            $discountableBase += $lineDiscountableBase;
            $discountAmount += $lineDiscountAmount;
            $vatExemptAmount += $lineVatExemptAmount;
            $netPayable += $lineNetPayable;

            $lineBreakdown[] = [
                'sale_item_id' => $item['sale_item_id'] ?? null,
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'] ?? null,
                'gross_amount' => $this->round4($lineGross),
                'vat_amount_removed' => $this->round4($lineVatAmount),
                'discountable_base' => $this->round4($lineDiscountableBase),
                'discount_amount' => $this->round4($lineDiscountAmount),
                'vat_exempt_amount' => $this->round4($lineVatExemptAmount),
                'net_payable' => $this->round4($lineNetPayable),
            ];
        }

        // ---- MEMC mode: Cap discount at MEMC base value ----
        if ($applicationMode === 'memc' && $memcBaseValue > 0) {
            $memcCap = $memcBaseValue * $eligiblePersonCount;
            if ($discountAmount > $memcCap) {
                $discountAmount = $memcCap;
                $netPayable = $discountableBase - $discountAmount;
            }
        }

        // ---- Portion mode: Scale by eligible/total ratio ----
        if ($applicationMode === 'portion' && $totalPaxCount > 0 && $eligiblePersonCount < $totalPaxCount) {
            $portionRatio = $eligiblePersonCount / $totalPaxCount;
            $discountAmount = $discountAmount * $portionRatio;
            $vatExemptAmount = $vatExemptAmount * $portionRatio;
            $netPayable = $discountableBase - $discountAmount;
        }

        // ---- Build immutable calculation snapshot ----
        $calculationSnapshot = [
            'engine_version' => 'EPIC42_V1',
            'discount_type_code' => $discountType->code,
            'discount_type_name' => $discountType->name,
            'statutory_category' => $discountType->statutory_category,
            'application_mode' => $applicationMode,
            'vat_treatment' => $discountType->vat_treatment,
            'vat_rate' => self::VAT_RATE,
            'discount_rate' => $this->round4($rate),
            'eligible_person_count' => $eligiblePersonCount,
            'total_pax_count' => $totalPaxCount,
            'memc_base_value' => $this->round4($memcBaseValue),
            'computed_at' => now()->toIso8601String(),
            'pipeline' => [
                'gross_eligible_amount' => $this->round4($grossEligibleAmount),
                'vat_amount_removed' => $this->round4($vatAmountRemoved),
                'discountable_base' => $this->round4($discountableBase),
                'discount_amount' => $this->round4($discountAmount),
                'vat_exempt_amount' => $this->round4($vatExemptAmount),
                'net_payable' => $this->round4($netPayable),
            ],
            'line_items' => $lineBreakdown,
            'beneficiary_count' => count($beneficiaries),
        ];

        return [
            'is_valid' => true,
            'errors' => [],
            'gross_eligible_amount' => $this->round4($grossEligibleAmount),
            'vat_amount_removed' => $this->round4($vatAmountRemoved),
            'discountable_base' => $this->round4($discountableBase),
            'discount_amount' => $this->round4($discountAmount),
            'vat_exempt_amount' => $this->round4($vatExemptAmount),
            'net_payable' => $this->round4($netPayable),
            'line_items' => $lineBreakdown,
            'calculation_snapshot' => $calculationSnapshot,
        ];
    }

    /**
     * Filter cart items to only those eligible for the given discount type.
     *
     * For line_item mode, checks product_discount_eligibility table.
     * For standard/portion/memc modes, applies channel restriction
     * (applies_to_fnb / applies_to_retail) based on each item's product type.
     */
    protected function filterEligibleItems(Collection $cartItems, DiscountType $discountType, string $applicationMode): Collection
    {
        if ($applicationMode === 'line_item') {
            $eligibleProductIds = ProductDiscountEligibility::where('discount_type_id', $discountType->id)
                ->where('is_eligible', true)
                ->pluck('product_id')
                ->toArray();

            return $cartItems->filter(fn($item) => in_array($item['product_id'], $eligibleProductIds));
        }

        // For standard, portion, and memc — apply channel restriction.
        // If the discount type applies to both F&B and retail, all items are eligible.
        // If restricted to one channel, each item's product type must match.
        $appliesToBoth = $discountType->applies_to_fnb && $discountType->applies_to_retail;
        if ($appliesToBoth) {
            return $cartItems;
        }

        // Resolve product types for all cart items in one query
        $productIds = $cartItems->pluck('product_id')->filter()->unique()->values()->all();
        $productTypes = [];
        if (!empty($productIds)) {
            $productTypes = Product::whereIn('id', $productIds)
                ->pluck('product_type', 'id')
                ->toArray();
        }

        return $cartItems->filter(function ($item) use ($discountType, $productTypes) {
            $productId = $item['product_id'] ?? null;
            // Allow an explicit product_type override on the cart item
            $productType = $item['product_type'] ?? ($productTypes[$productId] ?? null);

            // If the product type cannot be resolved and the discount is
            // channel-restricted, treat the item as ineligible (fail-safe).
            if ($productType === null) {
                return false;
            }

            $isFnb = in_array($productType, ['finished_good', 'fnb', 'food', 'beverage'], true);
            $isRetail = in_array($productType, ['retail', 'merchandise', 'ingredient', 'raw_material'], true);

            if ($discountType->applies_to_fnb && $isFnb) {
                return true;
            }
            if ($discountType->applies_to_retail && $isRetail) {
                return true;
            }

            return false;
        });
    }

    /**
     * Check if a line item is VATable based on its tax bucket.
     */
    protected function isVatableItem(array $item): bool
    {
        $taxBucket = $item['tax_bucket'] ?? SaleItem::TAX_BUCKET_UNKNOWN;
        return $taxBucket === SaleItem::TAX_BUCKET_VATABLE;
    }

    /**
     * Check if a statutory discount can be combined with an existing promo.
     *
     * Guardrail: Statutory discounts cannot be combined with regular promos
     * unless explicitly allowed.
     */
    public function canCombineWithPromo(DiscountType $statutoryType): bool
    {
        // By default, statutory discounts are exclusive.
        // This can be overridden per discount type in the future.
        return false;
    }

    /**
     * Check if manager approval is required for this discount type.
     */
    public function requiresManagerApproval(DiscountType $discountType): bool
    {
        return $discountType->requires_approval;
    }

    /**
     * Validate beneficiary metadata for a given discount type.
     */
    public function validateBeneficiaryMetadata(DiscountType $discountType, array $beneficiaries): array
    {
        $errors = [];

        if (!$discountType->requires_identity) {
            return ['is_valid' => true, 'errors' => []];
        }

        if (empty($beneficiaries)) {
            $errors[] = 'At least one beneficiary is required.';
            return ['is_valid' => false, 'errors' => $errors];
        }

        foreach ($beneficiaries as $index => $beneficiary) {
            $prefix = "Beneficiary " . ($index + 1) . ": ";

            if (empty($beneficiary['beneficiary_name'])) {
                $errors[] = $prefix . "Name is required.";
            }

            $category = $discountType->statutory_category;

            if ($category === 'senior' || $category === 'pwd') {
                if (empty($beneficiary['id_number'])) {
                    $errors[] = $prefix . "ID number is required for {$category}.";
                }
                if (empty($beneficiary['tin'])) {
                    $errors[] = $prefix . "TIN is required for {$category}.";
                }
            } elseif ($category === 'solo_parent') {
                if (empty($beneficiary['spic_number'])) {
                    $errors[] = $prefix . "SPIC number is required for Solo Parent.";
                }
            }
        }

        return ['is_valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * Round to 4 decimal places (centavo precision).
     */
    protected function round4(float $value): float
    {
        return round($value, 4);
    }

    /**
     * Return an invalid result with errors.
     */
    protected function invalidResult(array $errors): array
    {
        return [
            'is_valid' => false,
            'errors' => $errors,
            'gross_eligible_amount' => 0.0,
            'vat_amount_removed' => 0.0,
            'discountable_base' => 0.0,
            'discount_amount' => 0.0,
            'vat_exempt_amount' => 0.0,
            'net_payable' => 0.0,
            'line_items' => [],
            'calculation_snapshot' => null,
        ];
    }
}
