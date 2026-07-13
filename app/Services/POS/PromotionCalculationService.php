<?php

namespace App\Services\POS;

use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\Product;
use Illuminate\Support\Str;

class PromotionCalculationService
{
    /**
     * Compute promotions and return a pure result object.
     */
    public function calculate(string $tenantId, string $branchId, array $items, ?string $timestamp = null): PromotionCalculationResult
    {
        $time = $timestamp ? \Carbon\Carbon::parse($timestamp) : now();

        // 1. Fetch active promotions applicable to the branch and date
        $promotions = Promotion::where('tenant_id', $tenantId)
            ->active()
            ->dateRange($time)
            ->forBranch($branchId)
            ->with(['rules' => function ($q) {
                $q->active();
            }])
            ->get();

        // 2. Resolve missing product/category details for correct matching
        $productIds = collect($items)->pluck('product_id')->unique()->values()->all();
        $products = Product::where('tenant_id', $tenantId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        // Initialize cart line states
        $lineStates = [];
        $originalSubtotal = 0;
        foreach ($items as $index => $item) {
            $prod = $products[$item['product_id']] ?? null;
            $unitPrice = (int) ($item['unit_price_centavos'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            $lineSubtotal = (int) round($unitPrice * $qty);
            $originalSubtotal += $lineSubtotal;

            $lineStates[$index] = [
                'index' => $index,
                'product_id' => $item['product_id'],
                'category_id' => $prod ? $prod->product_category_id : null,
                'unit_price_centavos' => $unitPrice,
                'total_quantity' => $qty,
                'available_quantity' => $qty,
                'consumed_quantity' => 0.0,
                'discount_amount_centavos' => 0,
                'applied_promotions' => [],
            ];
        }

        $appliedRules = [];

        // 3. Stacking & tie-breaker loop
        while (true) {
            $candidates = [];

            foreach ($promotions as $promo) {
                foreach ($promo->rules as $rule) {
                    // Check if this rule has already been applied in our current run
                    $alreadyApplied = collect($appliedRules)->contains('promotion_rule_id', $rule->id);
                    if ($alreadyApplied) {
                        continue;
                    }

                    $appliedCount = collect($appliedRules)->where('promotion_rule_id', $rule->id)->count();
                    if ($rule->max_applications_per_sale !== null && $appliedCount >= $rule->max_applications_per_sale) {
                        continue;
                    }

                    $evaluation = $this->evaluateRule($rule, $lineStates);
                    if ($evaluation && $evaluation['benefit_centavos'] > 0) {
                        $candidates[] = [
                            'promotion' => $promo,
                            'rule' => $rule,
                            'benefit_centavos' => $evaluation['benefit_centavos'],
                            'calculation_details' => $evaluation,
                        ];
                    }
                }
            }

            if (empty($candidates)) {
                break;
            }

            // Sort candidates deterministically
            usort($candidates, function ($a, $b) {
                // 1. Higher customer benefit first
                if ($a['benefit_centavos'] !== $b['benefit_centavos']) {
                    return $b['benefit_centavos'] <=> $a['benefit_centavos'];
                }
                // 2. Higher priority first
                if ($a['promotion']->priority !== $b['promotion']->priority) {
                    return $b['promotion']->priority <=> $a['promotion']->priority;
                }
                // 3. Earlier created_at first
                $createA = $a['promotion']->created_at ? $a['promotion']->created_at->timestamp : 0;
                $createB = $b['promotion']->created_at ? $b['promotion']->created_at->timestamp : 0;
                if ($createA !== $createB) {
                    return $createA <=> $createB;
                }
                // 4. Deterministic tie-breaker on UUID string value
                return strcmp($a['promotion']->id, $b['promotion']->id);
            });

            // Apply the winning candidate
            $winner = $candidates[0];
            $appliedRules[] = $this->applyRule($winner['rule'], $winner['promotion'], $winner['calculation_details'], $lineStates);
        }

        // Compute final values
        $promotionDiscount = 0;
        $adjustedLines = [];
        foreach ($lineStates as $index => $state) {
            $lineDiscount = $state['discount_amount_centavos'];
            $promotionDiscount += $lineDiscount;
            $originalAmount = (int) round($state['unit_price_centavos'] * $state['total_quantity']);
            $finalAmount = $originalAmount - $lineDiscount;

            $adjustedLines[] = [
                'product_id' => $state['product_id'],
                'quantity' => $state['total_quantity'],
                'original_unit_price_centavos' => $state['unit_price_centavos'],
                'original_amount_centavos' => $originalAmount,
                'discount_amount_centavos' => $lineDiscount,
                'final_amount_centavos' => $finalAmount,
                'final_unit_price_centavos' => (int) round($finalAmount / ($state['total_quantity'] ?: 1)),
                'applied_promotions' => $state['applied_promotions'],
            ];
        }

        // Generate stable promotions config hash for offline sync drift checks
        $hashString = $promotions->sortBy('id')->map(fn($p) => $p->id . '-' . $p->updated_at->timestamp)->implode('|');
        $rulesVersionHash = hash('sha256', $hashString ?: 'no-promotions');

        return new PromotionCalculationResult(
            $originalSubtotal,
            $promotionDiscount,
            $originalSubtotal - $promotionDiscount,
            $appliedRules,
            $adjustedLines,
            $rulesVersionHash
        );
    }

    /**
     * Evaluate rule conditions and rewards on current line state copies.
     */
    protected function evaluateRule(PromotionRule $rule, array $lineStates): ?array
    {
        $cond = $rule->conditions;
        $reward = $rule->rewards;

        if ($rule->condition_type === 'buy_x_get_y') {
            return $this->evaluateBogo($rule, $cond, $reward, $lineStates);
        }

        if ($rule->condition_type === 'minimum_spend') {
            return $this->evaluateMinimumSpend($rule, $cond, $reward, $lineStates);
        }

        if ($rule->condition_type === 'bundle_match') {
            return $this->evaluateBundleMatch($rule, $cond, $reward, $lineStates);
        }

        return null;
    }

    protected function evaluateBogo(PromotionRule $rule, array $cond, array $reward, array $lineStates): ?array
    {
        $buyQty = (int) ($cond['buy_qty'] ?? 1);
        $rewardQty = (int) ($cond['reward_qty'] ?? 1);

        $buyProductIds = $cond['buy_product_ids'] ?? [];
        $buyCategoryIds = $cond['buy_category_ids'] ?? [];
        $rewardProductIds = $cond['reward_product_ids'] ?? [];
        $rewardCategoryIds = $cond['reward_category_ids'] ?? [];

        // Match buy lines
        $buyCandidates = [];
        foreach ($lineStates as $index => $line) {
            $matchesProduct = in_array($line['product_id'], $buyProductIds);
            $matchesCategory = in_array($line['category_id'], $buyCategoryIds);
            if (($matchesProduct || $matchesCategory) && $line['available_quantity'] > 0) {
                $buyCandidates[] = $index;
            }
        }

        // Sum available buy quantity
        $totalBuyAvailable = 0.0;
        foreach ($buyCandidates as $idx) {
            $totalBuyAvailable += $lineStates[$idx]['available_quantity'];
        }

        if ($totalBuyAvailable < $buyQty) {
            return null;
        }

        // Match reward lines
        $rewardCandidates = [];
        foreach ($lineStates as $index => $line) {
            $matchesProduct = in_array($line['product_id'], $rewardProductIds);
            $matchesCategory = in_array($line['category_id'], $rewardCategoryIds);
            if (($matchesProduct || $matchesCategory) && $line['available_quantity'] > 0) {
                $rewardCandidates[] = $index;
            }
        }

        // Find available reward items (sorted lowest price first to match cheapest_item_free / standard BOGO expectations)
        usort($rewardCandidates, function ($a, $b) use ($lineStates) {
            return $lineStates[$a]['unit_price_centavos'] <=> $lineStates[$b]['unit_price_centavos'];
        });

        // Determine how many times we can apply BOGO based on available buy quantities
        $overlap = false;
        if (array_intersect($buyProductIds, $rewardProductIds) || array_intersect($buyCategoryIds, $rewardCategoryIds)) {
            $overlap = true;
        }

        if ($overlap) {
            $maxAppsByBuy = (int) floor($totalBuyAvailable / ($buyQty + $rewardQty));
        } else {
            $maxAppsByBuy = (int) floor($totalBuyAvailable / $buyQty);
        }

        $maxAppsByLimit = $rule->max_applications_per_sale !== null ? $rule->max_applications_per_sale : 999;
        $applications = min($maxAppsByBuy, $maxAppsByLimit);

        if ($applications <= 0) {
            return null;
        }

        // Simulate consuming buy quantities and granting reward discounts
        $buyQuantityToConsume = $applications * $buyQty;
        $rewardQuantityToGrant = $applications * $rewardQty;

        // Select the reward items to discount
        $grantDetails = [];
        $benefit = 0;
        $tempLineStates = $lineStates; // Use a copy for validation simulation

        // Consume buy quantity from tempLineStates (highest price first is standard for buy requirements)
        usort($buyCandidates, function ($a, $b) use ($tempLineStates) {
            return $tempLineStates[$b]['unit_price_centavos'] <=> $tempLineStates[$a]['unit_price_centavos'];
        });

        $neededBuy = $buyQuantityToConsume;
        foreach ($buyCandidates as $idx) {
            if ($neededBuy <= 0) break;
            $avail = $tempLineStates[$idx]['available_quantity'];
            $consume = min($neededBuy, $avail);
            $tempLineStates[$idx]['available_quantity'] -= $consume;
            $neededBuy -= $consume;
        }

        // Now allocate rewards from remaining available reward items
        $neededReward = $rewardQuantityToGrant;
        foreach ($rewardCandidates as $idx) {
            if ($neededReward <= 0) break;
            $avail = $tempLineStates[$idx]['available_quantity'];
            $grant = min($neededReward, $avail);

            if ($grant > 0) {
                // Calculate reward value
                $unitPrice = $tempLineStates[$idx]['unit_price_centavos'];
                $discountAmt = 0;

                if ($rule->reward_type === 'percent_off') {
                    $percent = (float) ($reward['percent'] ?? 100);
                    $discountAmt = (int) round(($unitPrice * $percent / 100) * $grant);
                } elseif ($rule->reward_type === 'amount_off') {
                    $discountAmt = (int) min(($reward['amount_centavos'] ?? 0) * $grant, $unitPrice * $grant);
                } elseif ($rule->reward_type === 'free_item') {
                    $discountAmt = (int) round($unitPrice * $grant);
                }

                $benefit += $discountAmt;
                $tempLineStates[$idx]['available_quantity'] -= $grant;
                $neededReward -= $grant;

                $grantDetails[] = [
                    'line_index' => $idx,
                    'quantity' => $grant,
                    'discount_amount_centavos' => $discountAmt,
                ];
            }
        }

        if ($benefit <= 0) {
            return null;
        }

        // Enforce maximum discount limit if set
        if ($rule->max_discount_centavos !== null && $benefit > $rule->max_discount_centavos) {
            $ratio = $rule->max_discount_centavos / $benefit;
            $benefit = $rule->max_discount_centavos;
            foreach ($grantDetails as &$detail) {
                $detail['discount_amount_centavos'] = (int) round($detail['discount_amount_centavos'] * $ratio);
            }
        }

        return [
            'benefit_centavos' => $benefit,
            'applications' => $applications,
            'buy_quantity_consumed' => $buyQuantityToConsume,
            'buy_candidates' => $buyCandidates,
            'grant_details' => $grantDetails,
        ];
    }

    protected function evaluateMinimumSpend(PromotionRule $rule, array $cond, array $reward, array $lineStates): ?array
    {
        $minSpend = (int) ($cond['min_spend_centavos'] ?? 0);
        $eligibleProductIds = $cond['eligible_product_ids'] ?? [];
        $eligibleCategoryIds = $cond['eligible_category_ids'] ?? [];

        // Sum current available spend
        $currentSpend = 0;
        $eligibleIndices = [];
        foreach ($lineStates as $index => $line) {
            // If specific scopes are defined, restrict minimum spend computation
            $matchesProduct = empty($eligibleProductIds) || in_array($line['product_id'], $eligibleProductIds);
            $matchesCategory = empty($eligibleCategoryIds) || in_array($line['category_id'], $eligibleCategoryIds);

            if ($matchesProduct && $matchesCategory && $line['available_quantity'] > 0) {
                $currentSpend += (int) round($line['unit_price_centavos'] * $line['available_quantity']);
                $eligibleIndices[] = $index;
            }
        }

        if ($currentSpend < $minSpend || empty($eligibleIndices)) {
            return null;
        }

        $benefit = 0;
        $grantDetails = [];

        if ($rule->reward_type === 'amount_off') {
            $benefit = (int) min(($reward['amount_centavos'] ?? 0), $currentSpend);
            $allocatedDiscount = 0;

            foreach ($eligibleIndices as $idx) {
                $line = $lineStates[$idx];
                $lineAmount = (int) round($line['unit_price_centavos'] * $line['available_quantity']);
                $lineDiscount = (int) round($benefit * ($lineAmount / ($currentSpend ?: 1)));
                $allocatedDiscount += $lineDiscount;

                $grantDetails[] = [
                    'line_index' => $idx,
                    'quantity' => $line['available_quantity'],
                    'discount_amount_centavos' => $lineDiscount,
                ];
            }

            if (!empty($grantDetails) && $allocatedDiscount !== $benefit) {
                $lastIndex = array_key_last($grantDetails);
                $grantDetails[$lastIndex]['discount_amount_centavos'] += $benefit - $allocatedDiscount;
            }
        } else {
            foreach ($eligibleIndices as $idx) {
                $line = $lineStates[$idx];
                $qty = $line['available_quantity'];
                $originalLineAmount = (int) round($line['unit_price_centavos'] * $qty);
                $percent = (float) ($reward['percent'] ?? 0);
                $discountAmt = (int) round(($originalLineAmount * $percent) / 100);

                if ($discountAmt > 0) {
                    $benefit += $discountAmt;
                    $grantDetails[] = [
                        'line_index' => $idx,
                        'quantity' => $qty,
                        'discount_amount_centavos' => $discountAmt,
                    ];
                }
            }
        }

        if ($benefit <= 0) {
            return null;
        }

        if ($rule->max_discount_centavos !== null && $benefit > $rule->max_discount_centavos) {
            $ratio = $rule->max_discount_centavos / $benefit;
            $benefit = $rule->max_discount_centavos;
            foreach ($grantDetails as &$detail) {
                $detail['discount_amount_centavos'] = (int) round($detail['discount_amount_centavos'] * $ratio);
            }
        }

        return [
            'benefit_centavos' => $benefit,
            'grant_details' => $grantDetails,
        ];
    }

    protected function evaluateBundleMatch(PromotionRule $rule, array $cond, array $reward, array $lineStates): ?array
    {
        $requiredItems = $cond['required_items'] ?? [];

        // Check if bundle can match
        $maxApps = 999;
        $matchMappings = [];

        foreach ($requiredItems as $req) {
            $reqProdId = $req['product_id'] ?? null;
            $reqCatId = $req['category_id'] ?? null;
            $reqQty = (float) ($req['qty'] ?? 1);

            $availableQty = 0.0;
            $indices = [];
            foreach ($lineStates as $index => $line) {
                $matchesProduct = $reqProdId && $line['product_id'] === $reqProdId;
                $matchesCategory = $reqCatId && $line['category_id'] === $reqCatId;

                if (($matchesProduct || $matchesCategory) && $line['available_quantity'] > 0) {
                    $availableQty += $line['available_quantity'];
                    $indices[] = $index;
                }
            }

            if ($availableQty < $reqQty) {
                return null;
            }

            $maxAppsForReq = (int) floor($availableQty / $reqQty);
            $maxApps = min($maxApps, $maxAppsForReq);
            $matchMappings[] = [
                'required_qty' => $reqQty,
                'indices' => $indices,
            ];
        }

        if ($rule->max_applications_per_sale !== null) {
            $maxApps = min($maxApps, $rule->max_applications_per_sale);
        }

        if ($maxApps <= 0) {
            return null;
        }

        // Calculate benefit
        $benefit = 0;
        $grantDetails = [];
        $tempLineStates = $lineStates;

        // Simulate consuming required bundle quantities
        $totalOriginalBundlePrice = 0;
        $bundleQuantityConsumedMap = [];

        foreach ($matchMappings as $map) {
            $needed = $map['required_qty'] * $maxApps;
            foreach ($map['indices'] as $idx) {
                if ($needed <= 0) break;
                $avail = $tempLineStates[$idx]['available_quantity'];
                $consume = min($needed, $avail);

                $tempLineStates[$idx]['available_quantity'] -= $consume;
                $needed -= $consume;

                $totalOriginalBundlePrice += (int) round($tempLineStates[$idx]['unit_price_centavos'] * $consume);
                $bundleQuantityConsumedMap[$idx] = ($bundleQuantityConsumedMap[$idx] ?? 0.0) + $consume;
            }
        }

        if ($rule->reward_type === 'fixed_bundle_price') {
            $targetPrice = (int) ($reward['bundle_price_centavos'] ?? 0) * $maxApps;
            $benefit = $totalOriginalBundlePrice - $targetPrice;
        } elseif ($rule->reward_type === 'amount_off') {
            $benefit = (int) ($reward['amount_centavos'] ?? 0) * $maxApps;
        }

        if ($benefit <= 0) {
            return null;
        }

        if ($rule->max_discount_centavos !== null && $benefit > $rule->max_discount_centavos) {
            $benefit = $rule->max_discount_centavos;
        }

        // Distribute discount proportionally across consumed items
        foreach ($bundleQuantityConsumedMap as $idx => $consumedQty) {
            $origPrice = (int) round($lineStates[$idx]['unit_price_centavos'] * $consumedQty);
            $proportionalDiscount = (int) round($benefit * ($origPrice / ($totalOriginalBundlePrice ?: 1)));
            $grantDetails[] = [
                'line_index' => $idx,
                'quantity' => $consumedQty,
                'discount_amount_centavos' => $proportionalDiscount,
            ];
        }

        return [
            'benefit_centavos' => $benefit,
            'applications' => $maxApps,
            'grant_details' => $grantDetails,
            'bundle_consumed_map' => $bundleQuantityConsumedMap,
        ];
    }

    /**
     * Commit changes to the active line states and build applied rule representation.
     */
    protected function applyRule(PromotionRule $rule, Promotion $promo, array $details, array &$lineStates): array
    {
        $appliedLines = [];

        if ($rule->condition_type === 'buy_x_get_y') {
            // Consume buy requirements first
            $neededBuy = (float) $details['buy_quantity_consumed'];
            foreach ($details['buy_candidates'] as $idx) {
                if ($neededBuy <= 0) break;
                $avail = $lineStates[$idx]['available_quantity'];
                $consume = min($neededBuy, $avail);
                $lineStates[$idx]['available_quantity'] -= $consume;
                $lineStates[$idx]['consumed_quantity'] += $consume;
                $neededBuy -= $consume;

                $lineStates[$idx]['applied_promotions'][] = [
                    'promotion_id' => $promo->id,
                    'rule_id' => $rule->id,
                    'role' => 'trigger',
                ];

                $appliedLines[] = [
                    'product_id' => $lineStates[$idx]['product_id'],
                    'quantity' => $consume,
                    'original_unit_price_centavos' => $lineStates[$idx]['unit_price_centavos'],
                    'discount_amount_centavos' => 0,
                    'role' => 'trigger',
                ];
            }
        }

        if (isset($details['bundle_consumed_map'])) {
            foreach ($details['bundle_consumed_map'] as $idx => $consumedQty) {
                $lineStates[$idx]['available_quantity'] -= $consumedQty;
                $lineStates[$idx]['consumed_quantity'] += $consumedQty;

                $lineStates[$idx]['applied_promotions'][] = [
                    'promotion_id' => $promo->id,
                    'rule_id' => $rule->id,
                    'role' => 'bundled',
                ];

                $appliedLines[] = [
                    'product_id' => $lineStates[$idx]['product_id'],
                    'quantity' => $consumedQty,
                    'original_unit_price_centavos' => $lineStates[$idx]['unit_price_centavos'],
                    'discount_amount_centavos' => 0,
                    'role' => 'bundled',
                ];
            }
        }

        // Apply discounts to reward targets
        foreach ($details['grant_details'] as $grant) {
            $idx = $grant['line_index'];
            $qty = $grant['quantity'];
            $discount = $grant['discount_amount_centavos'];

            // Subtract from available qty if BOGO reward target wasn't already decremented
            if ($rule->condition_type === 'buy_x_get_y') {
                $lineStates[$idx]['available_quantity'] -= $qty;
                $lineStates[$idx]['consumed_quantity'] += $qty;
            }

            // If the rule is not stackable, lock the remaining available quantity of the item
            if (!$rule->stackable) {
                $lineStates[$idx]['consumed_quantity'] += $lineStates[$idx]['available_quantity'];
                $lineStates[$idx]['available_quantity'] = 0.0;
            }

            // Stack discount on line
            $lineStates[$idx]['discount_amount_centavos'] += $discount;

            $lineStates[$idx]['applied_promotions'][] = [
                'promotion_id' => $promo->id,
                'rule_id' => $rule->id,
                'role' => 'reward',
                'discount_amount_centavos' => $discount,
            ];

            $appliedLines[] = [
                'product_id' => $lineStates[$idx]['product_id'],
                'quantity' => $qty,
                'original_unit_price_centavos' => $lineStates[$idx]['unit_price_centavos'],
                'discount_amount_centavos' => $discount,
                'role' => $rule->condition_type === 'buy_x_get_y' ? 'reward' : 'discounted',
            ];
        }

        return [
            'id' => Str::uuid()->toString(),
            'promotion_id' => $promo->id,
            'promotion_rule_id' => $rule->id,
            'promotion_name' => $promo->name,
            'rule_type' => $promo->rule_type,
            'condition_type' => $rule->condition_type,
            'reward_type' => $rule->reward_type,
            'priority' => $promo->priority,
            'stackable' => $rule->stackable,
            'exclusive_group' => $rule->exclusive_group,
            'base_amount_centavos' => (int) round($details['benefit_centavos'] / (($rule->reward_type === 'percent_off' ? ($rule->rewards['percent'] / 100) : 1) ?: 1)),
            'discount_amount_centavos' => (int) $details['benefit_centavos'],
            'rule_snapshot_json' => $rule->toArray(),
            'calculation_snapshot_json' => $details,
            'applied_lines' => $appliedLines,
        ];
    }
}
