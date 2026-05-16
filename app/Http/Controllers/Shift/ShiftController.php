<?php

namespace App\Http\Controllers\Shift;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShiftController extends Controller
{
    public function __construct(
        protected BranchContext $branchContext
    ) {}

    /**
     * Show the form for opening a new shift.
     */
    public function open()
    {
        $branchId = $this->branchContext->getBranchId();
        
        // Check if there's already an active shift for this user and branch
        $activeShift = Shift::where('branch_id', $branchId)
            ->where('cashier_id', auth()->id())
            ->where('status', Shift::STATUS_OPEN)
            ->first();

        if ($activeShift) {
            return redirect()->route('pos.index')
                ->with('info', 'You already have an active shift.');
        }

        return Inertia::render('Shift/Create', [
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Start a new shift.
     */
    public function store(Request $request)
    {
        $request->validate([
            'opening_cash' => 'required|numeric|min:0',
            'opening_denominations' => 'required|array',
            'opening_denominations.*' => 'integer|min:0',
            'notes' => 'nullable|string',
        ]);

        // Backend recalculation of total from denominations
        $calculatedTotal = collect($request->opening_denominations)
            ->reduce(function ($sum, $count, $value) {
                return $sum + (floatval($value) * intval($count));
            }, 0);

        if (abs($calculatedTotal - floatval($request->opening_cash)) > 0.01) {
            return back()->withErrors(['opening_cash' => 'Total amount mismatch with denominations. Please recount.']);
        }

        $branchId = $this->branchContext->getBranchId();

        $shift = Shift::create([
            'branch_id' => $branchId,
            'cashier_id' => auth()->id(),
            'opened_by' => auth()->id(),
            'status' => Shift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash_amount' => $calculatedTotal,
            'opening_denominations' => $request->opening_denominations,
            'manager_notes' => $request->notes,
        ]);

        return redirect()->route('pos.index')
            ->with('success', 'Shift started successfully.');
    }

    /**
     * Submit shift for closing (from POS).
     */
    public function submitClosing(Request $request, Shift $shift)
    {
        // Simple permission check for now
        if ($shift->cashier_id !== auth()->id()) {
            abort(403);
        }

        $request->validate([
            'actual_cash' => 'required|numeric|min:0',
            'closing_denominations' => 'required|array',
            'closing_denominations.*' => 'integer|min:0',
            'closing_notes' => 'nullable|string',
        ]);

        // Backend recalculation
        $calculatedTotal = collect($request->closing_denominations)
            ->reduce(function ($sum, $count, $value) {
                return $sum + (floatval($value) * intval($count));
            }, 0);

        if (abs($calculatedTotal - floatval($request->actual_cash)) > 0.01) {
            return back()->withErrors(['actual_cash' => 'Total amount mismatch with denominations. Please recount.']);
        }

        $shift->update([
            'status' => Shift::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => auth()->id(),
            'counted_cash_amount' => $calculatedTotal,
            'closing_denominations' => $request->closing_denominations,
            'closing_notes' => $request->closing_notes,
        ]);

        return redirect()->route('shifts.show', $shift)
            ->with('success', 'Shift closed successfully.');
    }

    /**
     * Approve a submitted shift (Admin action).
     */
    public function approve(Request $request, Shift $shift)
    {
        $this->authorize('approve_shift');

        if ($shift->status !== Shift::STATUS_CLOSED) {
            return back()->with('error', 'Only closed shifts can be approved.');
        }

        $shift->update([
            'status' => Shift::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        return back()->with('success', 'Shift approved and finalized.');
    }

    /**
     * Record a cash drawer operational event.
     */
    public function recordDrawerEvent(Request $request, ShiftService $shiftService)
    {
        $request->validate([
            'shift_id' => 'required|uuid|exists:shifts,id',
            'event_type' => 'required|string|in:cash_drop,cash_top_up',
            'amount' => 'required|numeric|min:0.01',
            'reason_code' => 'required|string|max:50',
            'reason_notes' => 'nullable|string|max:255',
        ]);

        $shift = Shift::findOrFail($request->shift_id);

        try {
            $shiftService->recordDrawerEvent(
                $shift,
                $request->user(),
                $request->event_type,
                (string) $request->amount,
                $request->reason_code,
                $request->reason_notes
            );

            return back()->with('success', 'Cash drawer event recorded successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }
    }
}
