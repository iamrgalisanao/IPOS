<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Sales\ProductMixReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ProductMixReportController extends Controller
{
    public function __construct(
        protected ProductMixReportService $productMixReport,
    ) {}

    public function index(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $report = $this->productMixReport->build($request->user(), $filters);

        return Inertia::render('Reports/ProductMix/Index', [
            ...$report,
            'meta' => [
                'can_export' => $request->user()->hasPermission('export_sales_history'),
                'semantics' => 'Read-only product performance report from existing sale item records. No product, tax, settlement, accounting, inventory, or transaction state changes are performed.',
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = $this->validatedFilters($request);
        $csv = $this->productMixReport->exportCsv($request->user(), $filters);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="product-mix-report-'.now()->format('Y-m-d-His').'.csv"',
        ]);
    }

    protected function validatedFilters(Request $request): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['nullable', 'uuid'],
            'category_id' => [
                'nullable',
                'uuid',
                Rule::exists('product_categories', 'id')->where('tenant_id', $request->user()->tenant_id),
            ],
            'product_search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['paid', 'created', 'pending', 'draft', 'voided', 'refunded'])],
        ]);
    }
}
