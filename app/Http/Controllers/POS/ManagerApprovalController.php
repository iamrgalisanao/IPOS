<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\DiscountType;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\POS\ManagerAuthorizationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerApprovalController extends Controller
{
    public function __construct(
        protected ManagerAuthorizationService $authorization,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
    ) {}

    public function authorize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'discount_type_id' => ['required', 'uuid'],
            'cart_items' => ['required', 'array', 'min:1'],
            'cart_items.*.product_id' => ['required', 'uuid'],
            'cart_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'options' => ['nullable', 'array'],
            'manager_email' => ['required', 'email'],
            'manager_password' => ['required', 'string'],
        ]);

        $type = DiscountType::active()->findOrFail($validated['discount_type_id']);
        $terminal = $request->attributes->get('terminal_profile');
        if (!$terminal instanceof SalesMachineProfile) {
            return response()->json(['message' => 'Verified terminal context is required.'], 422);
        }

        try {
            $approval = $this->authorization->issue(
                $request->user(), $this->tenantContext->getTenantId(), $this->branchContext->getBranchId(),
                $terminal, $type, $validated['cart_items'], $validated['options'] ?? [],
                $validated['manager_email'], $validated['manager_password'],
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'status' => 'authorized', 'approval_id' => $approval->id,
            'expires_at' => $approval->expires_at->toIso8601String(),
        ]);
    }
}
