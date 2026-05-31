<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PriorPeriodAdjustment;
use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PriorPeriodAdjustmentController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Renders the Prior Period Adjustments index page.
     */
    public function index(Request $request)
    {
        $branches = Branch::orderBy('name')->get(['id', 'name', 'branch_code']);
        $terminals = SalesMachineProfile::orderBy('profile_code')->get(['id', 'profile_code', 'terminal_identifier', 'branch_id']);

        return Inertia::render('Admin/PriorPeriodAdjustments/Index', [
            'branches' => $branches,
            'terminals' => $terminals,
            'filters'  => $request->only(['branch_id', 'sales_machine_profile_id', 'start_date', 'end_date', 'status']),
        ]);
    }

    /**
     * API endpoint returning adjustments data.
     */
    public function getAdjustmentsData(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant context missing.'], 403);
        }

        $query = PriorPeriodAdjustment::query()
            ->with([
                'salesMachineProfile:id,profile_code,terminal_identifier',
                'sale:id,sale_number,total,status',
                'offlineSalesImport:id,offline_sequence_number',
                'originalRegisterZRead:id,z_read_sequence,z_read_date',
                'adjustedIntoSettlementPeriod:id,period_start_at,period_end_at,status'
            ]);

        // Tenant scope
        $query->where('tenant_id', $tenant->id);

        if ($this->branchContext->hasBranch()) {
            $query->where('branch_id', $this->branchContext->getBranchId());
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('sales_machine_profile_id')) {
            $query->where('sales_machine_profile_id', $request->sales_machine_profile_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('original_business_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('original_business_date', '<=', $request->end_date);
        }

        $adjustments = $query->orderBy('reconciled_at', 'desc')->get();

        return response()->json([
            'adjustments' => $adjustments,
        ]);
    }
}
