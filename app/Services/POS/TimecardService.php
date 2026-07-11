<?php

namespace App\Services\POS;

use App\Models\EmployeeTimecard;
use App\Models\Shift;
use App\Models\User;
use App\Exceptions\OpenShiftBlocksClockOutException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TimecardService
{
    /**
     * Resolve user by PIN within the tenant context.
     */
    public function resolveUserByPin(string $tenantId, string $branchId, string $pin): ?User
    {
        return User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereNotNull('pos_pin_hash')
            ->get()
            ->first(fn ($u) => Hash::check($pin, $u->pos_pin_hash));
    }

    /**
     * Clock in an employee.
     */
    public function clockIn(
        string $tenantId,
        string $branchId,
        ?string $terminalId,
        string $userId,
        array $context = []
    ): EmployeeTimecard {
        $user = User::findOrFail($userId);

        if (!$user->isActive()) {
            throw new \RuntimeException("Employee is not active.");
        }

        return DB::transaction(function () use ($user, $tenantId, $branchId, $terminalId, $context) {
            $existing = EmployeeTimecard::where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('user_id', $user->id)
                ->whereNull('clocked_out_at')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new \RuntimeException("Employee is already clocked in.");
            }

            // Create timecard
            return EmployeeTimecard::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'terminal_id' => $terminalId,
                'user_id' => $user->id,
                'clocked_in_at' => Carbon::now(),
                'clock_in_ip' => $context['ip_address'] ?? null,
                'clock_in_device_id' => $context['device_id'] ?? null,
                'clock_in_method' => $context['method'] ?? 'pin',
                'is_active' => 1,
            ]);
        });
    }

    /**
     * Clock out an employee.
     */
    public function clockOut(
        string $tenantId,
        string $branchId,
        ?string $terminalId,
        string $userId,
        array $context = []
    ): EmployeeTimecard {
        $user = User::findOrFail($userId);

        return DB::transaction(function () use ($user, $tenantId, $branchId, $terminalId, $context) {
            $timecard = EmployeeTimecard::where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('user_id', $user->id)
                ->whereNull('clocked_out_at')
                ->lockForUpdate()
                ->first();

            if (!$timecard) {
                throw new \RuntimeException("Employee is not clocked in.");
            }

            $this->assertCanClockOut($user->id, $branchId);

            $timecard->update([
                'clocked_out_at' => Carbon::now(),
                'clock_out_ip' => $context['ip_address'] ?? null,
                'clock_out_device_id' => $context['device_id'] ?? null,
                'clock_out_method' => $context['method'] ?? 'pin',
                'clock_out_reason' => $context['reason'] ?? null,
                'is_active' => null, // Set to NULL for MySQL index compatibility
            ]);

            return $timecard;
        });
    }

    /**
     * Assert if a cashier can clock out.
     */
    public function assertCanClockOut(string $userId, string $branchId): void
    {
        // Check if cashier has an open POS shift
        $hasOpenShift = Shift::where('cashier_id', $userId)
            ->where('status', Shift::STATUS_OPEN)
            ->exists();

        if ($hasOpenShift) {
            throw new OpenShiftBlocksClockOutException();
        }
    }

    /**
     * Get active timecard for employee.
     */
    public function getActiveTimecard(string $tenantId, string $branchId, string $userId): ?EmployeeTimecard
    {
        return EmployeeTimecard::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('user_id', $userId)
            ->whereNull('clocked_out_at')
            ->first();
    }
}
