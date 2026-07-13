<?php

namespace App\Services\Shift;

use App\Models\Shift;
use App\Models\User;
use App\Models\CashDrawerEvent;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Support\Facades\Hash;

class CashDropService
{
    public function __construct(
        protected ShiftService $shiftService,
        protected AuditLogger $auditLogger,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Resolve the cash drawer limit threshold for the branch or tenant.
     */
    public function resolveThreshold(?string $branchId = null): float
    {
        $branchId = $branchId ?? ($this->branchContext->hasBranch() ? $this->branchContext->getBranchId() : null);
        
        if ($branchId) {
            $branch = \App\Models\Branch::find($branchId);
            if ($branch && $branch->cash_drawer_limit !== null) {
                return (float) $branch->cash_drawer_limit;
            }
        }

        $tenant = $this->tenantContext->getTenant();
        if ($tenant && $tenant->default_cash_drawer_limit !== null) {
            return (float) $tenant->default_cash_drawer_limit;
        }

        return 5000.00; // Config fallback
    }

    /**
     * Check if a shift's expected cash exceeds the threshold.
     */
    public function isThresholdExceeded(Shift $shift): bool
    {
        $expectedCash = $this->shiftService->calculateExpectedCash($shift);
        $threshold = $this->resolveThreshold($shift->branch_id);

        return bccomp($expectedCash, (string) $threshold, 4) > 0;
    }

    /**
     * Record a cash drop (vault transfer).
     */
    public function recordCashDrop(
        Shift $shift,
        User $actor,
        string $amount,
        string $reasonCode,
        ?string $reasonNotes = null,
        ?string $managerEmail = null,
        ?string $managerPassword = null
    ): CashDrawerEvent {
        $tenantId = $this->tenantContext->getTenantId();

        // Check if shift is open
        if ($shift->status !== Shift::STATUS_OPEN) {
            throw new \RuntimeException('Cannot record event for a closed shift.');
        }

        // Resolve threshold limit
        $threshold = $this->resolveThreshold($shift->branch_id);
        $isHighValue = bccomp($amount, (string) $threshold, 4) > 0;

        $reason = \App\Models\CashDrawerReason::where('tenant_id', $tenantId)
            ->where('event_type', 'cash_drop')
            ->where('code', $reasonCode)
            ->where(function ($query) use ($shift) {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $shift->branch_id);
            })
            ->active()
            ->orderByRaw('branch_id IS NULL')
            ->first();

        $reasonsExist = \App\Models\CashDrawerReason::where('tenant_id', $tenantId)
            ->where('event_type', 'cash_drop')
            ->where(function ($query) use ($shift) {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $shift->branch_id);
            })
            ->exists();

        if ($reasonsExist && !$reason) {
            throw new \RuntimeException('Invalid or inactive cash drawer reason code.');
        }

        $requiresApproval = $isHighValue || ($reason && $reason->requires_manager_approval);

        $eventActor = $actor;

        if ($requiresApproval) {
            // Manager verification is required
            if (!$managerEmail || !$managerPassword) {
                // Log warning event before throwing exception
                $this->auditLogger->log(
                    'drawer_threshold_exceeded',
                    $shift,
                    null,
                    [
                        'shift_id' => $shift->id,
                        'amount' => $amount,
                        'threshold' => $threshold,
                    ],
                    'CASH_DROP',
                    'Drawer threshold exceeded or reason requires manager authorization.'
                );
                $msg = $isHighValue
                    ? 'Unauthorized: high-value cash drop requires manager approval.'
                    : 'Unauthorized: this cash drop requires manager approval.';
                throw new \RuntimeException($msg);
            }

            // Verify manager credentials
            $manager = User::where('tenant_id', $tenantId)
                ->where('email', $managerEmail)
                ->where('status', 'active')
                ->first();

            if (!$manager || !Hash::check($managerPassword, $manager->password)) {
                throw new \RuntimeException('Invalid manager credentials.');
            }

            // Verify manager has manage_cash_drawer permission
            if (!$manager->hasPermission('manage_cash_drawer')) {
                throw new \RuntimeException('Unauthorized: manager missing required permissions.');
            }

            // Cashier self-approval block (cannot self-approve if cashier is the shift owner)
            if ($shift->cashier_id === $manager->id) {
                $this->auditLogger->log(
                    'cash_drop_self_approval_blocked',
                    $shift,
                    null,
                    [
                        'shift_id' => $shift->id,
                        'manager_id' => $manager->id,
                        'amount' => $amount,
                    ],
                    'CASH_DROP',
                    'Self-approval blocked for high-value cash drop.'
                );
                throw new \RuntimeException('Security Block: Cashiers cannot approve their own high-value cash drop.');
            }

            // Manager verified successfully!
            $eventActor = $manager;

            $this->auditLogger->log(
                'cash_drop_manager_verified',
                $shift,
                null,
                [
                    'shift_id' => $shift->id,
                    'manager_id' => $manager->id,
                    'amount' => $amount,
                ],
                'CASH_DROP',
                'Manager verified for high-value cash drop.'
            );
        } else {
            // Below threshold drop. Ensure actor has permission.
            if (!$actor->hasPermission('manage_cash_drawer')) {
                throw new \RuntimeException('Unauthorized: missing manage_cash_drawer permission.');
            }

            // Ensure shift ownership if not manager
            if ($shift->cashier_id !== $actor->id && !$actor->hasPermission('approve_shift')) {
                throw new \RuntimeException('Unauthorized: shift belongs to another cashier.');
            }
        }

        // Call ShiftService to record the drawer event
        $event = $this->shiftService->recordDrawerEvent(
            $shift,
            $eventActor,
            CashDrawerEvent::TYPE_CASH_DROP,
            $amount,
            $reasonCode,
            $reasonNotes
        );

        $this->auditLogger->log(
            'cash_drop_recorded',
            $event,
            null,
            [
                'event_id' => $event->id,
                'shift_id' => $shift->id,
                'amount' => $amount,
                'created_by' => $eventActor->id,
            ],
            'CASH_DROP',
            $reasonNotes
        );

        return $event;
    }
}
