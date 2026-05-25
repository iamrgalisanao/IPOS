<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ProductCategory;
use App\Services\Inventory\InventoryVisibilityReportService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class InventoryVisibilityReportController extends Controller
{
    public function __construct(
        protected InventoryVisibilityReportService $reportService,
        protected TenantContext $tenantContext,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $validated = $this->validateFilters($request);
        $branches = $this->resolveAccessibleBranches($user);
        $this->authorizeSelectedBranch($validated, $branches);

        $filters = $this->normalizeFilters($validated);
        $report = $this->reportService->build($user, $filters, $branches);

        $categories = ProductCategory::query()
            ->active()
            ->whereHas('products.branchInventories', function ($query) use ($branches) {
                $query->active()->whereIn('branch_id', $branches->pluck('id'));
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Inventory/Visibility/Index', [
            ...$report,
            'filters' => $this->displayFilters($filters),
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
            ])->values(),
            'categories' => $categories,
            'meta' => [
                'can_export' => $user->hasPermission('view_inventory_reports') || $user->hasPermission('audit_inventory'),
                'semantics' => 'Read-only inventory visibility report from existing stock, expiry, movement, and sale-item records. No stock, procurement, recipe, or workflow changes are performed.',
                'expiry_risk_days' => InventoryVisibilityReportService::EXPIRY_RISK_DAYS,
                'slow_moving_days' => InventoryVisibilityReportService::SLOW_MOVING_DAYS,
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
        $csv = $this->reportService->exportCsv($user, $filters, $branches);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="inventory-visibility-report-'.now()->format('Y-m-d-His').'.csv"',
        ]);
    }

    private function validateFilters(Request $request): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $request->validate([
            'branch_id' => [
                'nullable',
                'uuid',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'category_id' => [
                'nullable',
                'uuid',
                Rule::exists('product_categories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'search' => ['nullable', 'string', 'max:255'],
            'low_stock_only' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
            'expiry_risk_only' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
        ]);
    }

    private function normalizeFilters(array $validated): array
    {
        return [
            'branch_id' => $validated['branch_id'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'search' => $validated['search'] ?? '',
            'low_stock_only' => filter_var($validated['low_stock_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'expiry_risk_only' => filter_var($validated['expiry_risk_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function displayFilters(array $filters): array
    {
        return [
            'branch_id' => $filters['branch_id'] ?? '',
            'category_id' => $filters['category_id'] ?? '',
            'search' => $filters['search'] ?? '',
            'low_stock_only' => (bool) ($filters['low_stock_only'] ?? false),
            'expiry_risk_only' => (bool) ($filters['expiry_risk_only'] ?? false),
        ];
    }

    private function authorizeSelectedBranch(array $validated, Collection $branches): void
    {
        $selectedBranchId = $validated['branch_id'] ?? null;
        if ($selectedBranchId && !$branches->pluck('id')->contains($selectedBranchId)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to the selected branch.');
        }
    }

    private function resolveAccessibleBranches($user): Collection
    {
        if ($user->hasPermission('view_multi_branch_dashboard')) {
            return Branch::query()->active()->orderBy('name')->get(['id', 'name']);
        }

        return $user->branches()->active()->orderBy('name')->get(['branches.id', 'branches.name']);
    }
}
