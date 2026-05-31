<?php

namespace App\Http\Controllers\Shift;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\Shift\SpotAuditService;
use Illuminate\Http\Request;

class SpotAuditController extends Controller
{
    public function __construct(
        protected SpotAuditService $spotAuditService
    ) {}

    /**
     * Perform a spot audit on an active shift.
     */
    public function store(Request $request, Shift $shift)
    {
        $request->validate([
            'manager_email' => 'required|email',
            'manager_password' => 'required|string',
            'counted_cash_amount' => 'required|numeric|min:0',
            'denominations' => 'required|array',
            'denominations.*' => 'integer|min:0',
            'audit_notes' => 'nullable|string|max:500',
        ]);

        try {
            $this->spotAuditService->performSpotAudit(
                $shift,
                $request->manager_email,
                $request->manager_password,
                (string) $request->counted_cash_amount,
                $request->denominations,
                $request->audit_notes
            );

            return back()->with('success', 'Spot audit successfully recorded.');
        } catch (\Exception $e) {
            return back()->withErrors(['spot_audit' => $e->getMessage()]);
        }
    }
}
