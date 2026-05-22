<?php

namespace App\Services\Shift;

use App\Models\Branch;
use App\Models\CashDrawerEvent;
use App\Models\Shift;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\BranchContext;
use App\Services\AuditLogger;
use App\Services\Settlement\SettlementPeriodService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    /**
     * Threshold for cash drops requiring manager approval.
     * Future enhancement: make this tenant-configurable.
     */
    public const CASH_DROP_APPROVAL_THRESHOLD = 5000.00;

    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext,
        protected AuditLogger $auditLogger,
        protected SettlementPeriodService $settlementPeriodService,
    ) {}

    /**
     * Approve a submitted shift count.
     */
    public function approveShift(
        Shift $shift,
        User $manager,
        ?string $managerNotes = null,
        ?CarbonInterface $approvedAt = null
    ): Shift {
        $approvedAt = $approvedAt ?? now();

        // 0. Settlement Lock Check
        $this->assertPeriodNotLocked($approvedAt, $shift->branch_id);

        // 1. RBAC Check
        if (! $manager->hasPermission('approve_shift')) {
            throw new \RuntimeException('Missing permission: approve_shift');
        }

        // 2. Status Check
        if ($shift->status !== Shift::STATUS_CLOSING_SUBMITTED) {
            throw new \RuntimeException("Shift cannot be approved. Current status: {$shift->status}. Expected: " . Shift::STATUS_CLOSING_SUBMITTED);
        }

        // 3. Self-Approval Guard
        if ($shift->cashier_id === $manager->id) {
            throw new \RuntimeException('Cashiers cannot approve their own shift.');
        }

        // 4. Isolation Checks
        $activeTenantId = $this->tenantContext->getTenantId();
        if ($shift->tenant_id !== $activeTenantId || $manager->tenant_id !== $activeTenantId) {
            throw new \RuntimeException('Cross-tenant shift approval blocked.');
        }

        if ($this->branchContext->hasBranch() && $shift->branch_id !== $this->branchContext->getBranchId()) {
            throw new \RuntimeException('Shift branch mismatch.');
        }

        // 5. Execution
        return DB::transaction(function () use ($shift, $manager, $managerNotes, $approvedAt) {
            $shift->update([
                'status' => Shift::STATUS_APPROVED,
                'approved_by' => $manager->id,
                'approved_at' => $approvedAt ?? now(),
                'manager_notes' => $managerNotes,
            ]);

            $this->auditLogger->log(
                'shift_approved',
                $shift,
                null,
                [
                    'shift_id' => $shift->id,
                    'branch_id' => $shift->branch_id,
                    'cashier_id' => $shift->cashier_id,
                    'approved_by' => $manager->id,
                    'approved_at' => $shift->approved_at,
                    'counted_cash_amount' => $shift->counted_cash_amount,
                    'expected_cash_amount' => $shift->expected_cash_amount,
                    'variance_amount' => $shift->variance_amount,
                    'has_manager_notes' => !empty($managerNotes),
                ],
                'SHIFT_RECONCILIATION',
                $managerNotes
            );

            return $shift;
        });
    }

    /**
     * Submit the closing cash count for a shift.
     */
     public function submitClosingCount(
        Shift $shift,
        User $actor,
        string $countedCashAmount,
        ?string $closingNotes = null,
        ?CarbonInterface $closingSubmittedAt = null,
        ?array $closingDenominations = null
    ): Shift {
        $closingSubmittedAt = $closingSubmittedAt ?? now();

        // 0. Settlement Lock Check
        $this->assertPeriodNotLocked($closingSubmittedAt, $shift->branch_id);

        // 1. Status check
        if ($shift->status !== Shift::STATUS_OPEN) {
            throw new \RuntimeException("Shift cannot be submitted for closing. Current status: {$shift->status}.");
        }

        // 2. Ownership check
        if ($shift->cashier_id !== $actor->id) {
            throw new \RuntimeException('Unauthorized: shift belongs to another cashier.');
        }

        // 3. Tenant/Branch isolation
        $activeTenantId = $this->tenantContext->getTenantId();
        if ($shift->tenant_id !== $activeTenantId) {
            throw new \RuntimeException('Cross-tenant shift access blocked.');
        }
        if ($this->branchContext->hasBranch() && $shift->branch_id !== $this->branchContext->getBranchId()) {
            throw new \RuntimeException('Shift branch mismatch.');
        }

        // 4. Amount validation
        if (!is_numeric($countedCashAmount) || bccomp($countedCashAmount, '0', 4) < 0) {
            throw new \InvalidArgumentException('Counted cash amount must be non-negative.');
        }

        return DB::transaction(function () use ($shift, $actor, $countedCashAmount, $closingNotes, $closingDenominations, $closingSubmittedAt) {
            $expectedCash = $this->calculateExpectedCash($shift);
            $variance = bcsub($countedCashAmount, $expectedCash, 4);

            $shift->update([
                'status' => Shift::STATUS_CLOSING_SUBMITTED,
                'counted_cash_amount' => $countedCashAmount,
                'expected_cash_amount' => $expectedCash,
                'variance_amount' => $variance,
                'closing_submitted_at' => $closingSubmittedAt ?? now(),
                'closing_notes' => $closingNotes,
                'closing_denominations' => $closingDenominations,
            ]);

            $this->auditLogger->log(
                'shift_closing_submitted',
                $shift,
                null,
                $shift->toArray(),
                'SHIFT_CLOSING',
                $closingNotes
            );

            return $shift;
        });
    }

    /**
     * Calculate the expected cash for a shift.
     */
    public function calculateExpectedCash(Shift $shift): string
    {
        $opening = (string) ($shift->opening_cash_amount ?? '0.0000');

        // Cash Payments (Additions)
        $cashPaymentsTotal = $shift->salePayments()
            ->whereHas('paymentMethod', function ($query) {
                $query->whereRaw('LOWER(code) = ?', ['cash']);
            })
            ->sum('amount') ?: '0.0000';

        // Operational Events
        $events = $shift->cashDrawerEvents()->get();
        
        $additions = $events->filter(fn($e) => in_array($e->event_type, [
            CashDrawerEvent::TYPE_CASH_IN,
            CashDrawerEvent::TYPE_CASH_TOP_UP
        ]))->sum('amount') ?: '0.0000';

        $deductions = $events->filter(fn($e) => in_array($e->event_type, [
            CashDrawerEvent::TYPE_CASH_DROP,
            CashDrawerEvent::TYPE_CASH_OUT
        ]))->sum('amount') ?: '0.0000';

        // Expected = Opening + Payments + In/Topup - Drop/Out - Refund(0)
        $total = bcadd($opening, (string) $cashPaymentsTotal, 4);
        $total = bcadd($total, (string) $additions, 4);
        $total = bcsub($total, (string) $deductions, 4);

        return $total;
    }

    /**
     * Record a cash drawer operational event.
     */
    public function recordDrawerEvent(
        Shift $shift,
        User $actor,
        string $eventType,
        string $amount,
        string $reasonCode,
        ?string $reasonNotes = null,
        ?CarbonInterface $occurredAt = null
    ): CashDrawerEvent {
        $occurredAt = $occurredAt ?? now();

        // 0. Settlement Lock Check
        $this->assertPeriodNotLocked($occurredAt, $shift->branch_id);

        // 1. Tenant/Branch isolation (Priority Guard)
        $activeTenantId = $this->tenantContext->getTenantId();
        if ($shift->tenant_id !== $activeTenantId) {
            throw new \RuntimeException('Cross-tenant shift access blocked.');
        }

        if ($this->branchContext->hasBranch() && $shift->branch_id !== $this->branchContext->getBranchId()) {
            throw new \RuntimeException('Shift branch mismatch.');
        }

        // 2. Permission check
        if (!$actor->hasPermission('manage_cash_drawer')) {
            throw new \RuntimeException('Unauthorized: missing manage_cash_drawer permission.');
        }

        // 3. Status check
        if ($shift->status !== Shift::STATUS_OPEN) {
            throw new \RuntimeException('Cannot record event for a closed shift.');
        }

        // 4. Ownership check (Cashier can only record on their own shift, but manager can override)
        if ($shift->cashier_id !== $actor->id && !$actor->hasPermission('approve_shift')) {
            throw new \RuntimeException('Unauthorized: shift belongs to another cashier.');
        }

        // 5. Event type validation
        $validTypes = [
            CashDrawerEvent::TYPE_CASH_DROP,
            CashDrawerEvent::TYPE_CASH_TOP_UP,
            CashDrawerEvent::TYPE_CASH_IN,
            CashDrawerEvent::TYPE_CASH_OUT,
        ];
        if (!in_array($eventType, $validTypes)) {
            throw new \InvalidArgumentException('Invalid drawer event type.');
        }

        // 6. Amount validation
        if (!is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new \InvalidArgumentException('Drawer event amount must be positive.');
        }

        // 6.1 Threshold Guard for Cash Drops
        if ($eventType === CashDrawerEvent::TYPE_CASH_DROP && bccomp($amount, (string) self::CASH_DROP_APPROVAL_THRESHOLD, 4) > 0) {
            if (!$actor->hasPermission('approve_shift')) {
                throw new \RuntimeException('Unauthorized: high-value cash drop requires manager approval.');
            }
            
            // Self-approval block for high-value drops
            if ($shift->cashier_id === $actor->id && $actor->hasPermission('approve_shift')) {
                // If the cashier has permission, they CAN approve, but we should log it as a risk.
                // However, the rule says "Cashier self-approval for high-value drop must be blocked".
                // I'll enforce it strictly: if they are the shift owner, they need ANOTHER manager to record it.
                throw new \RuntimeException('Security Block: Cashiers cannot approve their own high-value cash drop.');
            }
        }

        // 7. Reason code validation
        if (empty(trim($reasonCode))) {
            throw new \InvalidArgumentException('Reason code is required for drawer events.');
        }

        return DB::transaction(function () use ($shift, $actor, $eventType, $amount, $reasonCode, $reasonNotes, $occurredAt) {
            $event = CashDrawerEvent::create([
                'tenant_id' => $shift->tenant_id,
                'branch_id' => $shift->branch_id,
                'shift_id' => $shift->id,
                'cashier_id' => $shift->cashier_id,
                'event_type' => $eventType,
                'amount' => $amount,
                'reason_code' => $reasonCode,
                'reason_notes' => $reasonNotes,
                'created_by' => $actor->id,
                'occurred_at' => $occurredAt ?? now(),
            ]);

            $this->auditLogger->log(
                'cash_drawer_event_recorded',
                $event,
                null,
                $event->toArray(),
                $reasonCode,
                $reasonNotes
            );

            return $event;
        });
    }

    /**
     * Open a new shift for a cashier.
     */
    public function openShift(
        User $cashier,
        Branch $branch,
        string $openingCashAmount,
        User $openedBy,
        ?CarbonInterface $openedAt = null
    ): Shift {
        $openedAt = $openedAt ?? now();

        // 0. Settlement Lock Check
        $this->assertPeriodNotLocked($openedAt, $branch->id);

        $this->assertCanOpenShift($cashier, $branch, $openedBy);

        return DB::transaction(function () use ($cashier, $branch, $openingCashAmount, $openedBy, $openedAt) {
            $shift = Shift::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'cashier_id' => $cashier->id,
                'opened_by' => $openedBy->id,
                'status' => Shift::STATUS_OPEN,
                'opening_cash_amount' => $openingCashAmount,
                'opened_at' => $openedAt,
            ]);

            CashDrawerEvent::create([
                'tenant_id' => $shift->tenant_id,
                'branch_id' => $shift->branch_id,
                'shift_id' => $shift->id,
                'cashier_id' => $cashier->id,
                'event_type' => CashDrawerEvent::TYPE_OPENING_CASH,
                'amount' => $openingCashAmount,
                'reason_code' => 'INITIAL_OPENING',
                'created_by' => $openedBy->id,
                'occurred_at' => $shift->opened_at,
            ]);

            return $shift;
        });
    }

    /**
     * Get the current active shift for a cashier in a specific branch.
     */
    public function getActiveShiftFor(User $cashier, Branch $branch): ?Shift
    {
        return Shift::where('tenant_id', $branch->tenant_id)
            ->where('branch_id', $branch->id)
            ->where('cashier_id', $cashier->id)
            ->where('status', Shift::STATUS_OPEN)
            ->first();
    }

    /**
     * Check if a cashier has any active shift in any branch.
     */
    public function hasAnyActiveShift(User $cashier): bool
    {
        return Shift::where('tenant_id', $cashier->tenant_id)
            ->where('cashier_id', $cashier->id)
            ->where('status', Shift::STATUS_OPEN)
            ->exists();
    }

    /**
     * Assert and return the active shift for a cashier.
     */
    public function requireActiveShift(User $cashier, Branch $branch): Shift
    {
        $shift = $this->getActiveShiftFor($cashier, $branch);

        if (!$shift) {
            throw new \RuntimeException("No active shift found for cashier {$cashier->name} in branch {$branch->name}. Please open a shift before processing payments.");
        }

        return $shift;
    }

    /**
     * Assert that a shift can be opened.
     */
    public function assertCanOpenShift(User $cashier, Branch $branch, User $openedBy): void
    {
        // 1. Permission check
        if (!$openedBy->hasPermission('open_shift')) {
            throw new \RuntimeException('Unauthorized: missing open_shift permission.');
        }

        // 2. Tenant isolation
        $activeTenantId = $this->tenantContext->getTenantId();
        if ($branch->tenant_id !== $activeTenantId) {
            throw new \RuntimeException('Cross-tenant branch access blocked.');
        }
        if ($cashier->tenant_id !== $activeTenantId) {
            throw new \RuntimeException('Cross-tenant cashier access blocked.');
        }

        // 3. Branch assignment
        if (!$cashier->canAccessBranch($branch)) {
            throw new \RuntimeException('Cashier is not assigned to this branch.');
        }

        // 4. Duplicate shift in same branch
        if ($this->getActiveShiftFor($cashier, $branch)) {
            throw new \RuntimeException('Cashier already has an active shift in this branch.');
        }

        // 5. Active shift in another branch (MVP Rule)
        if ($this->hasAnyActiveShift($cashier)) {
            throw new \RuntimeException('Cashier already has an active shift in another branch.');
        }
    }

    /**
     * Ensure the period for the given timestamp is not locked.
     */
    protected function assertPeriodNotLocked(CarbonInterface $timestamp, ?string $branchId = null): void
    {
        if ($this->settlementPeriodService->isLockedForTimestamp($timestamp, $branchId)) {
            $dateStr = $timestamp->toDateString();
            throw new \RuntimeException("Operation blocked: Settlement period for {$dateStr} is locked.");
        }
    }
}
