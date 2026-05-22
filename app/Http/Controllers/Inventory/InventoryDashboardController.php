<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
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

        $filteredInventory = clone $baseInventory;
        if ($status === 'low') {
            $filteredInventory->whereColumn('current_stock', '<=', 'reorder_level');
        }
        if ($status === 'negative') {
            $filteredInventory->where('current_stock', '<', 0);
        }

        $summary = [
            'tracked_items' => (clone $baseInventory)->count(),
            'low_stock_count' => (clone $baseInventory)->whereColumn('current_stock', '<=', 'reorder_level')->count(),
            'negative_stock_count' => (clone $baseInventory)->where('current_stock', '<', 0)->count(),
        ];

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
                'product:id,name,sku,status',
            ])
            ->orderByRaw('CASE WHEN current_stock < 0 THEN 0 WHEN current_stock <= reorder_level THEN 1 ELSE 2 END')
            ->orderBy('current_stock')
            ->limit(15)
            ->get()
            ->map(function (BranchInventory $inventory) {
                $stock = (float) $inventory->current_stock;
                $reorder = (float) ($inventory->reorder_level ?? 0);

                $stockState = 'normal';
                if ($stock < 0) {
                    $stockState = 'negative';
                } elseif ($stock <= $reorder) {
                    $stockState = 'low';
                }

                return [
                    'branch_name' => $inventory->branch?->name,
                    'product_name' => $inventory->product?->name,
                    'sku' => $inventory->product?->sku,
                    'current_stock' => $stock,
                    'reorder_level' => $reorder,
                    'stock_state' => $stockState,
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
                'status' => $status,
                'days' => $days,
            ],
            'summary' => $summary,
            'branchSummaries' => $branchSummaries,
            'productVisibility' => $productVisibility,
            'movementSummary' => $movementSummary,
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
