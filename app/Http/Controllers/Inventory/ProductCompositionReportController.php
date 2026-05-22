<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ProductCategory;
use App\Services\Inventory\ProductCompositionReportService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ProductCompositionReportController extends Controller
{
    public function __construct(
        protected ProductCompositionReportService $reportService,
        protected TenantContext $tenantContext
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $validated = $this->validateFilters($request);
        $branches = $this->resolveAccessibleBranches($user);
        $this->authorizeSelectedBranch($validated, $branches);

        $filters = $this->normalizeFilters($validated);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $rows = $this->reportService->paginate($filters, $perPage);

        $canViewCosts = $user?->hasPermission('audit_inventory') ?? false;
        if (!$canViewCosts) {
            $rows->setCollection($this->maskCostFields($rows->getCollection()));
        }

        $categories = ProductCategory::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Inventory/ProductComposition/Index', [
            'rows' => $rows,
            'filters' => $filters,
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
            ])->values(),
            'categories' => $categories,
            'meta' => [
                'slice' => 'B',
            ],
            'semantics' => [
                'mode' => $filters['expansion_mode'],
                'mode_semantics' => $filters['expansion_mode'] === 'flatten_subrecipes'
                    ? 'planning_only'
                    : 'matches_live_deduction',
                'banner' => $filters['expansion_mode'] === 'flatten_subrecipes'
                    ? 'Flattened mode is planning-only. Live POS currently deducts direct recipe components only.'
                    : null,
            ],
            'permissions' => [
                'can_view_costs' => $canViewCosts,
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $user = $request->user();
        $validated = $this->validateFilters($request);
        $branches = $this->resolveAccessibleBranches($user);
        $this->authorizeSelectedBranch($validated, $branches);

        $filters = $this->normalizeFilters($validated);
        $rows = $this->reportService->exportRows($filters);

        $maxRows = (int) config('reports.product_composition_export_max_rows', env('REPORT_EXPORT_MAX_ROWS', 10000));
        if ($rows->count() > $maxRows) {
            return back()->withErrors([
                'export' => "Export contains {$rows->count()} rows, above the {$maxRows} row limit. Narrow filters and try again.",
            ]);
        }

        $canViewCosts = $user?->hasPermission('audit_inventory') ?? false;
        if (!$canViewCosts) {
            $rows = $this->maskCostFields($rows);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="product_composition_' . now()->format('Ymd_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows) {
            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'Parent SKU',
                'Parent Name',
                'Ingredient SKU',
                'Ingredient Name',
                'Ingredient Type',
                'Direct Qty',
                'Direct Unit',
                'Effective Qty (Base)',
                'Base Unit',
                'Depth',
                'Path',
                'Conversion Status',
                'Mode Semantics',
                'Branch Stock',
                'Branch Reorder Level',
                'Branch Avg Cost',
                'Fallback Cost',
                'Cost Status',
                'Effective Ingredient Cost / Parent Unit',
                'Coverage (Ingredient)',
                'Coverage (Parent Bottleneck)',
                'Recursion Status',
            ]);

            foreach ($rows as $row) {
                fputcsv($file, [
                    $this->escapeCsvValue($row['parent_product_sku'] ?? ''),
                    $this->escapeCsvValue($row['parent_product_name'] ?? ''),
                    $this->escapeCsvValue($row['ingredient_sku'] ?? ''),
                    $this->escapeCsvValue($row['ingredient_name'] ?? ''),
                    $this->escapeCsvValue($row['ingredient_product_type'] ?? ''),
                    $this->escapeCsvValue($row['direct_quantity'] ?? ''),
                    $this->escapeCsvValue($row['direct_unit'] ?? ''),
                    $this->escapeCsvValue($row['effective_quantity_base'] ?? ''),
                    $this->escapeCsvValue($row['ingredient_base_unit'] ?? ''),
                    $this->escapeCsvValue($row['depth'] ?? ''),
                    $this->escapeCsvValue($row['path_signature'] ?? ''),
                    $this->escapeCsvValue($row['conversion_status'] ?? ''),
                    $this->escapeCsvValue($row['mode_semantics'] ?? ''),
                    $this->escapeCsvValue($row['branch_current_stock'] ?? ''),
                    $this->escapeCsvValue($row['branch_reorder_level'] ?? ''),
                    $this->escapeCsvValue($row['branch_average_cost'] ?? ''),
                    $this->escapeCsvValue($row['fallback_cost_price'] ?? ''),
                    $this->escapeCsvValue($row['cost_status'] ?? ''),
                    $this->escapeCsvValue($row['effective_cost_per_parent_unit'] ?? ''),
                    $this->escapeCsvValue($row['coverage_ingredient_parent_units'] ?? ''),
                    $this->escapeCsvValue($row['coverage_parent_bottleneck_units'] ?? ''),
                    $this->escapeCsvValue($row['recursion_status'] ?? ''),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, Response::HTTP_OK, $headers);
    }

    private function validateFilters(Request $request): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'uuid',
                Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'product_type' => ['nullable', Rule::in(['finished_good', 'semi_finished', 'raw_material'])],
            'branch_id' => [
                'nullable',
                'uuid',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'expansion_mode' => ['nullable', Rule::in(['direct_only', 'flatten_subrecipes'])],
            'max_depth' => ['nullable', 'integer', 'min:1', 'max:10'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function normalizeFilters(array $validated): array
    {
        return array_merge(
            [
                'search' => '',
                'category_id' => null,
                'product_type' => null,
                'branch_id' => null,
                'expansion_mode' => 'direct_only',
                'max_depth' => 5,
            ],
            $validated
        );
    }

    private function authorizeSelectedBranch(array $validated, Collection $branches): void
    {
        $selectedBranchId = $validated['branch_id'] ?? null;
        if ($selectedBranchId && !$branches->pluck('id')->contains($selectedBranchId)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to the selected branch.');
        }
    }

    private function maskCostFields(Collection $rows): Collection
    {
        return $rows->map(function (array $row): array {
            $row['branch_average_cost'] = null;
            $row['fallback_cost_price'] = null;
            $row['cost_status'] = null;
            $row['effective_cost_per_parent_unit'] = null;

            return $row;
        });
    }

    private function escapeCsvValue($value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@'], true)) {
            return "'" . $str;
        }

        return $str;
    }

    private function resolveAccessibleBranches($user)
    {
        if ($user->hasPermission('view_multi_branch_dashboard')) {
            return Branch::query()->active()->orderBy('name')->get(['id', 'name']);
        }

        $assigned = $user->branches()->active()->orderBy('name')->get(['branches.id', 'branches.name']);
        if ($assigned->isNotEmpty()) {
            return $assigned;
        }

        return collect();
    }
}
