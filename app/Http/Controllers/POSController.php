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

        $user = $request->user();
        $isAdminMode = $user && $user->hasPermission('pos-layouts.manage');

        return Inertia::render('POS/Index', [
            'tenant' => $tenant,
            'tenant_id' => $this->tenantContext->getTenantId(),
            'branch_id' => $this->branchContext->getBranchId() ?: $user?->branches()->first()?->id,
            'user_id' => $user?->id,
            'is_admin_mode' => $isAdminMode,
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

    /**
     * API: Get active POS layout for the current branch.
     */
    public function layout(Request $request)
    {
        $branchId = $this->branchContext->getBranchId();
        $tenantId = $this->tenantContext->getTenantId();

        if (!$branchId || !$tenantId) {
            return response()->json(['fallback' => true, 'layout' => null, 'products' => []]);
        }

        $branch = \App\Models\Branch::where('id', $branchId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$branch) {
            return response()->json(['fallback' => true, 'layout' => null, 'products' => []]);
        }

        $activeLayout = $branch->posLayouts()
            ->where('is_active', true)
            ->where('status', \App\Models\PosLayout::STATUS_PUBLISHED)
            ->latest()
            ->first();

        if (!$activeLayout) {
            return response()->json(['fallback' => true, 'layout' => null, 'products' => []]);
        }

        // Double check schema validity
        if (!\App\Services\POS\PosLayoutSchemaValidator::validate($activeLayout->schema)) {
            return response()->json(['fallback' => true, 'layout' => null, 'products' => []]);
        }

        // Resolve products in the layout
        $productIds = collect($activeLayout->schema['tiles'])
            ->where('type', 'product')
            ->pluck('id')
            ->unique()
            ->toArray();

        $products = $this->catalogService->getByIds($productIds);

        return response()->json([
            'fallback' => false,
            'layout' => [
                'id' => $activeLayout->id,
                'name' => $activeLayout->name,
                'version' => $activeLayout->version,
                'schema' => $activeLayout->schema,
            ],
            'products' => $products
        ]);
    }

    /**
     * API: Verify current user password or manager bypass password to unlock terminal.
     */
    public function unlock(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        // 1. Try unlocking with the current cashier's password
        if (\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => true,
                'message' => 'Terminal unlocked.'
            ]);
        }

        // 2. Try unlocking with a manager's credentials (manager bypass)
        $tenantId = $this->tenantContext->getTenantId();
        
        $managers = \App\Models\User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get();

        foreach ($managers as $manager) {
            if ($manager->hasPermission('approve_shift') && \Illuminate\Support\Facades\Hash::check($request->password, $manager->password)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Terminal unlocked by manager ' . $manager->name,
                    'manager_bypass' => true,
                    'manager_name' => $manager->name,
                ]);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid password.'
        ], 422);
    }
}

