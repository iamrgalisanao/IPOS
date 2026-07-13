<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\PromotionRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('manage_promotions'), 403);

        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = Promotion::where('tenant_id', $tenantId)
            ->with(['branches', 'rules']);

        // Scoping for Branch Managers
        if ($user->actor_type !== 'system_admin' && !$user->hasRole('Owner/Admin')) {
            $userBranchIds = $user->branches->pluck('id')->toArray();
            $query->where(function ($q) use ($userBranchIds) {
                $q->whereDoesntHave('branches')
                  ->orWhereHas('branches', function ($bq) use ($userBranchIds) {
                      $bq->whereIn('branches.id', $userBranchIds);
                  });
            });
            $branches = $user->branches;
        } else {
            $branches = Branch::where('tenant_id', $tenantId)->orderBy('name')->get();
        }

        $canManageGlobalPromotions = $this->canManageGlobalPromotions($user);
        $promotions = $query->orderBy('priority', 'desc')->get()->map(function (Promotion $promotion) use ($canManageGlobalPromotions) {
            $promotion->is_global = $promotion->branches->isEmpty();
            $promotion->can_manage = $canManageGlobalPromotions || !$promotion->is_global;

            return $promotion;
        });

        return Inertia::render('Admin/Promotions/Index', [
            'promotions' => $promotions,
            'branches' => $branches->values(),
            'products' => Product::where('tenant_id', $tenantId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'product_category_id']),
            'categories' => ProductCategory::where('tenant_id', $tenantId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('manage_promotions'), 403);

        $user = $request->user();
        $tenantId = $user->tenant_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'rule_type' => 'required|string|in:bogo,discount_tier,combo_package',
            'priority' => 'required|integer|min:0',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'is_active' => 'required|boolean',
            'currency' => 'required|string|size:3',
            'timezone' => 'required|string|max:64',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => [
                'uuid',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'schema_version' => 'required|string',
            'condition_type' => 'required|string',
            'reward_type' => 'required|string',
            'conditions' => 'required|array',
            'rewards' => 'required|array',
            'stackable' => 'required|boolean',
            'min_spend_centavos' => 'required|integer|min:0',
            'max_applications_per_sale' => 'nullable|integer|min:1',
            'max_discount_centavos' => 'nullable|integer|min:0',
            'exclusive_group' => 'nullable|string|max:100',
        ]);

        $this->authorizeBranchScope($user, $validated['branch_ids'] ?? []);

        // Validate nested rules schemas
        $this->validateRulePayload(
            $validated['condition_type'],
            $validated['reward_type'],
            $validated['conditions'],
            $validated['rewards']
        );
        $this->validateRuleTypePairing($validated['rule_type'], $validated['condition_type']);
        $this->validateConditionRewardPairing($validated['condition_type'], $validated['reward_type']);
        $this->validateRuleReferences($tenantId, $validated['condition_type'], $validated['reward_type'], $validated['conditions'], $validated['rewards']);

        $promotion = DB::transaction(function () use ($validated, $tenantId, $user) {
            $promo = Promotion::create([
                'tenant_id' => $tenantId,
                'name' => $validated['name'],
                'description' => $validated['description'],
                'rule_type' => $validated['rule_type'],
                'priority' => $validated['priority'],
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'is_active' => $validated['is_active'],
                'currency' => $validated['currency'],
                'timezone' => $validated['timezone'],
                'created_by' => $user->id,
            ]);

            if (!empty($validated['branch_ids'])) {
                $promo->branches()->sync($validated['branch_ids']);
            }

            PromotionRule::create([
                'promotion_id' => $promo->id,
                'schema_version' => $validated['schema_version'],
                'condition_type' => $validated['condition_type'],
                'reward_type' => $validated['reward_type'],
                'conditions' => $validated['conditions'],
                'rewards' => $validated['rewards'],
                'stackable' => $validated['stackable'],
                'min_spend_centavos' => $validated['min_spend_centavos'],
                'max_applications_per_sale' => $validated['max_applications_per_sale'],
                'max_discount_centavos' => $validated['max_discount_centavos'],
                'exclusive_group' => $validated['exclusive_group'],
                'is_active' => true,
                'created_by' => $user->id,
            ]);

            return $promo;
        });

        return back()->with('success', "Promotion '{$promotion->name}' created successfully.");
    }

    public function update(Request $request, Promotion $promotion)
    {
        abort_unless($request->user()->hasPermission('manage_promotions'), 403);

        $user = $request->user();
        abort_unless($promotion->tenant_id === $user->tenant_id, 403);
        $this->authorizeBranchScope($user, $promotion->branches()->pluck('branches.id')->all());

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'required|integer|min:0',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'is_active' => 'required|boolean',
            'timezone' => 'required|string|max:64',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => [
                'uuid',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $user->tenant_id)),
            ],
            'schema_version' => 'required|string',
            'condition_type' => 'required|string',
            'reward_type' => 'required|string',
            'conditions' => 'required|array',
            'rewards' => 'required|array',
            'stackable' => 'required|boolean',
            'min_spend_centavos' => 'required|integer|min:0',
            'max_applications_per_sale' => 'nullable|integer|min:1',
            'max_discount_centavos' => 'nullable|integer|min:0',
            'exclusive_group' => 'nullable|string|max:100',
        ]);

        $this->authorizeBranchScope($user, $validated['branch_ids'] ?? []);

        $this->validateRulePayload(
            $validated['condition_type'],
            $validated['reward_type'],
            $validated['conditions'],
            $validated['rewards']
        );
        $this->validateRuleTypePairing($promotion->rule_type, $validated['condition_type']);
        $this->validateConditionRewardPairing($validated['condition_type'], $validated['reward_type']);
        $this->validateRuleReferences($user->tenant_id, $validated['condition_type'], $validated['reward_type'], $validated['conditions'], $validated['rewards']);

        DB::transaction(function () use ($promotion, $validated, $user) {
            $promotion->update([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'priority' => $validated['priority'],
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'is_active' => $validated['is_active'],
                'timezone' => $validated['timezone'],
                'updated_by' => $user->id,
            ]);

            if (isset($validated['branch_ids'])) {
                $promotion->branches()->sync($validated['branch_ids']);
            }

            $rulePayload = [
                'schema_version' => $validated['schema_version'],
                'condition_type' => $validated['condition_type'],
                'reward_type' => $validated['reward_type'],
                'conditions' => $validated['conditions'],
                'rewards' => $validated['rewards'],
                'stackable' => $validated['stackable'],
                'min_spend_centavos' => $validated['min_spend_centavos'],
                'max_applications_per_sale' => $validated['max_applications_per_sale'],
                'max_discount_centavos' => $validated['max_discount_centavos'],
                'exclusive_group' => $validated['exclusive_group'],
                'is_active' => true,
                'updated_by' => $user->id,
            ];

            $rule = $promotion->rules()->first();
            if ($rule) {
                $rule->update($rulePayload);
            } else {
                $promotion->rules()->create(array_merge($rulePayload, [
                    'created_by' => $user->id,
                ]));
            }
        });

        return back()->with('success', "Promotion '{$promotion->name}' updated successfully.");
    }

    public function destroy(Request $request, Promotion $promotion)
    {
        abort_unless($request->user()->hasPermission('manage_promotions'), 403);

        $user = $request->user();
        abort_unless($promotion->tenant_id === $user->tenant_id, 403);
        $this->authorizeBranchScope($user, $promotion->branches()->pluck('branches.id')->all());

        $promotion->update([
            'is_active' => false,
            'updated_by' => $user->id,
        ]);

        return back()->with('success', "Promotion deactivated successfully.");
    }

    protected function validateRulePayload(string $conditionType, string $rewardType, array $conditions, array $rewards): void
    {
        try {
            // 1. Conditions
            if ($conditionType === 'buy_x_get_y') {
                \App\Services\POS\Promotions\RuleValidators\BogoRuleValidator::validate($conditions);
            } elseif ($conditionType === 'minimum_spend') {
                \App\Services\POS\Promotions\RuleValidators\TieredDiscountRuleValidator::validate($conditions);
            } elseif ($conditionType === 'bundle_match') {
                \App\Services\POS\Promotions\RuleValidators\ComboPackageRuleValidator::validate($conditions);
            } else {
                throw ValidationException::withMessages([
                    'condition_type' => "Unknown promotion condition type: {$conditionType}",
                ]);
            }

            // 2. Rewards
            if ($rewardType === 'percent_off') {
                \App\Services\POS\Promotions\RewardValidators\PercentOffRewardValidator::validate($rewards);
            } elseif ($rewardType === 'amount_off') {
                \App\Services\POS\Promotions\RewardValidators\AmountOffRewardValidator::validate($rewards);
            } elseif ($rewardType === 'free_item') {
                \App\Services\POS\Promotions\RewardValidators\FreeItemRewardValidator::validate($rewards);
            } elseif ($rewardType === 'fixed_bundle_price') {
                \App\Services\POS\Promotions\RewardValidators\FixedBundlePriceRewardValidator::validate($rewards);
            } else {
                throw ValidationException::withMessages([
                    'reward_type' => "Unknown promotion reward type: {$rewardType}",
                ]);
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'conditions' => $exception->getMessage(),
            ]);
        }
    }

    protected function authorizeBranchScope($user, array $branchIds): void
    {
        if ($this->canManageGlobalPromotions($user)) {
            return;
        }

        if (empty($branchIds)) {
            abort(403, 'Promotion branch scope is required for branch-scoped managers.');
        }

        $allowedBranchIds = $user->branches->pluck('id')->all();

        foreach ($branchIds as $branchId) {
            if (!in_array($branchId, $allowedBranchIds, true)) {
                abort(403, 'Promotion branch scope is outside the user assignment.');
            }
        }
    }

    protected function validateRuleTypePairing(string $ruleType, string $conditionType): void
    {
        $expected = [
            'discount_tier' => 'minimum_spend',
            'bogo' => 'buy_x_get_y',
            'combo_package' => 'bundle_match',
        ];

        if (($expected[$ruleType] ?? null) !== $conditionType) {
            throw ValidationException::withMessages([
                'condition_type' => "The {$conditionType} condition is not valid for {$ruleType} promotions.",
            ]);
        }
    }

    protected function canManageGlobalPromotions($user): bool
    {
        return $user->actor_type === 'system_admin' || $user->hasRole('Owner/Admin');
    }

    protected function validateConditionRewardPairing(string $conditionType, string $rewardType): void
    {
        $allowed = [
            'minimum_spend' => ['percent_off', 'amount_off'],
            'buy_x_get_y' => ['percent_off', 'amount_off'],
            'bundle_match' => ['fixed_bundle_price', 'amount_off'],
        ];

        if (!in_array($rewardType, $allowed[$conditionType] ?? [], true)) {
            throw ValidationException::withMessages([
                'reward_type' => "The {$rewardType} reward is not supported for {$conditionType} promotion rules.",
            ]);
        }
    }

    protected function validateRuleReferences(string $tenantId, string $conditionType, string $rewardType, array $conditions, array $rewards): void
    {
        $productIds = [];
        $categoryIds = [];

        if ($conditionType === 'buy_x_get_y') {
            $productIds = array_merge(
                $productIds,
                $conditions['buy_product_ids'] ?? [],
                $conditions['reward_product_ids'] ?? []
            );
            $categoryIds = array_merge(
                $categoryIds,
                $conditions['buy_category_ids'] ?? [],
                $conditions['reward_category_ids'] ?? []
            );
        }

        if ($conditionType === 'minimum_spend') {
            $productIds = array_merge($productIds, $conditions['eligible_product_ids'] ?? []);
            $categoryIds = array_merge($categoryIds, $conditions['eligible_category_ids'] ?? []);
        }

        if ($conditionType === 'bundle_match') {
            foreach ($conditions['required_items'] ?? [] as $item) {
                if (!empty($item['product_id'])) {
                    $productIds[] = $item['product_id'];
                }
                if (!empty($item['category_id'])) {
                    $categoryIds[] = $item['category_id'];
                }
            }
        }

        if ($rewardType === 'free_item' && !empty($rewards['product_id'])) {
            $productIds[] = $rewards['product_id'];
        }

        $productIds = array_values(array_unique(array_filter($productIds)));
        $categoryIds = array_values(array_unique(array_filter($categoryIds)));

        if (!empty($productIds)) {
            $validProductCount = Product::where('tenant_id', $tenantId)
                ->whereIn('id', $productIds)
                ->count();

            if ($validProductCount !== count($productIds)) {
                throw ValidationException::withMessages([
                    'conditions' => 'Promotion rules may only reference products owned by this tenant.',
                ]);
            }
        }

        if (!empty($categoryIds)) {
            $validCategoryCount = ProductCategory::where('tenant_id', $tenantId)
                ->whereIn('id', $categoryIds)
                ->count();

            if ($validCategoryCount !== count($categoryIds)) {
                throw ValidationException::withMessages([
                    'conditions' => 'Promotion rules may only reference product categories owned by this tenant.',
                ]);
            }
        }
    }
}
