<?php

namespace App\Http\Controllers\Shift;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\BranchContext;
use App\Services\Shift\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class ShiftController extends Controller
{
    use AuthorizesRequests;

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
    public function submitClosing(Request $request, Shift $shift, ShiftService $shiftService)
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

        try {
            $shiftService->submitClosingCount(
                $shift,
                $request->user(),
                (string) $calculatedTotal,
                $request->closing_notes,
                null,
                $request->closing_denominations
            );

            return redirect()->route('shifts.show', $shift)
                ->with('success', 'Shift closed successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['actual_cash' => $e->getMessage()]);
        }
    }

    /**
     * Approve a submitted shift (Admin action).
     */
    public function approve(Request $request, Shift $shift, ShiftService $shiftService)
    {
        if (!$request->user()->hasPermission('approve_shift')) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'manager_notes' => 'nullable|string',
            'deposit_amount' => 'required|numeric|min:0',
            'variance_explanation' => 'nullable|string',
            'bank_name' => 'nullable|string|max:100',
            'reference_number' => 'nullable|string|max:100',
            'deposited_at' => 'nullable|date',
        ]);

        if ($shift->depositRecord()->exists()) {
            return back()->with('error', 'A deposit record already exists for this shift.');
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($shift, $request, $shiftService, $validated) {
                // Call original shift approval
                $shiftService->approveShift($shift, $request->user(), $validated['manager_notes']);

                // Calculate cash drop total
                $cashDropTotal = $shift->cashDrawerEvents()
                    ->where('event_type', \App\Models\CashDrawerEvent::TYPE_CASH_DROP)
                    ->sum('amount') ?: '0.0000';

                $depositedAt = isset($validated['deposited_at']) ? \Carbon\Carbon::parse($validated['deposited_at']) : now();

                // Create immutable ShiftDepositRecord
                $deposit = \App\Models\ShiftDepositRecord::create([
                    'tenant_id' => $shift->tenant_id,
                    'branch_id' => $shift->branch_id,
                    'shift_id' => $shift->id,
                    'manager_id' => $request->user()->id,
                    'deposit_amount' => $validated['deposit_amount'],
                    'expected_cash_amount' => $shift->expected_cash_amount,
                    'counted_cash_amount' => $shift->counted_cash_amount,
                    'cash_drop_total' => $cashDropTotal,
                    'variance_amount' => $shift->variance_amount,
                    'variance_explanation' => $validated['variance_explanation'] ?? null,
                    'bank_name' => $validated['bank_name'] ?? null,
                    'reference_number' => $validated['reference_number'] ?? null,
                    'deposited_at' => $depositedAt,
                    'approved_at' => now(),
                ]);

                // Log audit events
                app(\App\Services\AuditLogger::class)->log(
                    'shift_deposit_record_created',
                    $deposit,
                    null,
                    $deposit->toArray(),
                    'SHIFT_DEPOSIT',
                    'Shift deposit record created on approval.'
                );
            });

            return back()->with('success', 'Shift approved and finalized.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Record a cash drawer operational event.
     */
    public function recordDrawerEvent(Request $request, ShiftService $shiftService, \App\Services\Shift\CashDropService $cashDropService)
    {
        $request->validate([
            'shift_id' => 'required|uuid|exists:shifts,id',
            'event_type' => 'required|string|in:cash_drop,cash_top_up',
            'amount' => 'required|numeric|min:0.01',
            'reason_code' => 'required|string|max:50',
            'reason_notes' => 'nullable|string|max:255',
            'manager_email' => 'nullable|email',
            'manager_password' => 'nullable|string',
        ]);

        $shift = Shift::findOrFail($request->shift_id);

        try {
            if ($request->event_type === \App\Models\CashDrawerEvent::TYPE_CASH_DROP) {
                $cashDropService->recordCashDrop(
                    $shift,
                    $request->user(),
                    (string) $request->amount,
                    $request->reason_code,
                    $request->reason_notes,
                    $request->manager_email,
                    $request->manager_password
                );
            } else {
                $shiftService->recordDrawerEvent(
                    $shift,
                    $request->user(),
                    $request->event_type,
                    (string) $request->amount,
                    $request->reason_code,
                    $request->reason_notes
                );
            }

            return back()->with('success', 'Cash drawer event recorded successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }
    }
}
