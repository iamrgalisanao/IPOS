<?php

namespace App\Services\Tax;

use App\Models\User;
use Illuminate\Support\Carbon;

class ComplianceExportPackageService
{
    public function __construct(
        protected SalesTaxReportingQueryService $queryService
    ) {}

    /**
     * Prepare a compliance export package contract from the reporting query service.
     *
     * This service establishes the data structure for exports without generating files.
     * It ensures redaction of sensitive data and preserves filter criteria.
     */
    public function preparePackage(
        string $tenantId,
        string $dateFrom,
        string $dateTo,
        ?string $branchId = null,
        ?User $generatedBy = null
    ): array {
        $summary = $this->queryService->summarize($tenantId, $dateFrom, $dateTo, $branchId);

        return [
            'metadata' => [
                'generated_at' => Carbon::now()->toIso8601String(),
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'generated_by' => $generatedBy?->name ?? 'System',
                'source' => 'sales_tax_reporting_query_service',
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'branch_id' => $branchId,
            ],
            'summary' => [
                'gross_sales' => $summary['gross_sales'],
                'net_sales' => $summary['net_sales'],
                'vatable_sales' => $summary['vatable_sales'],
                'vat_exempt_sales' => $summary['vat_exempt_sales'],
                'zero_rated_sales' => $summary['zero_rated_sales'],
                'non_vat_sales' => $summary['non_vat_sales'],
                'vat_amount' => $summary['vat_amount'],
                'statutory_discount_amount' => $summary['statutory_discount_amount'],
                'regular_discount_amount' => $summary['regular_discount_amount'],
                'void_adjustment_amount' => $summary['void_adjustment_amount'],
                'refund_adjustment_amount' => $summary['refund_adjustment_amount'],
                'reversal_adjustment_amount' => $summary['reversal_adjustment_amount'],
                'net_adjustment_amount' => $summary['net_adjustment_amount'],
                'transaction_count' => $summary['transaction_count'],
            ],
            'notes' => [
                'This export is system-generated from IPOS reporting data.',
                'This does not represent standalone BIR certification.',
            ],
        ];
    }
}
