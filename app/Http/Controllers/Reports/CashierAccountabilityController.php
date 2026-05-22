<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use App\Services\Shift\ShiftAccountabilityQueryService;
use App\Services\Shift\ShiftAccountabilityCsvExportService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Auth\Access\AuthorizationException;

class CashierAccountabilityController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Display a listing of cashier accountability reports.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Standard permission check for the entry point
        if (!$user->hasPermission('reports.cashier-accountability.view') && !$user->hasPermission('reports.shift-summary.view')) {
            abort(403, 'Unauthorized.');
        }

        $query = Shift::with(['cashier:id,name', 'branch:id,name']);

        // RBAC Branch & Cashier Scope gating
        if ($user->hasPermission('view_all_shifts') || $user->hasPermission('reports.shift-summary.view')) {
            if ($user->hasPermission('view_all_shifts')) {
                // Admin can see everything, but respect branch context if active
                if ($this->branchContext->hasBranch()) {
                    $query->where('branch_id', $this->branchContext->getBranchId());
                }
            } else {
                // Manager can see assigned branches, respect active branch context
                $branchIds = $user->branches()->pluck('branches.id')->all();
                $query->whereIn('branch_id', $branchIds);
                
                if ($this->branchContext->hasBranch()) {
                    $query->where('branch_id', $this->branchContext->getBranchId());
                }
            }
        } else {
            // Cashiers can only view their own shifts
            $query->where('cashier_id', $user->id);
        }

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('opened_at', $request->date);
        }

        if ($request->filled('cashier_id')) {
            if ($user->hasPermission('view_all_shifts') || $user->hasPermission('reports.shift-summary.view')) {
                $query->where('cashier_id', $request->cashier_id);
            }
        }

        if ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
            if ($user->hasPermission('view_all_shifts')) {
                $query->where('branch_id', $branchId);
            } elseif ($user->hasPermission('reports.shift-summary.view')) {
                $branchIds = $user->branches()->pluck('branches.id')->all();
                if (in_array((string)$branchId, array_map('strval', $branchIds), true)) {
                    $query->where('branch_id', $branchId);
                } else {
                    $query->whereRaw('1=0'); // Force empty result if branch access not allowed
                }
            }
        }

        $shifts = $query->latest('opened_at')
            ->paginate(15)
            ->withQueryString()
            ->through(function ($shift) {
                return [
                    'id' => $shift->id,
                    'branch' => [
                        'name' => $shift->branch->name ?? '',
                    ],
                    'cashier' => [
                        'name' => $shift->cashier->name ?? '',
                    ],
                    'status' => $shift->status,
                    'opened_at' => $shift->opened_at?->toIso8601String(),
                    'closed_at' => $shift->closed_at?->toIso8601String(),
                    'expected_cash_amount' => $shift->expected_cash_amount,
                    'counted_cash_amount' => $shift->counted_cash_amount,
                    'variance_amount' => $shift->variance_amount,
                ];
            });

        // Dropdowns for filtering (Managers/Admins only)
        $branches = [];
        $cashiers = [];
        if ($user->hasPermission('view_all_shifts') || $user->hasPermission('reports.shift-summary.view')) {
            if ($user->hasPermission('view_all_shifts')) {
                $branches = \App\Models\Branch::select('id', 'name', 'branch_code')->get();
                $cashiers = User::select('id', 'name')->get();
            } else {
                $branches = $user->branches()->select('id', 'name', 'branch_code')->get();
                $branchIds = $branches->pluck('id');
                $cashiers = User::whereHas('branches', function ($q) use ($branchIds) {
                    $q->whereIn('branch_id', $branchIds);
                })->select('id', 'name')->get();
            }
        }

        return Inertia::render('Reports/CashierAccountability/Index', [
            'shifts' => $shifts,
            'filters' => $request->only(['status', 'date', 'cashier_id', 'branch_id']),
            'branches' => $branches,
            'cashiers' => $cashiers,
        ]);
    }

    public function show(
        Shift $shift, 
        Request $request, 
        ShiftAccountabilityQueryService $queryService,
        AuditLogger $auditLogger
    ): Response {
        $user = $request->user();

        // 1. Tenant isolation check
        if ($shift->tenant_id !== $this->tenantContext->getTenantId()) {
            abort(403, 'Cross-tenant access blocked.');
        }

        // 2. Query service automatically enforces RBAC, Tenant, Branch isolation
        try {
            $payload = $queryService->forShift($shift, $user);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        // 3. Branch Context enforcement
        if ($this->branchContext->hasBranch() && $shift->branch_id !== $this->branchContext->getBranchId()) {
            abort(403, 'Shift branch mismatch.');
        }

        // 4. Log the audit event for viewing the cashier accountability report
        $auditLogger->log(
            action: 'cashier_accountability_report_viewed',
            auditable: $shift,
            metadata: [
                'tenant_id' => $shift->tenant_id,
                'branch_id' => $shift->branch_id,
                'shift_id' => $shift->id,
                'viewer_id' => $user->id,
                'generated_at' => now()->toIso8601String(),
            ]
        );

        return Inertia::render('Reports/CashierAccountability/Show', [
            'report' => $payload,
        ]);
    }

    /**
     * Export the specified cashier accountability report as a CSV.
     */
    public function export(
        Shift $shift, 
        Request $request, 
        ShiftAccountabilityQueryService $queryService,
        ShiftAccountabilityCsvExportService $exportService,
        AuditLogger $auditLogger
    ) {
        $user = $request->user();

        // 1. Tenant isolation check
        if ($shift->tenant_id !== $this->tenantContext->getTenantId()) {
            abort(403, 'Cross-tenant access blocked.');
        }

        // 2. Export-specific Permission Check
        if (!$user->hasPermission('reports.cashier-accountability.export') && !$user->hasPermission('reports.shift-summary.export')) {
            abort(403, 'Unauthorized to export cashier accountability reports.');
        }

        // 3. Query service automatically enforces RBAC, Tenant, Branch isolation
        try {
            $payload = $queryService->forShift($shift, $user);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        // 4. Branch Context enforcement
        if ($this->branchContext->hasBranch() && $shift->branch_id !== $this->branchContext->getBranchId()) {
            abort(403, 'Shift branch mismatch.');
        }

        // 5. Log the audit event for exporting the cashier accountability report
        $auditLogger->log(
            action: 'cashier_accountability_report_exported',
            auditable: $shift,
            metadata: [
                'tenant_id' => $shift->tenant_id,
                'branch_id' => $shift->branch_id,
                'shift_id' => $shift->id,
                'viewer_id' => $user->id,
                'generated_at' => now()->toIso8601String(),
                'export_format' => 'CSV',
            ]
        );

        // 6. Generate and return Streamed CSV Response
        return $exportService->export($payload);
    }
}
