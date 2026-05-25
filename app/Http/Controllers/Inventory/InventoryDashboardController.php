<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\ProductCategory;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryDashboardController extends Controller
{
    public function __construct(
        protected BranchContext $branchContext
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewCosts = $user->hasPermission('audit_inventory');

        $branches = $this->resolveAccessibleBranches($user);
        $branchIds = $branches->pluck('id');

        $requestedBranchId = (string) $request->query('branch_id', '');
        $selectedBranchId = $branchIds->contains($requestedBranchId)
            ? $requestedBranchId
            : ($branches->first()?->id ?? null);

        $status = (string) $request->query('status', 'all');
        if (!in_array($status, ['all', 'low', 'negative'], true)) {
            $status = 'all';
        }

        $days = (int) $request->query('days', 30);
        if (!in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        $productQuery = trim((string) $request->query('product', ''));
        $categoryId = trim((string) $request->query('category_id', ''));
        $priority = (string) $request->query('priority', 'all');
        if (!in_array($priority, ['all', 'critical', 'high', 'normal'], true)) {
            $priority = 'all';
        }

        $baseInventory = BranchInventory::query()
            ->active()
            ->whereIn('branch_id', $branchIds)
            ->whereHas('product', function ($q) {
                $q->where('is_inventory_tracked', true);
            });

        if ($selectedBranchId) {
            $baseInventory->where('branch_id', $selectedBranchId);
        }

        if ($productQuery !== '') {
            $baseInventory->whereHas('product', function ($q) use ($productQuery) {
                $q->where('name', 'like', '%' . $productQuery . '%')
                    ->orWhere('sku', 'like', '%' . $productQuery . '%');
            });
        }

        if ($categoryId !== '') {
            $baseInventory->whereHas('product', function ($q) use ($categoryId) {
                $q->where('product_category_id', $categoryId);
            });
        }

        $filteredInventory = clone $baseInventory;
        if ($status === 'low') {
            $filteredInventory->whereColumn('current_stock', '<=', 'reorder_level');
        }
        if ($status === 'negative') {
            $filteredInventory->where('current_stock', '<', 0);
        }

        if ($priority === 'critical') {
            $filteredInventory->where('current_stock', '<', 0);
        }

        if ($priority === 'high') {
            $filteredInventory
                ->where('current_stock', '>=', 0)
                ->whereColumn('current_stock', '<=', 'reorder_level');
        }

        if ($priority === 'normal') {
            $filteredInventory->whereColumn('current_stock', '>', 'reorder_level');
        }

        $summaryRows = (clone $baseInventory)->get([
            'current_stock',
            'reorder_level',
            'average_cost',
        ]);

        $suggestedReorderUnits = $summaryRows->sum(function (BranchInventory $inventory) {
            $stock = (float) $inventory->current_stock;
            $reorder = (float) ($inventory->reorder_level ?? 0);
            return max($reorder - $stock, 0);
        });

        $estimatedReorderValue = $summaryRows->sum(function (BranchInventory $inventory) {
            $stock = (float) $inventory->current_stock;
            $reorder = (float) ($inventory->reorder_level ?? 0);
            $shortage = max($reorder - $stock, 0);
            $cost = (float) ($inventory->average_cost ?? 0);
            return $shortage * $cost;
        });

        $summary = [
            'tracked_items' => (clone $baseInventory)->count(),
            'low_stock_count' => (clone $baseInventory)->whereColumn('current_stock', '<=', 'reorder_level')->count(),
            'negative_stock_count' => (clone $baseInventory)->where('current_stock', '<', 0)->count(),
            'suggested_reorder_units' => round($suggestedReorderUnits, 4),
            'estimated_reorder_value' => $canViewCosts ? round($estimatedReorderValue, 4) : null,
        ];

        $categories = ProductCategory::query()
            ->active()
            ->whereHas('products.branchInventories', function ($q) use ($branchIds) {
                $q->active()->whereIn('branch_id', $branchIds);
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        $branchAggregates = (clone $filteredInventory)
            ->selectRaw('branch_id, COUNT(*) as item_count')
            ->selectRaw('SUM(CASE WHEN current_stock <= reorder_level THEN 1 ELSE 0 END) as low_count')
            ->selectRaw('SUM(CASE WHEN current_stock < 0 THEN 1 ELSE 0 END) as negative_count')
            ->groupBy('branch_id')
            ->orderByDesc('negative_count')
            ->orderByDesc('low_count')
            ->get();

        $branchNameMap = $branches->pluck('name', 'id');
        $branchSummaries = $branchAggregates->map(function ($row) use ($branchNameMap) {
            return [
                'branch_id' => $row->branch_id,
                'branch_name' => $branchNameMap[$row->branch_id] ?? 'Unknown Branch',
                'item_count' => (int) $row->item_count,
                'low_count' => (int) $row->low_count,
                'negative_count' => (int) $row->negative_count,
            ];
        })->values();

        $productVisibility = (clone $filteredInventory)
            ->with([
                'branch:id,name',
                'product:id,name,sku,status,product_category_id',
                'product.category:id,name',
            ])
            ->orderByRaw('CASE WHEN current_stock < 0 THEN 0 WHEN current_stock <= reorder_level THEN 1 ELSE 2 END')
            ->orderByRaw('(reorder_level - current_stock) DESC')
            ->orderBy('current_stock')
            ->limit(15)
            ->get()
            ->map(function (BranchInventory $inventory) use ($canViewCosts) {
                $stock = (float) $inventory->current_stock;
                $reorder = (float) ($inventory->reorder_level ?? 0);
                $averageCost = (float) ($inventory->average_cost ?? 0);
                $reorderShortage = max($reorder - $stock, 0);

                $stockState = 'normal';
                if ($stock < 0) {
                    $stockState = 'negative';
                } elseif ($stock <= $reorder) {
                    $stockState = 'low';
                }

                $priorityClass = 'normal';
                if ($stock < 0) {
                    $priorityClass = 'critical';
                } elseif ($stock <= $reorder) {
                    $priorityClass = 'high';
                }

                return [
                    'branch_name' => $inventory->branch?->name,
                    'product_name' => $inventory->product?->name,
                    'sku' => $inventory->product?->sku,
                    'category_name' => $inventory->product?->category?->name,
                    'current_stock' => $stock,
                    'reorder_level' => $reorder,
                    'reorder_shortage' => $reorderShortage,
                    'recommended_reorder_units' => $reorderShortage,
                    'stock_state' => $stockState,
                    'priority_class' => $priorityClass,
                    'average_cost' => $canViewCosts ? $averageCost : null,
                    'estimated_reorder_value' => $canViewCosts ? ($reorderShortage * $averageCost) : null,
                ];
            })
            ->values();

        $reorderPriorities = (clone $filteredInventory)
            ->with([
                'branch:id,name',
                'product:id,name,sku,product_category_id',
                'product.category:id,name',
            ])
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->orderByRaw('CASE WHEN current_stock < 0 THEN 0 ELSE 1 END')
            ->orderByRaw('(reorder_level - current_stock) DESC')
            ->limit(10)
            ->get()
            ->map(function (BranchInventory $inventory) use ($canViewCosts) {
                $stock = (float) $inventory->current_stock;
                $reorder = (float) ($inventory->reorder_level ?? 0);
                $averageCost = (float) ($inventory->average_cost ?? 0);
                $reorderShortage = max($reorder - $stock, 0);

                return [
                    'branch_name' => $inventory->branch?->name,
                    'product_name' => $inventory->product?->name,
                    'sku' => $inventory->product?->sku,
                    'category_name' => $inventory->product?->category?->name,
                    'current_stock' => $stock,
                    'reorder_level' => $reorder,
                    'recommended_reorder_units' => $reorderShortage,
                    'priority_class' => $stock < 0 ? 'critical' : 'high',
                    'estimated_reorder_value' => $canViewCosts ? ($reorderShortage * $averageCost) : null,
                ];
            })
            ->values();

        $movementSummary = [
            'period_days' => $days,
            'total_count' => 0,
            'type_counts' => [],
        ];

        if ($user->hasPermission('view_branch_inventory') && $selectedBranchId) {
            $movementQuery = InventoryMovement::query()
                ->whereIn('branch_id', $branchIds)
                ->where('branch_id', $selectedBranchId)
                ->where('created_at', '>=', now()->subDays($days));

            $movementSummary['total_count'] = (clone $movementQuery)->count();
            $movementSummary['type_counts'] = (clone $movementQuery)
                ->selectRaw('movement_type, COUNT(*) as total')
                ->groupBy('movement_type')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'movement_type' => $row->movement_type,
                    'total' => (int) $row->total,
                ])
                ->values();
        }

        return Inertia::render('Inventory/Dashboard/Index', [
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
            ])->values(),
            'filters' => [
                'branch_id' => $selectedBranchId,
                'product' => $productQuery,
                'category_id' => $categoryId,
                'status' => $status,
                'priority' => $priority,
                'days' => $days,
            ],
            'categories' => $categories,
            'summary' => $summary,
            'branchSummaries' => $branchSummaries,
            'productVisibility' => $productVisibility,
            'reorderPriorities' => $reorderPriorities,
            'movementSummary' => $movementSummary,
            'permissions' => [
                'can_view_costs' => $canViewCosts,
            ],
        ]);
    }

    private function resolveAccessibleBranches($user)
    {
        if ($user->hasPermission('view_multi_branch_dashboard')) {
            return Branch::query()->active()->orderBy('name')->get(['id', 'name']);
        }

        $assigned = $user->branches()->active()->orderBy('name')->get(['branches.id', 'branches.name']);
        if ($assigned->isNotEmpty()) {
            return $assigned;
        }

        if ($this->branchContext->hasBranch()) {
            return Branch::query()
                ->active()
                ->where('id', $this->branchContext->getBranchId())
                ->get(['id', 'name']);
        }

        return collect();
    }
}
