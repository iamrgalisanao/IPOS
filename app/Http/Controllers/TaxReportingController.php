<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\POS\EJournalExportService;
use App\Services\Tax\ComplianceCsvExportService;
use App\Services\Tax\ComplianceExportPackageService;
use App\Services\Tax\SalesTaxReportingQueryService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxReportingController extends Controller
{
    public function __construct(
        protected SalesTaxReportingQueryService $queryService,
        protected ComplianceExportPackageService $packageService,
        protected ComplianceCsvExportService $csvService,
        protected EJournalExportService $ejournalService
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $resolvedBranchId = $this->resolveBranchScope($request, $filters['branch_id']);
        $tenantId = app(TenantContext::class)->getTenantId() ?? (string) $request->user()->tenant_id;

        return Inertia::render('Reports/Tax/Index', [
            'filters' => [
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'branch_id' => $resolvedBranchId,
            ],
            'branches' => $this->availableBranches($request),
            'canViewAllBranches' => $request->user()->hasPermission('view_multi_branch_dashboard'),
            'sections' => $this->sections(),
            'summary' => $this->queryService->summarize(
                $tenantId,
                $filters['date_from'].' 00:00:00',
                $filters['date_to'].' 23:59:59',
                $resolvedBranchId,
            ),
        ]);
    }

    public function exportCsv(Request $request): \Illuminate\Http\Response
    {
        $filters = $this->filters($request);
        $resolvedBranchId = $this->resolveBranchScope($request, $filters['branch_id']);
        $tenantId = app(TenantContext::class)->getTenantId() ?? (string) $request->user()->tenant_id;

        $package = $this->packageService->preparePackage(
            $tenantId,
            $filters['date_from'].' 00:00:00',
            $filters['date_to'].' 23:59:59',
            $resolvedBranchId,
            $request->user()
        );

        $csvContent = $this->csvService->generate($package);

        $filename = sprintf(
            'ipos-tax-compliance-summary-%s-to-%s.csv',
            $filters['date_from'],
            $filters['date_to']
        );

        return response($csvContent)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    public function exportEJournal(Request $request): \Illuminate\Http\Response
    {
        $filters = $this->filters($request);
        $resolvedBranchId = $this->resolveBranchScope($request, $filters['branch_id']);
        $tenantId = app(TenantContext::class)->getTenantId() ?? (string) $request->user()->tenant_id;

        $journalContent = $this->ejournalService->export([
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'branch_id' => $resolvedBranchId,
            'sales_machine_profile_id' => $filters['sales_machine_profile_id'],
        ]);

        $filename = sprintf(
            'ipos-electronic-journal-%s-to-%s.txt',
            $filters['date_from'],
            $filters['date_to']
        );

        return response($journalContent)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    protected function filters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'string'],
            'sales_machine_profile_id' => ['nullable', 'string'],
        ]);

        $today = now()->toDateString();
        $dateFrom = $validated['date_from'] ?? ($validated['date_to'] ?? $today);
        $dateTo = $validated['date_to'] ?? ($validated['date_from'] ?? $today);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'branch_id' => $validated['branch_id'] ?? null,
            'sales_machine_profile_id' => $validated['sales_machine_profile_id'] ?? null,
        ];
    }

    protected function resolveBranchScope(Request $request, ?string $requestedBranchId): ?string
    {
        $allowedBranchIds = $this->allowedBranchIds($request);

        if ($allowedBranchIds !== null && $allowedBranchIds === []) {
            abort(403, 'Branch scope access denied for tax reporting.');
        }

        if ($requestedBranchId !== null) {
            if ($allowedBranchIds !== null && !in_array($requestedBranchId, $allowedBranchIds, true)) {
                abort(404);
            }

            $branch = Branch::query()
                ->where('status', 'active')
                ->find($requestedBranchId);

            abort_if(!$branch, 404);

            return (string) $branch->id;
        }

        if ($allowedBranchIds !== null) {
            return $allowedBranchIds[0] ?? null;
        }

        return null;
    }

    protected function availableBranches(Request $request): array
    {
        $query = Branch::query()->where('status', 'active')->orderBy('name');
        $allowedBranchIds = $this->allowedBranchIds($request);

        if ($allowedBranchIds !== null) {
            $query->whereIn('id', $allowedBranchIds);
        }

        return $query->get(['id', 'name'])->map(fn (Branch $branch) => [
            'id' => $branch->id,
            'name' => $branch->name,
        ])->all();
    }

    protected function allowedBranchIds(Request $request): ?array
    {
        if ($request->user()->hasPermission('view_multi_branch_dashboard')) {
            return null;
        }

        return $request->user()
            ->branches()
            ->pluck('branches.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    protected function sections(): array
    {
        return [
            [
                'id' => 'sales_summary',
                'title' => 'Sales Summary',
                'description' => 'High-level sales totals for the selected reporting window.',
                'items' => [
                    ['key' => 'gross_sales', 'label' => 'Gross sales'],
                    ['key' => 'net_sales', 'label' => 'Net sales'],
                    ['key' => 'transaction_count', 'label' => 'Transactions'],
                ],
            ],
            [
                'id' => 'tax_buckets',
                'title' => 'Tax Bucket Breakdown',
                'description' => 'Read-only bucket totals exactly as returned by the backend reporting contract.',
                'items' => [
                    ['key' => 'vatable_sales', 'label' => 'Vatable sales'],
                    ['key' => 'vat_exempt_sales', 'label' => 'VAT exempt sales'],
                    ['key' => 'zero_rated_sales', 'label' => 'Zero-rated sales'],
                    ['key' => 'non_vat_sales', 'label' => 'Non-VAT sales'],
                    ['key' => 'vat_amount', 'label' => 'VAT amount'],
                ],
            ],
            [
                'id' => 'discount_breakdown',
                'title' => 'Discount Breakdown',
                'description' => 'Displayed directly from the reporting contract without frontend recomputation.',
                'items' => [
                    ['key' => 'statutory_discount_amount', 'label' => 'Statutory discounts'],
                    ['key' => 'regular_discount_amount', 'label' => 'Regular discounts'],
                ],
            ],
            [
                'id' => 'adjustment_breakdown',
                'title' => 'Adjustment / Reversal Breakdown',
                'description' => 'Adjustment totals and counts remain separate from base sales totals.',
                'items' => [
                    ['key' => 'void_adjustment_amount', 'label' => 'Void adjustments'],
                    ['key' => 'refund_adjustment_amount', 'label' => 'Refund adjustments'],
                    ['key' => 'reversal_adjustment_amount', 'label' => 'Reversal adjustments'],
                    ['key' => 'net_adjustment_amount', 'label' => 'Net adjustments'],
                    ['key' => 'void_count', 'label' => 'Void count'],
                    ['key' => 'refund_count', 'label' => 'Refund count'],
                    ['key' => 'reversal_count', 'label' => 'Reversal count'],
                ],
            ],
            [
                'id' => 'review_lock_awareness',
                'title' => 'Review / Lock Awareness',
                'description' => 'Read-only indicators for overlapping reviewed and locked reporting periods.',
                'items' => [
                    ['key' => 'reviewed_period_count', 'label' => 'Reviewed periods'],
                    ['key' => 'locked_period_count', 'label' => 'Locked periods'],
                    ['key' => 'has_reviewed_period', 'label' => 'Reviewed period detected'],
                    ['key' => 'has_locked_period', 'label' => 'Locked period detected'],
                ],
            ],
        ];
    }
}