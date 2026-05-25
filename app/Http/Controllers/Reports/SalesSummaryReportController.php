<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesSummaryReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SalesSummaryReportController extends Controller
{
    public function __construct(
        protected SalesSummaryReportService $salesSummaryReport,
    ) {}

    public function index(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $report = $this->salesSummaryReport->build($request->user(), $filters);

        return Inertia::render('Reports/SalesSummary/Index', [
            ...$report,
            'meta' => [
                'can_export' => $request->user()->hasPermission('export_sales_history'),
                'semantics' => 'Read-only sales summary from existing sales records. No settlement, tax, receipt, accounting, or transaction state changes are performed.',
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = $this->validatedFilters($request);
        $csv = $this->salesSummaryReport->exportCsv($request->user(), $filters);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sales-summary-report-'.now()->format('Y-m-d-His').'.csv"',
        ]);
    }

    protected function validatedFilters(Request $request): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', Rule::in(['paid', 'created', 'pending', 'draft', 'voided', 'refunded'])],
            'payment_method_id' => ['nullable', 'uuid'],
            'cashier_id' => ['nullable', 'uuid'],
        ]);
    }
}
