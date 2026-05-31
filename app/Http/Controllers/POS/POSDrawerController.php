<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\BranchContext;
use App\Services\Shift\ShiftService;
use App\Services\Shift\CashDropService;
use App\Services\Shift\SpotAuditService;
use Illuminate\Http\Request;

class POSDrawerController extends Controller
{
    public function __construct(
        protected ShiftService $shiftService,
        protected CashDropService $cashDropService,
        protected SpotAuditService $spotAuditService,
        protected BranchContext $branchContext
    ) {}

    /**
     * GET /api/pos/drawer-status
     */
    public function drawerStatus(Request $request)
    {
        $branch = $this->branchContext->getBranch();
        if (!$branch) {
            return response()->json([
                'active_shift' => false,
                'message' => 'Branch context missing.'
            ], 400);
        }

        $shift = $this->shiftService->getActiveShiftFor($request->user(), $branch);
        if (!$shift) {
            return response()->json([
                'active_shift' => false,
                'message' => 'No active shift found.'
            ], 404);
        }

        $expectedCash = $this->shiftService->calculateExpectedCash($shift);
        $threshold = $this->cashDropService->resolveThreshold($shift->branch_id);
        $isExceeded = bccomp($expectedCash, (string) $threshold, 4) > 0;
        $recommendation = $isExceeded ? bcsub($expectedCash, (string) $threshold, 4) : '0.0000';

        return response()->json([
            'active_shift' => true,
            'shift_id' => $shift->id,
            'current_drawer_cash' => $expectedCash,
            'cash_drawer_limit' => number_format($threshold, 4, '.', ''),
            'is_warning_threshold_exceeded' => $isExceeded,
            'pending_drop_recommendation' => $recommendation,
        ]);
    }

    /**
     * POST /api/pos/shifts/{shift}/spot-audits
     */
    public function spotAudit(Request $request, Shift $shift)
    {
        $request->validate([
            'manager_email' => 'required|email',
            'manager_password' => 'required|string',
            'counted_cash_amount' => 'required|numeric|min:0',
            'denominations' => 'required|array',
            'denominations.*' => 'integer|min:0',
            'audit_notes' => 'nullable|string|max:255',
        ]);

        try {
            $audit = $this->spotAuditService->performSpotAudit(
                $shift,
                $request->manager_email,
                $request->manager_password,
                (string) $request->counted_cash_amount,
                $request->denominations,
                $request->audit_notes
            );

            return response()->json([
                'success' => true,
                'message' => 'Spot audit logged successfully.',
                'audit' => $audit
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * POST /api/pos/shifts/{shift}/drawer-events
     */
    public function recordEvent(Request $request, Shift $shift)
    {
        $request->validate([
            'event_type' => 'required|string|in:cash_drop,cash_top_up',
            'amount' => 'required|numeric|min:0.01',
            'reason_code' => 'required|string|max:50',
            'reason_notes' => 'nullable|string|max:255',
            'manager_email' => 'nullable|email',
            'manager_password' => 'nullable|string',
        ]);

        try {
            if ($request->event_type === 'cash_drop') {
                $event = $this->cashDropService->recordCashDrop(
                    $shift,
                    $request->user(),
                    (string) $request->amount,
                    $request->reason_code,
                    $request->reason_notes,
                    $request->manager_email,
                    $request->manager_password
                );
            } else {
                // Other events (top up, etc.)
                $event = $this->shiftService->recordDrawerEvent(
                    $shift,
                    $request->user(),
                    $request->event_type,
                    (string) $request->amount,
                    $request->reason_code,
                    $request->reason_notes
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Drawer event logged successfully.',
                'event' => $event
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
