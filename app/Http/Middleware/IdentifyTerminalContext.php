<?php

namespace App\Http\Middleware;

use App\Models\SalesMachineProfile;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTerminalContext
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // In testing, also skip unless terminal binding or timecards are enforced.
        if (app()->environment('testing')
            && !config('app.enforce_timecards', false)
            && !config('app.enforce_terminal_binding', false)
        ) {
            return $next($request);
        }

        // Terminal identity binding can be disabled via config for active POS
        // terminal development. Timecard-enforced tests still need verified
        // terminal context because clock-in/out records are terminal-bound.
        // Reference: docs/implementation-plans/epic-41-terminal-identity-binding-planning-lock.md
        if (!app()->environment('testing') && !config('app.enforce_terminal_binding', true)) {
            return $next($request);
        }

        $tenantId = $this->tenantContext->getTenantId();
        $branchId = $this->branchContext->getBranchId();

        if (!$tenantId || !$branchId) {
            return $this->respondForbidden($request, 'Active Tenant and Branch contexts are required.');
        }

        $terminalId = $request->header('X-Terminal-ID') ?: $request->query('test_terminal_id');

        if (!$terminalId || $terminalId === 'null') {
            return $this->respondForbidden($request, 'Terminal context missing.');
        }

        // Verify that the terminal exists under this tenant and branch
        $terminal = SalesMachineProfile::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where(function ($query) use ($terminalId) {
                $query->where('id', $terminalId)
                      ->orWhere('terminal_identifier', $terminalId);
            })
            ->first();

        if (!$terminal) {
            return $this->respondForbidden($request, 'Invalid terminal context.');
        }

        // Verify activation status
        if ($terminal->activation_status !== SalesMachineProfile::STATUS_ACTIVE) {
            return $this->respondForbidden($request, "Terminal activation status is {$terminal->activation_status}.");
        }

        // Verify device ID if one is bound to the profile
        $deviceId = $request->header('X-Device-ID');
        if ($terminal->activated_device_id && $deviceId !== $terminal->activated_device_id) {
            return $this->respondForbidden($request, 'Terminal device ID mismatch.');
        }

        // Attach terminal profile to request
        $request->attributes->set('terminal_profile', $terminal);

        return $next($request);
    }

    protected function respondForbidden(Request $request, string $message): Response
    {
        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => false,
                'code' => 'TERMINAL_CONTEXT_INVALID',
                'message' => $message,
            ], 403);
        }

        if ($request->header('X-Inertia')) {
            if ($request->routeIs('pos.terminal.checkout')) {
                abort(403, $message);
            }
            return redirect()->route('pos.terminal.checkout')->with('error', $message);
        }

        abort(403, $message);
    }
}
