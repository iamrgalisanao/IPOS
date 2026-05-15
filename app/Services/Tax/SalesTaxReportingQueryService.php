<?php

namespace App\Services\Tax;

use App\Models\SettlementPeriod;
use Illuminate\Support\Facades\DB;

class SalesTaxReportingQueryService
{
    public function summarize(string $tenantId, string $dateFrom, string $dateTo, ?string $branchId = null): array
    {
        $salesQuery = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereRaw('COALESCE(reporting_basis_at, confirmed_at) >= ?', [$dateFrom])
            ->whereRaw('COALESCE(reporting_basis_at, confirmed_at) <= ?', [$dateTo]);

        $saleItemsQuery = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', $tenantId)
            ->when($branchId, fn ($query) => $query->where('sales.branch_id', $branchId))
            ->whereRaw('COALESCE(sales.reporting_basis_at, sales.confirmed_at) >= ?', [$dateFrom])
            ->whereRaw('COALESCE(sales.reporting_basis_at, sales.confirmed_at) <= ?', [$dateTo]);

        $discountsQuery = DB::table('sale_statutory_discounts')
            ->join('sales', 'sales.id', '=', 'sale_statutory_discounts.sale_id')
            ->where('sales.tenant_id', $tenantId)
            ->when($branchId, fn ($query) => $query->where('sales.branch_id', $branchId))
            ->whereRaw('COALESCE(sales.reporting_basis_at, sales.confirmed_at) >= ?', [$dateFrom])
            ->whereRaw('COALESCE(sales.reporting_basis_at, sales.confirmed_at) <= ?', [$dateTo]);

        $refundsQuery = DB::table('sale_refunds')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('refunded_at', '>=', $dateFrom)
            ->where('refunded_at', '<=', $dateTo);

        $voidsQuery = DB::table('sale_voids')
            ->join('sales', 'sales.id', '=', 'sale_voids.sale_id')
            ->where('sale_voids.tenant_id', $tenantId)
            ->when($branchId, fn ($query) => $query->where('sale_voids.branch_id', $branchId))
            ->where('sale_voids.voided_at', '>=', $dateFrom)
            ->where('sale_voids.voided_at', '<=', $dateTo);

        $paymentReversalsQuery = DB::table('payment_reversals')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('reversed_at', '>=', $dateFrom)
            ->where('reversed_at', '<=', $dateTo);

        $settlementPeriodsQuery = DB::table('settlement_periods')
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('period_start_at', '<=', $dateTo)
            ->where('period_end_at', '>=', $dateFrom);

        $grossSales = $this->decimalString((clone $salesQuery)->sum(DB::raw('COALESCE(gross_sales_amount, total)')));
        $vatableSales = $this->decimalString((clone $saleItemsQuery)->sum('sale_items.vatable_amount'));
        $vatExemptSales = $this->decimalString((clone $saleItemsQuery)->sum('sale_items.vat_exempt_amount'));
        $zeroRatedSales = $this->decimalString((clone $saleItemsQuery)->sum('sale_items.zero_rated_amount'));
        $nonVatSales = $this->decimalString((clone $saleItemsQuery)->sum('sale_items.non_vat_amount'));
        $vatAmount = $this->decimalString((clone $salesQuery)->sum('vat_amount'));
        $statutoryDiscountAmount = $this->decimalString((clone $discountsQuery)->sum('sale_statutory_discounts.discount_amount'));
        $regularDiscountAmount = $this->decimalString((clone $salesQuery)->sum('commercial_discount_total'));
        $refundAdjustmentAmount = $this->decimalString((clone $refundsQuery)->sum('refund_total'));
        $voidAdjustmentAmount = $this->decimalString((clone $voidsQuery)->sum('sales.total'));
        $reversalAdjustmentAmount = $this->decimalString((clone $paymentReversalsQuery)->sum('amount'));
        $netAdjustmentAmount = $this->decimalString(
            bcadd(
                bcadd($refundAdjustmentAmount, $voidAdjustmentAmount, 4),
                $reversalAdjustmentAmount,
                4
            )
        );
        $refundCount = (int) (clone $refundsQuery)->count();
        $voidCount = (int) (clone $voidsQuery)->count('sale_voids.id');
        $reversalCount = (int) (clone $paymentReversalsQuery)->count();
        $reviewedPeriodCount = (int) (clone $settlementPeriodsQuery)
            ->whereIn('status', [SettlementPeriod::STATUS_IN_REVIEW, SettlementPeriod::STATUS_APPROVED])
            ->count();
        $lockedPeriodCount = (int) (clone $settlementPeriodsQuery)
            ->where('status', SettlementPeriod::STATUS_LOCKED)
            ->count();

        $netSales = $this->decimalString(
            bcsub(
                bcsub(
                    bcsub($grossSales, $statutoryDiscountAmount, 4),
                    $regularDiscountAmount,
                    4
                ),
                bcadd($refundAdjustmentAmount, $voidAdjustmentAmount, 4),
                4
            )
        );

        return array_replace($this->summaryContract($tenantId, $dateFrom, $dateTo, $branchId), [
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExemptSales,
            'zero_rated_sales' => $zeroRatedSales,
            'non_vat_sales' => $nonVatSales,
            'vat_amount' => $vatAmount,
            'statutory_discount_amount' => $statutoryDiscountAmount,
            'regular_discount_amount' => $regularDiscountAmount,
            'refund_adjustment_amount' => $refundAdjustmentAmount,
            'void_adjustment_amount' => $voidAdjustmentAmount,
            'reversal_adjustment_amount' => $reversalAdjustmentAmount,
            'net_adjustment_amount' => $netAdjustmentAmount,
            'refund_count' => $refundCount,
            'void_count' => $voidCount,
            'reversal_count' => $reversalCount,
            'reviewed_period_count' => $reviewedPeriodCount,
            'locked_period_count' => $lockedPeriodCount,
            'has_reviewed_period' => $reviewedPeriodCount > 0,
            'has_locked_period' => $lockedPeriodCount > 0,
            'transaction_count' => (int) (clone $salesQuery)->count(),
        ]);
    }

    protected function summaryContract(string $tenantId, string $dateFrom, string $dateTo, ?string $branchId): array
    {
        return [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'gross_sales' => '0.0000',
            'net_sales' => '0.0000',
            'vatable_sales' => '0.0000',
            'vat_exempt_sales' => '0.0000',
            'zero_rated_sales' => '0.0000',
            'non_vat_sales' => '0.0000',
            'vat_amount' => '0.0000',
            'statutory_discount_amount' => '0.0000',
            'regular_discount_amount' => '0.0000',
            'refund_adjustment_amount' => '0.0000',
            'void_adjustment_amount' => '0.0000',
            'reversal_adjustment_amount' => '0.0000',
            'net_adjustment_amount' => '0.0000',
            'refund_count' => 0,
            'void_count' => 0,
            'reversal_count' => 0,
            'reviewed_period_count' => 0,
            'locked_period_count' => 0,
            'has_reviewed_period' => false,
            'has_locked_period' => false,
            'transaction_count' => 0,
        ];
    }

    protected function decimalString(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 4, '.', '');
    }
}