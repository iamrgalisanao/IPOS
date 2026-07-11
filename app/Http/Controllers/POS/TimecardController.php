<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\POS\TimecardService;
use App\Services\POS\TimecardSecurityService;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TimecardController extends Controller
{
    public function __construct(
        protected TimecardService $timecardService,
        protected TimecardSecurityService $securityService,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Get active timecard status for current session or requested PIN.
     */
    public function status(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();
        $branchId = $this->branchContext->getBranchId();

        $user = $request->user();
        if (!$user) {
            $pin = $request->query('pin');
            if (!$pin) {
                return response()->json([
                    'success' => false,
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication required.'
                ], 401);
            }

            // Lockout check
            $ip = $request->ip();
            $terminal = $request->attributes->get('terminal_profile');
            $terminalId = $terminal?->id;
            $deviceId = $this->resolveDeviceId($request);

            try {
                $this->securityService->assertNotLockedOut($tenantId, $branchId, $terminalId, $deviceId, $ip);
            } catch (\Throwable $e) {
                if (method_exists($e, 'render')) {
                    return $e->render($request);
                }
                throw $e;
            }

            $user = $this->timecardService->resolveUserByPin($tenantId, $branchId, $pin);

            if (!$user) {
                $this->securityService->recordFailedPinAttempt($tenantId, $branchId, $terminalId, $deviceId, $ip);
                return response()->json([
                    'success' => false,
                    'code' => 'INVALID_PIN',
                    'message' => 'Invalid PIN or employee is not allowed to clock in on this terminal.'
                ], 403);
            }

            $this->securityService->clearFailuresAfterSuccess($tenantId, $branchId, $terminalId, $deviceId, $ip);
        }

        $timecard = $this->timecardService->getActiveTimecard($tenantId, $branchId, $user->id);

        return response()->json([
            'success' => true,
            'clocked_in' => $timecard !== null,
            'timecard_id' => $timecard?->id,
            'clocked_in_at' => $timecard?->clocked_in_at?->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ]
        ]);
    }

    /**
     * Toggle clock-in/out status via PIN authentication.
     */
    public function toggle(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId();
        $branchId = $this->branchContext->getBranchId();

        $request->validate([
            'pin' => 'required|string',
        ]);

        $pin = $request->input('pin');
        $ip = $request->ip();
        $terminal = $request->attributes->get('terminal_profile');
        $terminalId = $terminal?->id;
        $deviceId = $this->resolveDeviceId($request);

        // 1. Lockout Check
        try {
            $this->securityService->assertNotLockedOut($tenantId, $branchId, $terminalId, $deviceId, $ip);
        } catch (\Throwable $e) {
            if (method_exists($e, 'render')) {
                return $e->render($request);
            }
            throw $e;
        }

        // 2. Resolve User by PIN
        $user = $this->timecardService->resolveUserByPin($tenantId, $branchId, $pin);

        if (!$user) {
            $this->securityService->recordFailedPinAttempt($tenantId, $branchId, $terminalId, $deviceId, $ip);
            return response()->json([
                'success' => false,
                'code' => 'INVALID_PIN',
                'message' => 'Invalid PIN or employee is not allowed to clock in on this terminal.'
            ], 403);
        }

        // Clear failures on success
        $this->securityService->clearFailuresAfterSuccess($tenantId, $branchId, $terminalId, $deviceId, $ip);

        // 3. Action clock-in / clock-out
        try {
            $activeTimecard = $this->timecardService->getActiveTimecard($tenantId, $branchId, $user->id);

            $context = [
                'ip_address' => $ip,
                'device_id' => $deviceId,
                'method' => 'pin',
            ];

            if ($activeTimecard) {
                $timecard = $this->timecardService->clockOut(
                    $tenantId,
                    $branchId,
                    $terminalId,
                    $user->id,
                    $context
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Clocked out successfully.',
                    'data' => [
                        'timecard_id' => $timecard->id,
                        'clocked_out_at' => $timecard->clocked_out_at->toIso8601String(),
                        'action' => 'clock_out'
                    ]
                ]);
            } else {
                $timecard = $this->timecardService->clockIn(
                    $tenantId,
                    $branchId,
                    $terminalId,
                    $user->id,
                    $context
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Clocked in successfully.',
                    'data' => [
                        'timecard_id' => $timecard->id,
                        'clocked_in_at' => $timecard->clocked_in_at->toIso8601String(),
                        'action' => 'clock_in'
                    ]
                ]);
            }
        } catch (\Throwable $e) {
            if (method_exists($e, 'render')) {
                return $e->render($request);
            }

            return response()->json([
                'success' => false,
                'code' => 'TIMECARD_ACTION_FAILED',
                'message' => $e->getMessage()
            ], 409);
        }
    }

    protected function resolveDeviceId(Request $request): string
    {
        $deviceId = trim((string) $request->header('X-Device-ID', ''));

        if ($deviceId !== '') {
            return mb_substr($deviceId, 0, 100);
        }

        return 'ua-' . hash('sha256', (string) $request->userAgent());
    }
}
