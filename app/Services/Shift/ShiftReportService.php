<?php

namespace App\Services\Shift;

use App\Models\Shift;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;

class ShiftReportService
{
    /**
     * Generate a comprehensive shift summary for reporting.
     * 
     * This service aggregates data from immutable sales, payments, and drawer events.
     * It does NOT mutate any records.
     */
    public function generateSummary(Shift $shift, bool $includeSensitivity = false): array
    {
        // 1. Resolve Sale IDs from Shift Payments
        // This is the source of truth for which sales belong to this shift.
        $saleIds = SalePayment::where('shift_id', $shift->id)
            ->pluck('sale_id')
            ->unique()
            ->values()
            ->all();

        // 2. Aggregate Sales Totals
        // We use the already recorded financial fields to ensure consistency.
        $salesSummary = Sale::whereIn('id', $saleIds)
            ->where('tenant_id', $shift->tenant_id)
            ->select([
                DB::raw('SUM(gross_sales_amount) as gross_sales'),
                DB::raw('SUM(vatable_sales_amount) as vatable_sales'),
                DB::raw('SUM(vat_exempt_sales_amount) as vat_exempt_sales'),
                DB::raw('SUM(zero_rated_sales_amount) as zero_rated_sales'),
                DB::raw('SUM(non_vat_sales_amount) as non_vat_sales'),
                DB::raw('SUM(vat_amount) as vat_amount'),
                DB::raw('SUM(statutory_discount_total) as statutory_discounts'),
                DB::raw('SUM(commercial_discount_total) as commercial_discounts'),
                DB::raw('SUM(total) as net_total'),
                DB::raw('COUNT(id) as transaction_count'),
            ])->first();

        // 3. Payment Method Breakdown
        $paymentBreakdown = SalePayment::where('shift_id', $shift->id)
            ->join('payment_methods', 'payment_methods.id', '=', 'sale_payments.payment_method_id')
            ->select('payment_methods.name', DB::raw('SUM(sale_payments.amount) as total'), DB::raw('COUNT(sale_payments.id) as count'))
            ->groupBy('payment_methods.name')
            ->get();

        // 4. Cash Drawer Events Summary
        $drawerEvents = $shift->cashDrawerEvents()
            ->select('event_type', DB::raw('SUM(amount) as total'), DB::raw('COUNT(id) as count'))
            ->groupBy('event_type')
            ->get()
            ->keyBy('event_type');

        // 5. Construct the Report Object
        $report = [
            'meta' => [
                'generated_at' => now()->toDateTimeString(),
                'is_certified' => false,
                'disclaimer' => 'NON-CERTIFIED REPORT — This shift report is for internal operational reconciliation only and is not a BIR-certified tax report.',
            ],
            'shift' => [
                'id' => $shift->id,
                'cashier_name' => $shift->cashier->name,
                'branch_name' => $shift->branch->name,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'status' => $shift->status,
                'opening_cash' => $this->formatDecimal($shift->opening_cash_amount),
                'counted_cash' => $this->formatDecimal($shift->counted_cash_amount),
                'opening_denominations' => $shift->opening_denominations,
                'closing_denominations' => $shift->closing_denominations,
            ],
            'sales' => [
                'gross_sales' => $this->formatDecimal($salesSummary->gross_sales),
                'net_total' => $this->formatDecimal($salesSummary->net_total),
                'transaction_count' => (int) $salesSummary->transaction_count,
                'tax_breakdown' => [
                    'vatable' => $this->formatDecimal($salesSummary->vatable_sales),
                    'vat_amount' => $this->formatDecimal($salesSummary->vat_amount),
                    'exempt' => $this->formatDecimal($salesSummary->vat_exempt_sales),
                    'zero_rated' => $this->formatDecimal($salesSummary->zero_rated_sales),
                    'non_vat' => $this->formatDecimal($salesSummary->non_vat_sales),
                ],
                'discount_breakdown' => [
                    'statutory' => $this->formatDecimal($salesSummary->statutory_discounts),
                    'commercial' => $this->formatDecimal($salesSummary->commercial_discounts),
                    'total' => $this->formatDecimal(bcadd($salesSummary->statutory_discounts ?? '0', $salesSummary->commercial_discounts ?? '0', 4)),
                ],
            ],
            'payments' => $paymentBreakdown->map(fn($p) => [
                'method' => $p->name,
                'total' => $this->formatDecimal($p->total),
                'count' => (int) $p->count,
            ]),
            'drawer_activity' => [
                'cash_drops' => $this->formatEvent($drawerEvents->get('cash_drop')),
                'cash_top_ups' => $this->formatEvent($drawerEvents->get('cash_top_up')),
                'cash_in' => $this->formatEvent($drawerEvents->get('cash_in')),
                'cash_out' => $this->formatEvent($drawerEvents->get('cash_out')),
            ],
        ];

        // 6. Conditional Sensitivity Redaction
        if ($includeSensitivity) {
            $report['reconciliation'] = [
                'expected_cash' => $this->formatDecimal($shift->expected_cash_amount),
                'counted_cash' => $this->formatDecimal($shift->counted_cash_amount),
                'variance' => $this->formatDecimal($shift->variance_amount),
            ];
        }

        return $report;
    }

    protected function formatDecimal($value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }

    protected function formatEvent($event): array
    {
        return [
            'total' => $this->formatDecimal($event->total ?? 0),
            'count' => (int) ($event->count ?? 0),
        ];
    }
}
