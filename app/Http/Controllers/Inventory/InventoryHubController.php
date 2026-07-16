<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryHubController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tenant = $this->tenantContext->getTenant() ?? $user?->tenant;

        $sections = [
            [
                'key' => 'inventory_overview',
                'title' => 'Inventory Overview',
                'description' => 'Quick access to existing inventory and movement visibility routes.',
                'items' => [
                    $this->buildLink(
                        label: 'Inventory Dashboard',
                        description: 'Current branch inventory overview with stock health and movement summaries.',
                        routeName: 'inventory.dashboard.index',
                        available: $user->hasAnyPermission(['view_branch_inventory', 'inventory.stocktake.view']),
                        unavailableReason: 'Requires view_branch_inventory or inventory.stocktake.view.'
                    ),
                    $this->buildLink(
                        label: 'Inventory Movements',
                        description: 'Branch-level inventory movement visibility. Full UI is planned for a later slice.',
                        routeName: 'inventory.movements.index',
                        available: false,
                        unavailableReason: $user->hasPermission('view_branch_inventory')
                            ? 'Movement API exists; user-facing movement summary is planned for a later slice.'
                            : 'Requires view_branch_inventory.',
                        extra: [
                            'surface_type' => 'json_endpoint',
                        ]
                    ),
                ],
            ],
            [
                'key' => 'stocktake_operations',
                'title' => 'Stocktake Operations',
                'description' => 'Entry points for count cycles, review, and posting inside existing stocktake flows.',
                'items' => [
                    $this->buildLink(
                        label: 'Stocktake Sessions',
                        description: 'List and monitor stocktake sessions.',
                        routeName: 'inventory.stocktakes.index',
                        available: $user->hasPermission('inventory.stocktake.view'),
                        unavailableReason: 'Requires inventory.stocktake.view.'
                    ),
                    $this->buildLink(
                        label: 'Create Stocktake',
                        description: 'Start a new stocktake draft session.',
                        routeName: 'inventory.stocktakes.create',
                        available: $user->hasPermission('inventory.stocktake.create'),
                        unavailableReason: 'Requires inventory.stocktake.create.'
                    ),
                ],
            ],
            [
                'key' => 'reports_audit',
                'title' => 'Reports and Audit',
                'description' => 'Inventory-focused report surfaces and audit-sensitive visibility.',
                'items' => [
                    $this->buildLink(
                        label: 'Variance Logs',
                        description: 'Legacy variance lifecycle surface with mutation actions kept outside report generation.',
                        routeName: 'inventory.reports.variance-logs.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.'
                    ),
                    $this->buildLink(
                        label: 'Product Composition',
                        description: 'Analyze parent-to-ingredient structure and optional branch coverage context.',
                        routeName: 'inventory.reports.product-composition.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.',
                        extra: [
                            'cost_visible' => $user->hasPermission('audit_inventory'),
                        ]
                    ),
                    $this->buildLink(
                        label: 'Current Stock',
                        description: 'Current operational stock projection with revision and watermark metadata.',
                        routeName: 'inventory.reports.current-stock.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.'
                    ),
                    $this->buildLink(
                        label: 'Stock Card',
                        description: 'Sequence-ordered branch/product movement ledger.',
                        routeName: 'inventory.reports.stock-card.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.'
                    ),
                    $this->buildLink(
                        label: 'Movement Summary',
                        description: 'Business-date activity summary using captured movement watermarks.',
                        routeName: 'inventory.reports.movement-summary.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.'
                    ),
                    $this->buildLink(
                        label: 'Physical Count Variance',
                        description: 'Stocktake count variance evidence from posted count sessions.',
                        routeName: 'inventory.reports.physical-count-variance.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.'
                    ),
                    $this->buildLink(
                        label: 'Negative Stock Exceptions',
                        description: 'Audit view for soft-negative deduction exceptions and current lifecycle status.',
                        routeName: 'inventory.reports.negative-stock-exceptions.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.'
                    ),
                    $this->buildLink(
                        label: 'Reconciliation Exceptions',
                        description: 'Movement-derived stock versus operational stock with baseline status.',
                        routeName: 'inventory.reports.reconciliation-exceptions.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.'
                    ),
                    $this->buildLink(
                        label: 'Usage Reconciliation',
                        description: 'Expected-versus-recorded usage foundation without inventing missing expected evidence.',
                        routeName: 'inventory.reports.usage-reconciliation.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.'
                    ),
                    $this->buildLink(
                        label: 'Configuration and Integrity',
                        description: 'Separate setup gaps from evidence-chain integrity exceptions.',
                        routeName: 'inventory.reports.integrity.index',
                        available: $user->hasAnyPermission(['view_inventory_reports', 'audit_inventory']),
                        unavailableReason: 'Requires view_inventory_reports or audit_inventory.'
                    ),
                ],
            ],
            [
                'key' => 'catalog_recipe_setup',
                'title' => 'Catalog and Recipe Setup',
                'description' => 'Setup links for products, categories, recipes, and unit conversion rules.',
                'items' => [
                    $this->buildLink(
                        label: 'Product Catalog',
                        description: 'Manage products and recipe definitions through current catalog flows.',
                        routeName: 'admin.products.index',
                        available: $user->hasPermission('manage_products') && $this->tenantHasFeature($tenant, 'catalog.view'),
                        unavailableReason: 'Requires manage_products and catalog.view feature.'
                    ),
                    $this->buildLink(
                        label: 'Product Categories',
                        description: 'Maintain category structure used by inventory and reports.',
                        routeName: 'admin.product-categories.index',
                        available: $user->hasPermission('manage_products') && $this->tenantHasFeature($tenant, 'catalog.view'),
                        unavailableReason: 'Requires manage_products and catalog.view feature.'
                    ),
                    $this->buildLink(
                        label: 'Unit Conversions',
                        description: 'Maintain unit conversion factors used by composition and stock calculations.',
                        routeName: 'inventory.unit-conversions.index',
                        available: $user->hasPermission('manage_unit_conversions'),
                        unavailableReason: 'Requires manage_unit_conversions.'
                    ),
                ],
            ],
            [
                'key' => 'inbound_procurement',
                'title' => 'Inbound and Procurement',
                'description' => 'Link to existing supplier, PO, receiving, and returns workflows only.',
                'items' => [
                    $this->buildLink(
                        label: 'Suppliers',
                        description: 'Supplier setup and maintenance.',
                        routeName: 'procurement.suppliers.index',
                        available: $this->tenantHasFeature($tenant, 'procurement.basic') && $user->hasPermission('procurement.suppliers.view'),
                        unavailableReason: 'Requires procurement.basic feature and procurement.suppliers.view.'
                    ),
                    $this->buildLink(
                        label: 'Purchase Orders',
                        description: 'Inbound purchasing overview and lifecycle.',
                        routeName: 'procurement.purchase-orders.index',
                        available: $this->tenantHasFeature($tenant, 'procurement.basic') && $user->hasPermission('procurement.purchase-orders.view'),
                        unavailableReason: 'Requires procurement.basic feature and procurement.purchase-orders.view.'
                    ),
                    $this->buildLink(
                        label: 'Goods Receiving',
                        description: 'Record and review received stock transactions.',
                        routeName: 'procurement.receivings.index',
                        available: $this->tenantHasFeature($tenant, 'procurement.basic') && $user->hasPermission('procurement.receiving.view'),
                        unavailableReason: 'Requires procurement.basic feature and procurement.receiving.view.'
                    ),
                    $this->buildLink(
                        label: 'Supplier Returns',
                        description: 'Reverse-logistics workflow when advanced procurement is enabled.',
                        routeName: 'procurement.returns.index',
                        available: $this->tenantHasFeature($tenant, 'procurement.advanced') && $user->hasPermission('procurement.returns.view'),
                        unavailableReason: 'Requires procurement.advanced feature and procurement.returns.view.'
                    ),
                ],
            ],
        ];

        return Inertia::render('Inventory/Hub/Index', [
            'sections' => $sections,
            'meta' => [
                'is_read_only_hub' => true,
                'cost_visibility' => $user->hasPermission('audit_inventory') ? 'audit_only' : 'masked',
            ],
        ]);
    }

    private function buildLink(
        string $label,
        string $description,
        string $routeName,
        bool $available,
        string $unavailableReason,
        array $extra = []
    ): array {
        return array_merge([
            'label' => $label,
            'description' => $description,
            'route_name' => $routeName,
            'url' => $available ? route($routeName) : null,
            'available' => $available,
            'unavailable_reason' => $available ? null : $unavailableReason,
        ], $extra);
    }

    private function tenantHasFeature(?Tenant $tenant, string $feature): bool
    {
        return $tenant?->hasFeature($feature) ?? false;
    }
}
