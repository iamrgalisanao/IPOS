<?php

namespace App\Services\POS\OfflineReadiness;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TaxCategory;
use App\Models\ProductCategory;
use App\Models\BranchProductPricing;
use App\Models\Product;
use App\Models\SalesMachineProfile;
use App\Services\CatalogService;
use App\Services\TenantContext;
use App\Services\BranchContext;

class CacheBootstrapService
{
    public function __construct(
        protected CatalogService $catalogService,
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Generate the complete read-only bootstrap cache payload.
     */
    public function generatePayload(Tenant $tenant, Branch $branch, $user): array
    {
        $originalTenant = $this->tenantContext->getTenant();
        $originalBranch = $this->branchContext->getBranch();

        $this->tenantContext->setTenant($tenant);
        $this->branchContext->setBranch($branch);

        try {
            // 1. Fetch active products shaped for POS
            $products = $this->catalogService->search('');

            // 2. Fetch categories lookup data
            $categories = ProductCategory::active()
                ->where('tenant_id', $tenant->id)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'description'])
                ->toArray();

            // 3. Fetch active tax categories/rules
            $taxCategories = TaxCategory::active()
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'tax_type', 'rate', 'is_default'])
                ->toArray();

            // 4. Tenant and Branch display context
            $tenantContextData = [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'currency' => $tenant->currency,
                'timezone' => $tenant->timezone,
                'tax_mode' => $tenant->tax_mode,
                'offline_sales_enabled' => (bool) $tenant->offline_sales_enabled,
                'business_registration_number' => $tenant->business_registration_number,
                'receipt_header' => $tenant->receipt_header,
                'receipt_footer' => $tenant->receipt_footer,
            ];

            $branchContextData = [
                'id' => $branch->id,
                'name' => $branch->name,
                'branch_code' => $branch->branch_code,
                'address' => $branch->address,
                'contact_number' => $branch->contact_number,
                'timezone' => $branch->getTimezone(),
                'receipt_prefix' => $branch->receipt_prefix,
                'receipt_next_number' => $branch->receipt_next_number,
                'offline_sales_enabled' => (bool) $branch->offline_sales_enabled,
                'inventory_deduction_policy' => $branch->inventory_deduction_policy,
            ];

            // 5. Sales machine profile display context
            $machineProfile = SalesMachineProfile::where('branch_id', $branch->id)
                ->where('status', 'active')
                ->first();

            $machineProfileContext = $machineProfile ? [
                'id' => $machineProfile->id,
                'profile_code' => $machineProfile->profile_code,
                'machine_identification_number' => $machineProfile->machine_identification_number,
                'machine_serial_number' => $machineProfile->machine_serial_number,
                'software_license_number' => $machineProfile->software_license_number,
                'permit_to_use_number' => $machineProfile->permit_to_use_number,
                'terminal_identifier' => $machineProfile->terminal_identifier,
                'status' => $machineProfile->status,
                'offline_sales_enabled' => $machineProfile->offline_sales_enabled,
                'offline_sequence_prefix' => $machineProfile->offline_sequence_prefix,
                'offline_sequence_next_value' => $machineProfile->offline_sequence_next_value,
                'offline_sequence_status' => $machineProfile->offline_sequence_status,
                'last_offline_sync_at' => $machineProfile->last_offline_sync_at?->toIso8601String(),
            ] : null;

            // 6. Cashier permissions needed by the POS runtime
            $permissions = [];
            if ($user) {
                $permissions = $user->roles()
                    ->with('permissions')
                    ->get()
                    ->pluck('permissions')
                    ->flatten()
                    ->pluck('name')
                    ->unique()
                    ->values()
                    ->all();
            }

            // 7. Calculate version hashes
            $taxHash = $this->calculateTaxConfigHash($tenant->id, $branch->id);
            $catalogHash = $this->calculateCatalogVersionHash($tenant->id, $branch->id);

            return [
                'products' => $products->toArray(),
                'categories' => $categories,
                'tax_categories' => $taxCategories,
                'tenant_context' => $tenantContextData,
                'branch_context' => $branchContextData,
                'machine_profile_context' => $machineProfileContext,
                'permissions' => $permissions,
                'tax_configuration_version_hash' => $taxHash,
                'catalog_version_hash' => $catalogHash,
                'generated_at' => now()->toIso8601String(),
                'cache_ttl_seconds' => 3600,
            ];
        } finally {
            if ($originalTenant) {
                $this->tenantContext->setTenant($originalTenant);
            } else {
                $this->tenantContext->clear();
            }

            if ($originalBranch) {
                $this->branchContext->setBranch($originalBranch);
            } else {
                $this->branchContext->clear();
            }
        }
    }

    /**
     * Compute a canonical SHA-256 hash of all tax-affecting configurations.
     */
    public function calculateTaxConfigHash(string $tenantId, string $branchId): string
    {
        // 1. Tax categories
        $taxCategories = TaxCategory::active()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'code', 'tax_type', 'rate', 'is_default', 'updated_at'])
            ->map(fn($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'tax_type' => $t->tax_type,
                'rate' => number_format((float) $t->rate, 4, '.', ''),
                'is_default' => (bool)$t->is_default,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ])
            ->toArray();

        // 2. Tenant tax settings
        $tenant = Tenant::find($tenantId);
        $tenantTaxSettings = $tenant ? [
            'tax_mode' => $tenant->tax_mode,
            'currency' => $tenant->currency,
            'updated_at' => $tenant->updated_at?->toIso8601String(),
        ] : [];

        $serialized = json_encode([
            'tax_categories' => $taxCategories,
            'tenant_settings' => $tenantTaxSettings,
        ]);

        return hash('sha256', $serialized);
    }

    /**
     * Compute a canonical SHA-256 hash of the product catalog configuration.
     */
    public function calculateCatalogVersionHash(string $tenantId, string $branchId): string
    {
        // 1. Categories
        $categories = ProductCategory::active()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'updated_at'])
            ->map(fn($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ])
            ->toArray();

        // 2. Products
        $products = Product::active()
            ->where('is_sellable', true)
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'selling_price', 'status', 'updated_at'])
            ->map(fn($p) => [
                'id' => $p->id,
                'selling_price' => number_format((float) $p->selling_price, 4, '.', ''),
                'status' => $p->status,
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])
            ->toArray();

        // 3. Branch pricing overrides
        $branchPricingOverrides = BranchProductPricing::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('product_id')
            ->get(['product_id', 'selling_price', 'status', 'updated_at'])
            ->map(fn($bp) => [
                'product_id' => $bp->product_id,
                'selling_price' => number_format((float) $bp->selling_price, 4, '.', ''),
                'status' => $bp->status,
                'updated_at' => $bp->updated_at?->toIso8601String(),
            ])
            ->toArray();

        $serialized = json_encode([
            'categories' => $categories,
            'products' => $products,
            'branch_pricing_overrides' => $branchPricingOverrides,
        ]);

        return hash('sha256', $serialized);
    }
}
