<?php

namespace App\Http\Controllers\Shift;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftSummaryController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Display a listing of shifts.
     */
    public function index(Request $request, \App\Services\Shift\ShiftService $shiftService): Response
    {
        $user = $request->user();
        $query = Shift::with(['cashier:id,name', 'branch:id,name']);

        // 1. RBAC & Isolation
        if ($user->hasPermission('view_all_shifts')) {
            // Admin can see everything, but respect branch context if active
            if ($this->branchContext->hasBranch()) {
                $query->where('branch_id', $this->branchContext->getBranchId());
            }
        } elseif ($user->hasPermission('view_branch_shifts')) {
            // Manager can see assigned branches
            $branchIds = $user->branches()->pluck('id');
            $query->whereIn('branch_id', $branchIds);
            
            if ($this->branchContext->hasBranch()) {
                $query->where('branch_id', $this->branchContext->getBranchId());
            }
        } else {
            // Regular cashier only own
            $query->where('cashier_id', $user->id);
        }

        // Active Shifts Section (Managers only)
        $activeShifts = [];
        if ($user->hasPermission('approve_shift')) {
            $activeQuery = clone $query;
            $activeShifts = $activeQuery->where('status', Shift::STATUS_OPEN)
                ->get()
                ->map(function ($shift) use ($shiftService) {
                    return [
                        'id' => $shift->id,
                        'cashier_name' => $shift->cashier->name,
                        'branch_name' => $shift->branch->name,
                        'opened_at' => $shift->opened_at,
                        'opening_cash_amount' => $shift->opening_cash_amount,
                        'expected_cash_amount' => $shiftService->calculateExpectedCash($shift),
                        'duration_seconds' => now()->diffInSeconds($shift->opened_at),
                    ];
                });
        }

        // 2. Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('opened_at', $request->date);
        }

        $shifts = $query->latest('opened_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Shift/Index', [
            'shifts' => $shifts,
            'activeShifts' => $activeShifts,
            'filters' => $request->only(['status', 'date']),
        ]);
    }

    /**
     * Display the specified shift summary.
     */
    public function show(Shift $shift, Request $request): Response
    {
        $user = $request->user();

        // 1. Tenant Isolation (already handled by global scope, but let's be explicit for safety)
        if ($shift->tenant_id !== $this->tenantContext->getTenantId()) {
            abort(403, 'Cross-tenant access blocked.');
        }

        // 2. Authorization
        $canView = $user->hasPermission('view_all_shifts') || 
                   $shift->cashier_id === $user->id ||
                   ($user->hasPermission('view_branch_shifts') && $user->canAccessBranch($shift->branch));

        if (!$canView) {
            abort(403, 'Unauthorized to view this shift.');
        }

        // 3. Branch Context enforcement
        if ($this->branchContext->hasBranch() && $shift->branch_id !== $this->branchContext->getBranchId()) {
            abort(403, 'Shift branch mismatch.');
        }

        // 3. Load Details
        $shift->load([
            'cashier:id,name',
            'branch:id,name',
            'openedByUser:id,name',
            'approvedByUser:id,name',
            'cashDrawerEvents' => fn($q) => $q->with(['cashier:id,name', 'createdBy:id,name'])->latest('occurred_at'),
            'salePayments' => fn($q) => $q->whereHas('paymentMethod', fn($pq) => $pq->whereRaw('LOWER(code) = ?', ['cash']))->with('sale:id,sale_number'),
        ]);

        return Inertia::render('Shift/Show', [
            'shift' => $shift,
        ]);
    }

    /**
     * Generate a printable Z-Report for the shift.
     */
    public function zReport(Shift $shift, Request $request, \App\Services\Shift\ShiftReportService $reportService): Response
    {
        $user = $request->user();

        // 1. Tenant/Branch Isolation
        if ($shift->tenant_id !== $this->tenantContext->getTenantId()) {
            abort(403, 'Cross-tenant access blocked.');
        }

        // 2. Authorization
        $canView = $user->hasPermission('view_all_shifts') || 
                   $shift->cashier_id === $user->id ||
                   ($user->hasPermission('view_branch_shifts') && $user->canAccessBranch($shift->branch));

        if (!$canView) {
            abort(403, 'Unauthorized to view this shift report.');
        }

        if ($this->branchContext->hasBranch() && $shift->branch_id !== $this->branchContext->getBranchId()) {
            abort(403, 'Shift branch mismatch.');
        }

        // 3. Redaction Logic: Only users with 'approve_shift' see expected/variance
        $includeSensitivity = $user->hasPermission('approve_shift');

        $reportData = $reportService->generateSummary($shift, $includeSensitivity);

        return Inertia::render('Shift/ZReport', [
            'report' => $reportData,
            'can_see_sensitivity' => $includeSensitivity,
        ]);
    }
}
