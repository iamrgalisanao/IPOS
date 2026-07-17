<?php

namespace App\Services\POS\OfflineSync;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryVarianceLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\BranchContext;
use App\Services\InventoryService;
use App\Services\TenantContext;

class OfflineInventoryConsequenceService
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
    ) {}

    public function deductAndVerify(Sale $sale): array
    {
        $sale->loadMissing('items.product.recipes');
        $plan = $this->expectedEffectPlan($sale);

        if ($plan === []) {
            return [
                'inventory_status' => 'not_applicable',
                'variance_status' => 'not_applicable',
                'movement_ids' => [],
                'variance_ids' => [],
                'expected_effects' => [],
            ];
        }

        $this->withContexts($sale, function () use ($sale) {
            $this->inventoryService->deductFromSale($sale->fresh('items'));
        });

        $movements = InventoryMovement::query()
            ->where('tenant_id', $sale->tenant_id)
            ->where('branch_id', $sale->branch_id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->get()
            ->keyBy('source_effect_key');

        $missing = collect($plan)
            ->reject(fn (array $line) => $movements->has($line['expected_source_effect_key']))
            ->values()
            ->all();

        if ($missing !== []) {
            throw new \RuntimeException('Offline inventory consequence evidence is incomplete.');
        }

        $movementIds = $movements->pluck('id')->values()->all();
        $variances = InventoryVarianceLog::query()
            ->where('tenant_id', $sale->tenant_id)
            ->where('branch_id', $sale->branch_id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->where('variance_category', InventoryVarianceLog::CATEGORY_NEGATIVE_STOCK)
            ->get();

        return [
            'inventory_status' => 'committed',
            'variance_status' => $variances->isEmpty() ? 'not_applicable' : 'committed',
            'movement_ids' => $movementIds,
            'variance_ids' => $variances->pluck('id')->values()->all(),
            'expected_effects' => $plan,
        ];
    }

    private function expectedEffectPlan(Sale $sale): array
    {
        $plan = [];

        foreach ($sale->items as $item) {
            /** @var Product|null $product */
            $product = $item->product;
            if (!$product) {
                continue;
            }

            $recipes = $product->recipes;
            if ($recipes && $recipes->isNotEmpty()) {
                foreach ($recipes as $recipe) {
                    $plan[] = [
                        'sale_item_id' => $item->id,
                        'expected_source_effect_key' => "sale:{$sale->id}:sale_item:{$item->id}:recipe_line:{$recipe->recipe_line_uuid}:ingredient:{$recipe->ingredient_id}",
                        'inventory_item_id' => $recipe->ingredient_id,
                        'expected_quantity' => null,
                        'expected_unit_snapshot' => null,
                        'expected_recipe_component_reference' => $recipe->recipe_line_uuid,
                        'expected_variance_requirement' => 'policy_dependent',
                    ];
                }

                continue;
            }

            if (!$item->is_inventory_tracked) {
                continue;
            }

            $plan[] = [
                'sale_item_id' => $item->id,
                'expected_source_effect_key' => "sale:{$sale->id}:sale_item:{$item->id}:product:{$item->product_id}",
                'inventory_item_id' => $item->product_id,
                'expected_quantity' => (string) $item->quantity,
                'expected_unit_snapshot' => $product->unit_of_measure,
                'expected_recipe_component_reference' => null,
                'expected_variance_requirement' => 'policy_dependent',
            ];
        }

        return $plan;
    }

    private function withContexts(Sale $sale, callable $callback): mixed
    {
        $previousTenant = $this->tenantContext->getTenant();
        $previousBranch = $this->branchContext->getBranch();

        $tenant = Tenant::find($sale->tenant_id);
        $branch = Branch::find($sale->branch_id);

        if ($tenant) {
            $this->tenantContext->setTenant($tenant);
        }
        if ($branch) {
            $this->branchContext->setBranch($branch);
        }

        try {
            return $callback();
        } finally {
            $previousTenant ? $this->tenantContext->setTenant($previousTenant) : $this->tenantContext->clear();
            $previousBranch ? $this->branchContext->setBranch($previousBranch) : $this->branchContext->clear();
        }
    }
}
