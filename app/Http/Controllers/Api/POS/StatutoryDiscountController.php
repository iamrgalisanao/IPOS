<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Models\DiscountType;
use App\Services\POS\StatutoryDiscountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\POS\ApprovalRuleResolver;
use App\Services\TenantContext;
use App\Services\BranchContext;

class StatutoryDiscountController extends Controller
{
    public function __construct(
        protected StatutoryDiscountService $discountService,
        protected ApprovalRuleResolver $approvalRuleResolver,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
    ) {}

    /**
     * Calculate a statutory discount preview for the POS UI.
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'discount_type_id' => 'required|exists:discount_types,id',
            'cart_items' => 'required|array',
            'cart_items.*.product_id' => 'required|string',
            'cart_items.*.line_subtotal' => 'required|numeric',
            'cart_items.*.tax_bucket' => 'required|string',
            'options' => 'array',
            'options.eligible_person_count' => 'integer|min:1',
            'options.total_pax_count' => 'integer|min:1',
            'options.application_mode' => 'string|in:standard,line_item,portion,memc',
            'options.memc_base_value' => 'numeric',
            'options.beneficiaries' => 'array',
        ]);

        $discountType = DiscountType::active()->findOrFail($validated['discount_type_id']);
        
        $cartItems = collect($validated['cart_items']);
        
        $result = $this->discountService->calculate(
            $cartItems,
            $discountType,
            $validated['options'] ?? []
        );

        $rule = $this->approvalRuleResolver->resolve(
            $this->tenantContext->getTenantId(),
            $this->branchContext->getBranchId(),
            $discountType,
        );
        $result['approval_required'] = $rule['required'];
        $result['approval_rule_source'] = $rule['source'];

        return response()->json($result);
    }
}
