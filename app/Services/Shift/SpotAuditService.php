<?php

namespace App\Services\Shift;

use App\Models\Shift;
use App\Models\SpotAudit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Settlement\SettlementPeriodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SpotAuditService
{
    public function __construct(
        protected ShiftService $shiftService,
        protected SettlementPeriodService $settlementPeriodService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Perform a surprise spot audit for a shift.
     */
    public function performSpotAudit(
        Shift $shift,
        string $managerEmail,
        string $managerPassword,
        string $countedCashAmount,
        array $denominations,
        ?string $auditNotes = null
    ): SpotAudit {
        $tenantId = app(\App\Services\TenantContext::class)->getTenantId();

        // 1. Tenant safety check
        if ($shift->tenant_id !== $tenantId) {
            throw new \RuntimeException('Cross-tenant spot audit blocked.');
        }

        // 2. Validate counted amount
        if (!is_numeric($countedCashAmount) || bccomp($countedCashAmount, '0', 4) < 0) {
            throw new \InvalidArgumentException('Counted cash amount must be non-negative.');
        }

        // 3. Verify denominations total matches counted cash
        $calculatedTotal = collect($denominations)
            ->reduce(function ($sum, $count, $value) {
                return $sum + (floatval($value) * intval($count));
            }, 0);

        if (abs($calculatedTotal - floatval($countedCashAmount)) > 0.01) {
            throw new \InvalidArgumentException('Total amount mismatch with denominations.');
        }

        // 4. Verify manager credentials
        $manager = $this->verifyManagerCredentials($managerEmail, $managerPassword, $tenantId);

        // 5. Verify manager permissions for spot audit
        if (!$manager->hasPermission('approve_shift') && !$manager->hasPermission('manage_cash_drawer')) {
            throw new \RuntimeException('Unauthorized: manager missing required permissions.');
        }

        // 6. Assert period is not locked
        $occurredAt = now();
        if ($this->settlementPeriodService->isLockedForTimestamp($occurredAt, $shift->branch_id)) {
            throw new \RuntimeException("Operation blocked: Settlement period is locked.");
        }

        // 7. Calculate expected cash
        $expectedCash = $this->shiftService->calculateExpectedCash($shift);
        $variance = bcsub($countedCashAmount, $expectedCash, 4);

        // 8. Create immutable SpotAudit record
        return DB::transaction(function () use ($shift, $manager, $expectedCash, $countedCashAmount, $variance, $denominations, $auditNotes, $occurredAt) {
            $spotAudit = SpotAudit::create([
                'tenant_id' => $shift->tenant_id,
                'branch_id' => $shift->branch_id,
                'shift_id' => $shift->id,
                'cashier_id' => $shift->cashier_id,
                'manager_id' => $manager->id,
                'expected_cash_amount' => $expectedCash,
                'counted_cash_amount' => $countedCashAmount,
                'variance_amount' => $variance,
                'denominations' => $denominations,
                'audit_notes' => $auditNotes,
                'occurred_at' => $occurredAt,
            ]);

            $this->auditLogger->log(
                'spot_audit_created',
                $spotAudit,
                null,
                [
                    'spot_audit_id' => $spotAudit->id,
                    'shift_id' => $shift->id,
                    'manager_id' => $manager->id,
                    'cashier_id' => $shift->cashier_id,
                    'expected_cash_amount' => $expectedCash,
                    'counted_cash_amount' => $countedCashAmount,
                    'variance_amount' => $variance,
                ],
                'SPOT_AUDIT',
                $auditNotes
            );

            return $spotAudit;
        });
    }

    /**
     * Verify manager credentials.
     */
    public function verifyManagerCredentials(string $email, string $password, string $tenantId): User
    {
        $manager = User::where('tenant_id', $tenantId)
            ->where('email', $email)
            ->where('status', 'active')
            ->first();

        if (!$manager || !Hash::check($password, $manager->password)) {
            throw new \RuntimeException('Invalid manager credentials.');
        }

        return $manager;
    }
}
