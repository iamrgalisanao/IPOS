<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\BranchContext;
use App\Services\InventoryService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryMovementController extends Controller
{
    protected InventoryService $inventoryService;
    protected TenantContext $tenantContext;
    protected BranchContext $branchContext;

    public function __construct(
        InventoryService $inventoryService,
        TenantContext $tenantContext,
        BranchContext $branchContext
    ) {
        $this->inventoryService = $inventoryService;
        $this->tenantContext = $tenantContext;
        $this->branchContext = $branchContext;
    }

    /**
     * List inventory movements for the active branch.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->branchContext->getBranchId();
        
        if (!$branchId) {
            return response()->json(['message' => 'Active branch context required.'], 400);
        }

        $branch = Branch::find($branchId);
        
        if (!$branch || $branch->tenant_id !== $this->tenantContext->getTenantId()) {
            return response()->json(['message' => 'Invalid branch context.'], 403);
        }

        // Permission check
        $user = $request->user();
        if ($user && method_exists($user, 'hasPermission') && !$user->hasPermission('view_branch_inventory')) {
            return response()->json(['message' => 'Unauthorized to view inventory movements.'], 403);
        }

        $movements = $this->inventoryService->getMovementsForBranch($branch, $request->only([
            'movement_type',
            'product_id',
            'per_page'
        ]));

        return response()->json([
            'data' => $movements->map(function ($m) {
                return [
                    'id' => $m->id,
                    'movement_type' => $m->movement_type,
                    'source_type' => $m->source_type,
                    'source_id' => $m->source_id,
                    'tenant_id' => $m->tenant_id,
                    'branch_id' => $m->branch_id,
                    'product_id' => $m->product_id,
                    'product_name' => $m->product->name ?? 'Unknown',
                    'sku' => $m->product->sku ?? null,
                    'quantity_before' => (float) $m->quantity_before,
                    'quantity_change' => (float) $m->quantity_change,
                    'quantity_after' => (float) $m->quantity_after,
                    'created_at' => $m->created_at->toIso8601String(),
                ];
            }),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'total' => $movements->total(),
            ]
        ]);
    }
}
