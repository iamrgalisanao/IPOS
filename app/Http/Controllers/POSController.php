<?php

namespace App\Http\Controllers;

use App\Services\CatalogService;
use App\Services\ConfigurationService;
use App\Models\PaymentMethod;
use App\Models\ProductCategory;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class POSController extends Controller
{
    public function __construct(
        protected CatalogService $catalogService,
        protected TenantContext $tenantContext,
        protected ConfigurationService $configurationService,
        protected \App\Services\BranchContext $branchContext
    ) {}

    /**
     * Display the POS interface.
     */
    public function index(Request $request)
    {
        $tenant = $this->tenantContext->getTenant();

        if ($tenant) {
            $this->configurationService->ensureDefaultPaymentMethods($tenant);
        }

        return Inertia::render('POS/Index', [
            'tenant' => $tenant,
            'tenant_id' => $this->tenantContext->getTenantId(),
            'branch_id' => $this->branchContext->getBranchId() ?: $request->user()?->branches()->first()?->id,
            'user_id' => $request->user()?->id,
            'categories' => ProductCategory::active()->get(),
            'payment_methods' => PaymentMethod::active()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * API: Search products for POS.
     */
    public function search(Request $request)
    {
        $searchTerm = $request->query('q', '');
        $categoryId = $request->query('category_id');

        return response()->json(
            $this->catalogService->search($searchTerm, $categoryId)
        );
    }

    /**
     * API: Get current active shift for the cashier.
     */
    public function activeShift(Request $request, \App\Services\Shift\ShiftService $shiftService)
    {
        $branchId = $this->branchContext->getBranchId();
        if (!$branchId) {
            return response()->json(null);
        }

        $branch = \App\Models\Branch::find($branchId);
        if (!$branch) {
            return response()->json(null);
        }

        $shift = $shiftService->getActiveShiftFor($request->user(), $branch);

        if (!$shift) {
            return response()->json(null);
        }

        // Base response for everyone
        $data = [
            'id' => $shift->id,
            'opened_at' => $shift->opened_at,
            'cashier_name' => $shift->cashier->name,
            'opening_cash_amount' => $shift->opening_cash_amount,
            'status' => $shift->status,
        ];

        // Manager-only sensitive fields
        if ($request->user()->hasPermission('approve_shift')) {
            $data['expected_cash_amount'] = $shiftService->calculateExpectedCash($shift);
            $data['is_manager_view'] = true;
        }

        return response()->json($data);
    }
}
