<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesHistoryQueryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesHistoryController extends Controller
{
    public function __construct(
        protected SalesHistoryQueryService $queryService,
        protected \App\Services\Sales\SalesHistoryExportService $exportService,
        protected \App\Services\AuditLogger $auditLogger
    ) {}

    /**
     * Display the sales history index.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only([
            'start_date', 'end_date', 'branch_id', 'status', 
            'payment_method_id', 'cashier_id', 'search', 'per_page'
        ]);

        $sales = $this->queryService
            ->query($request->user(), $filters)
            ->through(fn ($sale) => [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'client_request_uuid' => $sale->client_request_uuid,
                'status' => $sale->status,
                'branch_name' => $sale->branch?->name,
                'cashier_name' => $sale->user?->name,
                'terminal_identifier' => $sale->salesMachineProfile?->terminal_identifier
                    ?: $sale->salesMachineProfile?->profile_code,
                'total' => $sale->total,
                'confirmed_at' => optional($sale->confirmed_at)->toDateTimeString(),
                'created_at' => optional($sale->created_at)->toDateTimeString(),
                'reporting_basis_at' => optional($sale->reporting_basis_at)->toDateTimeString(),
            ]);

        return Inertia::render('Sales/History/Index', [
            'sales' => $sales,
            'filters' => $filters,
            'meta' => [
                'can_view_details' => $request->user()->hasPermission('view_sale_details'),
                'can_export' => $request->user()->hasPermission('export_sales_history'),
                'semantics' => 'Audit log view of existing transactions. This page is read-only and does not edit, repost, recalculate, void, refund, or settle transactions.',
            ]
        ]);
    }

    /**
     * Display the details of a specific sale.
     */
    public function show(string $saleId, Request $request): Response
    {
        $sale = $this->queryService->find($request->user(), $saleId);

        return Inertia::render('Sales/History/Show', [
            'sale' => $sale
        ]);
    }

    /**
     * Export the filtered sales history as CSV.
     */
    public function export(Request $request)
    {
        if (!$request->user()->hasPermission('export_sales_history')) {
            abort(403);
        }

        $filters = $request->only([
            'start_date', 'end_date', 'branch_id', 'status', 
            'payment_method_id', 'cashier_id', 'search'
        ]);

        $queryBuilder = $this->queryService->getBuilder($request->user(), $filters);
        
        // Count for audit metadata
        $totalRows = $queryBuilder->count();

        $csvContent = $this->exportService->generate($queryBuilder);

        // Audit Logging
        $this->auditLogger->log(
            'transaction_history_exported',
            null,
            null,
            null,
            null,
            null,
            [
                'filters' => $filters,
                'row_count' => $totalRows,
                'format' => 'csv'
            ]
        );

        $filename = 'sales-history-' . now()->format('Y-m-d-His') . '.csv';

        return response($csvContent)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename={$filename}");
    }
}
