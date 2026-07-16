<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\BranchInventory;
use App\Models\InventoryAdjustmentReason;
use App\Services\BranchContext;
use App\Services\Inventory\InventoryAdjustmentApprovalService;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryAdjustmentController extends Controller
{
    public function __construct(
        protected InventoryAdjustmentService $adjustments,
        protected InventoryAdjustmentApprovalService $approvals,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $command = $this->validatedCommand($request);

        try {
            return response()->json($this->adjustments->preview($command));
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $command = $this->validatedCommand($request);

        try {
            $result = $this->adjustments->adjust($command);
            $movement = $result['movement'];

            return response()->json([
                'status' => $result['status'],
                'movement_id' => $movement->id,
                'movement_sequence' => $movement->movement_sequence,
                'inventory_revision' => $movement->inventory?->inventory_revision,
                'quantity_before' => $movement->quantity_before,
                'quantity_change' => $movement->quantity_change,
                'quantity_after' => $movement->quantity_after,
            ], $result['status'] === 'replayed' ? 200 : 201);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function managerApproval(Request $request): JsonResponse
    {
        $command = $this->validatedCommand($request);
        $credentials = $request->validate([
            'manager_email' => ['required', 'email'],
            'manager_password' => ['required', 'string'],
        ]);

        $inventory = $this->inventoryForCommand($command);
        $reason = InventoryAdjustmentReason::where('tenant_id', $inventory->tenant_id)
            ->where('code', strtoupper(trim((string) $command['reason_code'])))
            ->where('is_active', true)
            ->firstOrFail();

        try {
            $approval = $this->approvals->issue(
                $request->user(),
                $inventory,
                $reason,
                $command,
                $credentials['manager_email'],
                $credentials['manager_password']
            );
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json([
            'status' => 'authorized',
            'approval_id' => $approval->id,
            'expires_at' => $approval->expires_at?->toIso8601String(),
        ]);
    }

    protected function validatedCommand(Request $request): array
    {
        $validated = $request->validate([
            'branch_inventory_id' => ['required', 'uuid'],
            'quantity_change' => ['nullable', 'numeric'],
            'requested_quantity' => ['nullable', 'numeric', 'gt:0'],
            'requested_direction' => ['nullable', 'string', 'in:increase,decrease'],
            'reason_code' => ['required', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'client_request_uuid' => ['required', 'uuid'],
            'manager_approval_id' => ['nullable', 'uuid'],
        ]);

        if (!array_key_exists('quantity_change', $validated) && !array_key_exists('requested_quantity', $validated)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'quantity_change' => ['Either quantity_change or requested_quantity is required.'],
            ]);
        }

        $inventory = $this->inventoryForCommand($validated);
        $validated['product_id'] = $inventory->product_id;

        if (!array_key_exists('quantity_change', $validated) || $validated['quantity_change'] === null) {
            $reason = InventoryAdjustmentReason::where('tenant_id', $inventory->tenant_id)
                ->where('code', strtoupper(trim((string) $validated['reason_code'])))
                ->where('is_active', true)
                ->firstOrFail();
            $quantity = abs((float) $validated['requested_quantity']);
            $validated['quantity_change'] = match ($reason->direction_policy) {
                InventoryAdjustmentReason::DIRECTION_DECREASE => -$quantity,
                InventoryAdjustmentReason::DIRECTION_OPENING_BALANCE,
                InventoryAdjustmentReason::DIRECTION_INCREASE => $quantity,
                default => ($validated['requested_direction'] ?? 'increase') === 'decrease' ? -$quantity : $quantity,
            };
        }

        return $validated;
    }

    protected function inventoryForCommand(array $command): BranchInventory
    {
        return BranchInventory::where('tenant_id', $this->tenantContext->getTenantId())
            ->where('id', $command['branch_inventory_id'])
            ->when($this->branchContext->hasBranch(), fn ($query) => $query->where('branch_id', $this->branchContext->getBranchId()))
            ->firstOrFail();
    }
}
