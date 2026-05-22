<?php

namespace App\Services\Shift;

use App\Models\CashDrawerEvent;
use App\Models\PaymentReversal;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ShiftAccountabilityQueryService
{
    /**
     * Generate cashier accountability report payload for one shift.
     *
     * @param Shift $shift
     * @param User $viewer
     * @return array
     * @throws AuthorizationException
     */
    public function forShift(Shift $shift, User $viewer): array
    {
        // 1. Tenant and Branch Isolation Checks
        $this->assertTenantIsolation($shift);
        $this->assertBranchIsolation($shift, $viewer);

        // 2. Role-Based Access Control (RBAC) Checks
        $this->assertRolePermissions($shift, $viewer);

        // 3. Define Shift Upper Bound for Temporal Overlap
        $upperBound = $shift->closed_at 
            ?? $shift->closing_submitted_at 
            ?? now();

        // 4. Query Reversals (Temporal Overlap)
        // Voids
        $voidsQuery = PaymentReversal::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('branch_id', $shift->branch_id)
            ->where('reversal_type', 'void_reversal')
            ->where('reversed_at', '>=', $shift->opened_at)
            ->where('reversed_at', '<', $upperBound);

        $voidsTotal = '0.0000';
        foreach ($voidsQuery->get() as $rev) {
            $voidsTotal = bcadd($voidsTotal, (string) $rev->amount, 4);
        }

        // Refunds (All methods)
        $refundsQuery = PaymentReversal::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('branch_id', $shift->branch_id)
            ->where('reversal_type', 'refund_reversal')
            ->where('reversed_at', '>=', $shift->opened_at)
            ->where('reversed_at', '<', $upperBound);

        $refundsTotal = '0.0000';
        foreach ($refundsQuery->get() as $rev) {
            $refundsTotal = bcadd($refundsTotal, (string) $rev->amount, 4);
        }

        // Refunds (Cash method only, for Expected Cash formula)
        $cashRefundsQuery = PaymentReversal::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('branch_id', $shift->branch_id)
            ->where('reversal_type', 'refund_reversal')
            ->where('reversed_at', '>=', $shift->opened_at)
            ->where('reversed_at', '<', $upperBound)
            ->whereHas('salePayment', function ($q) {
                $q->where('payment_type', 'cash');
            });

        $cashRefundsTotal = '0.0000';
        foreach ($cashRefundsQuery->get() as $rev) {
            $cashRefundsTotal = bcadd($cashRefundsTotal, (string) $rev->amount, 4);
        }

        // 5. Query Sales and Discounts (Linked via payments of this shift)
        $salesQuery = Sale::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('branch_id', $shift->branch_id)
            ->where('is_reversal', false)
            ->whereHas('payments', function ($q) use ($shift) {
                $q->where('shift_id', $shift->id);
            });

        $grossSales = '0.0000';
        $discounts = '0.0000';
        foreach ($salesQuery->get() as $sale) {
            $grossSales = bcadd($grossSales, (string) ($sale->gross_sales_amount ?? '0.0000'), 4);
            $discounts = bcadd($discounts, (string) ($sale->discount_total ?? '0.0000'), 4);
        }

        // 6. Net Sales Calculation
        // Net Sales = Gross Sales - Discounts - Refunds - Voids
        $netSales = bcsub($grossSales, $discounts, 4);
        $netSales = bcsub($netSales, $refundsTotal, 4);
        $netSales = bcsub($netSales, $voidsTotal, 4);

        // 7. Query Sale Payments
        $payments = SalePayment::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('branch_id', $shift->branch_id)
            ->where('shift_id', $shift->id)
            ->where('status', 'paid')
            ->get();

        $cashSales = '0.0000';
        $nonCashSales = '0.0000';
        $methodBreakdown = [];

        foreach ($payments as $pay) {
            $payAmount = (string) $pay->amount;
            $methodCode = strtolower($pay->payment_type);

            if ($methodCode === 'cash') {
                $cashSales = bcadd($cashSales, $payAmount, 4);
            } else {
                $nonCashSales = bcadd($nonCashSales, $payAmount, 4);
            }

            if (!isset($methodBreakdown[$methodCode])) {
                $methodBreakdown[$methodCode] = [
                    'code' => $methodCode,
                    'name' => ucfirst($methodCode),
                    'total' => '0.0000',
                    'count' => 0,
                ];
            }
            $methodBreakdown[$methodCode]['total'] = bcadd($methodBreakdown[$methodCode]['total'], $payAmount, 4);
            $methodBreakdown[$methodCode]['count']++;
        }

        $paymentMixByMethod = array_values($methodBreakdown);

        // 8. Query Cash Drawer Events
        $events = CashDrawerEvent::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('branch_id', $shift->branch_id)
            ->where('shift_id', $shift->id)
            ->get();

        $cashIn = '0.0000';
        $cashOut = '0.0000';
        foreach ($events as $event) {
            $evtAmount = (string) $event->amount;
            if (in_array($event->event_type, ['cash_in', 'cash_top_up'])) {
                $cashIn = bcadd($cashIn, $evtAmount, 4);
            } elseif (in_array($event->event_type, ['cash_drop', 'cash_out'])) {
                $cashOut = bcadd($cashOut, $evtAmount, 4);
            }
        }

        $drawerEventCount = $events->count();

        // 9. Source-of-Truth Resolution for Expected Cash & Variance
        $isClosed = in_array($shift->status, [Shift::STATUS_CLOSED, Shift::STATUS_APPROVED]);

        if ($isClosed && $shift->expected_cash_amount !== null) {
            $expectedCash = (string) $shift->expected_cash_amount;
            $variance = $shift->variance_amount !== null ? (string) $shift->variance_amount : null;
        } else {
            // Live / Open calculations
            // Expected Cash = Opening Cash + Cash In - Cash Out + Cash Sales - Refunds (Cash only)
            $expectedCash = (string) ($shift->opening_cash_amount ?? '0.0000');
            $expectedCash = bcadd($expectedCash, $cashIn, 4);
            $expectedCash = bcsub($expectedCash, $cashOut, 4);
            $expectedCash = bcadd($expectedCash, $cashSales, 4);
            $expectedCash = bcsub($expectedCash, $cashRefundsTotal, 4);

            if ($shift->counted_cash_amount !== null) {
                $variance = bcsub((string) $shift->counted_cash_amount, $expectedCash, 4);
            } else {
                $variance = null;
            }
        }

        // 10. Drawer Timeline Details
        $drawerTimeline = CashDrawerEvent::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('branch_id', $shift->branch_id)
            ->where('shift_id', $shift->id)
            ->orderBy('occurred_at', 'asc')
            ->get()
            ->map(function (CashDrawerEvent $event) {
                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'amount' => $this->formatDecimal($event->amount),
                    'reason_code' => $event->reason_code,
                    'reason_notes' => $event->reason_notes,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ];
            })
            ->all();

        // 11. Assemble final read-only payload
        return [
            'shift' => [
                'id' => $shift->id,
                'status' => $shift->status,
            ],
            'cashier' => [
                'id' => $shift->cashier_id,
                'name' => $shift->cashier?->name,
            ],
            'branch' => [
                'id' => $shift->branch_id,
                'name' => $shift->branch?->name,
            ],
            'timeline' => [
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'closing_submitted_at' => $shift->closing_submitted_at?->toIso8601String(),
                'closed_at' => $shift->closed_at?->toIso8601String(),
                'approved_at' => $shift->approved_at?->toIso8601String(),
            ],
            'sales_summary' => [
                'gross_sales' => $this->formatDecimal($grossSales),
                'discounts' => $this->formatDecimal($discounts),
                'refunds' => $this->formatDecimal($refundsTotal),
                'voids' => $this->formatDecimal($voidsTotal),
                'net_sales' => $this->formatDecimal($netSales),
            ],
            'payment_mix' => [
                'cash_sales' => $this->formatDecimal($cashSales),
                'non_cash_sales' => $this->formatDecimal($nonCashSales),
                'by_method' => $paymentMixByMethod,
            ],
            'drawer_summary' => [
                'opening_cash' => $this->formatDecimal($shift->opening_cash_amount),
                'cash_in' => $this->formatDecimal($cashIn),
                'cash_out' => $this->formatDecimal($cashOut),
                'drawer_event_count' => $drawerEventCount,
            ],
            'cash_variance' => [
                'expected_cash' => $this->formatDecimal($expectedCash),
                'declared_cash' => $shift->counted_cash_amount !== null ? $this->formatDecimal($shift->counted_cash_amount) : null,
                'variance' => $variance !== null ? $this->formatDecimal($variance) : null,
            ],
            'drawer_timeline' => $drawerTimeline,
            'reversal_summary' => [
                'refunds' => $this->formatDecimal($refundsTotal),
                'voids' => $this->formatDecimal($voidsTotal),
                'cash_refunds' => $this->formatDecimal($cashRefundsTotal),
            ],
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'generated_by' => $viewer->name,
            ],
        ];
    }

    /**
     * Format values to exactly 4 decimal places.
     */
    protected function formatDecimal(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }

    /**
     * Enforce strict tenant context resolution.
     */
    protected function assertTenantIsolation(Shift $shift): void
    {
        $resolvedTenantId = app()->bound(\App\Services\TenantContext::class) 
            ? app(\App\Services\TenantContext::class)->getTenantId() 
            : null;

        if ($resolvedTenantId && $shift->tenant_id !== $resolvedTenantId) {
            throw new AuthorizationException('Cross-tenant cashier accountability access denied.');
        }
    }

    /**
     * Enforce branch context isolation limits.
     */
    protected function assertBranchIsolation(Shift $shift, User $viewer): void
    {
        if ($viewer->hasPermission('view_multi_branch_dashboard')) {
            return;
        }

        $allowedBranchIds = $viewer->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all();
        if (!in_array((string) $shift->branch_id, $allowedBranchIds, true)) {
            throw new AuthorizationException('Branch scope access denied for this shift report.');
        }
    }

    /**
     * Enforce standard RBAC permissions.
     */
    protected function assertRolePermissions(Shift $shift, User $viewer): void
    {
        if ($viewer->id !== $shift->cashier_id) {
            if (!$viewer->hasPermission('reports.shift-summary.view')) {
                throw new AuthorizationException('Unauthorized: Missing permission reports.shift-summary.view to inspect another cashier.');
            }
        } else {
            if (!$viewer->hasPermission('reports.cashier-accountability.view') && !$viewer->hasPermission('reports.shift-summary.view')) {
                throw new AuthorizationException('Unauthorized: Missing view permission for cashier accountability report.');
            }
        }
    }
}
