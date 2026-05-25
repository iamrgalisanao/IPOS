<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesTimingReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SalesTimingReportController extends Controller
{
    public function __construct(
        protected SalesTimingReportService $salesTimingReport,
    ) {}

    public function index(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $report = $this->salesTimingReport->build($request->user(), $filters);

        return Inertia::render('Reports/SalesTiming/Index', [
            ...$report,
            'meta' => [
                'can_export' => $request->user()->hasPermission('export_sales_history'),
                'semantics' => 'Read-only sales timing report from existing sales records. No forecasting, staffing, tax, settlement, accounting, or transaction state changes are performed.',
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = $this->validatedFilters($request);
        $csv = $this->salesTimingReport->exportCsv($request->user(), $filters);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sales-timing-report-'.now()->format('Y-m-d-His').'.csv"',
        ]);
    }

    protected function validatedFilters(Request $request): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', Rule::in(['paid', 'created', 'pending', 'draft', 'voided', 'refunded'])],
            'cashier_id' => ['nullable', 'uuid'],
        ]);
    }
}
