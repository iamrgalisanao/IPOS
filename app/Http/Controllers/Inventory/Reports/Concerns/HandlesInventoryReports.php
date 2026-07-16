<?php

namespace App\Http\Controllers\Inventory\Reports\Concerns;

use App\Models\ProductCategory;
use App\Services\Inventory\Reports\InventoryCsvExportService;
use App\Services\Inventory\Reports\InventoryReportFilter;
use App\Services\Inventory\Reports\InventoryReportScopeService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

trait HandlesInventoryReports
{
    protected function renderReport(Request $request, object $service, string $title, string $reportKey, bool $auditExport = false)
    {
        $filter = $this->filterFrom($request);
        $scope = app(InventoryReportScopeService::class);
        $branchIds = $scope->selectedBranchIds($request->user(), $filter->get('branch_id'));
        $report = $service->build($filter, $branchIds);

        return Inertia::render('Inventory/Reports/Index', [
            'title' => $title,
            'reportKey' => $reportKey,
            'filters' => $filter->all(),
            'branches' => $scope->branchOptions($request->user()),
            'categories' => ProductCategory::query()->active()->orderBy('name')->get(['id', 'name']),
            'rows' => $report['rows'],
            'summary' => $report['summary'] ?? [],
            'meta' => array_merge($report['meta'] ?? [], [
                'can_export' => !$auditExport || $request->user()->hasPermission('audit_inventory'),
                'audit_export_required' => $auditExport,
            ]),
        ]);
    }

    protected function exportReport(Request $request, object $service, string $title, string $reportKey, bool $auditExport = false): Response
    {
        if ($auditExport && !$request->user()->hasPermission('audit_inventory')) {
            abort(Response::HTTP_FORBIDDEN, 'Audit permission is required to export this report.');
        }

        $filter = $this->filterFrom($request);
        $scope = app(InventoryReportScopeService::class);
        $branchIds = $scope->selectedBranchIds($request->user(), $filter->get('branch_id'));
        $report = $service->build($filter, $branchIds);
        $headers = $this->headersFor($report['rows']);
        $filename = $reportKey.'-'.now()->format('Y-m-d-His').'.csv';
        $metadata = array_merge($report['meta'] ?? [], [
            'report_type' => $reportKey,
            'tenant_id' => app(TenantContext::class)->getTenantId(),
            'filename' => $filename,
        ]);

        $csv = app(InventoryCsvExportService::class)->make(
            $title,
            $headers,
            $report['rows'],
            $metadata,
            $request->user(),
            $auditExport,
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    protected function filterFrom(Request $request): InventoryReportFilter
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        $validated = $request->validate([
            'branch_id' => [
                'nullable',
                'uuid',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'product_id' => [
                'nullable',
                'uuid',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'category_id' => [
                'nullable',
                'uuid',
                Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'stock_state' => ['nullable', Rule::in(['all', 'normal', 'low', 'negative'])],
            'status' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', Rule::in(['all', 'configuration', 'integrity'])],
            'show_reconciled' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
            'search' => ['nullable', 'string', 'max:255'],
            'cursor' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $validated['show_reconciled'] = filter_var($validated['show_reconciled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $validated['stock_state'] = ($validated['stock_state'] ?? null) === 'all' ? null : ($validated['stock_state'] ?? null);
        $validated['type'] ??= 'all';

        return InventoryReportFilter::from($validated);
    }

    private function headersFor(array $rows): array
    {
        if ($rows === []) {
            return ['message'];
        }

        return array_keys($rows[0]);
    }
}
