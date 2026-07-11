<?php

namespace App\Services\POS\OfflineReadiness;

use App\Models\Branch;
use App\Models\DiscountType;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Models\TaxCategory;
use App\Models\ProductCategory;
use App\Models\BranchProductPricing;
use App\Models\Product;
use App\Models\PosLayout;
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
    public function generatePayload(Tenant $tenant, Branch $branch, $user, ?SalesMachineProfile $machineProfile = null): array
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
            if (!$machineProfile) {
                $machineProfile = SalesMachineProfile::where('branch_id', $branch->id)
                    ->where('status', 'active')
                    ->first();
            }

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
            $layoutHash = $this->calculateLayoutVersionHash($tenant->id, $branch->id, $machineProfile);
            $discountRulesHash = $this->calculateDiscountRulesVersionHash($tenant->id);
            $paymentMethodsHash = $this->calculatePaymentMethodsVersionHash($tenant->id, $branch->id);
            $terminalPolicyHash = $this->calculateTerminalPolicyVersionHash($tenant, $branch, $machineProfile);
            $printerProfileHash = $this->calculatePrinterProfileVersionHash($tenant->id, $branch->id, $machineProfile?->id);

            // Fetch branch-resolved payment methods list to cache in IndexedDB
            $paymentMethods = PaymentMethod::active()
                ->where('tenant_id', $tenant->id)
                ->orderBy('code')
                ->orderBy('id')
                ->get()
                ->map(fn (PaymentMethod $method) => $method->getSettingsForBranch($branch->id))
                ->toArray();

            $snapshot = [
                'schema_version' => 1,
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'sales_machine_profile_id' => $machineProfile?->id,
                'layout_version_hash' => $layoutHash,
                'catalog_version_hash' => $catalogHash,
                'tax_configuration_version_hash' => $taxHash,
                'discount_rules_version_hash' => $discountRulesHash,
                'payment_methods_version_hash' => $paymentMethodsHash,
                'terminal_policy_version_hash' => $terminalPolicyHash,
                'printer_profile_version_hash' => $printerProfileHash,
            ];

            $snapshotHash = $this->hashCanonical($snapshot);
            $snapshot['config_snapshot_hash'] = $snapshotHash;

            return [
                'products' => $products->toArray(),
                'categories' => $categories,
                'tax_categories' => $taxCategories,
                'payment_methods' => $paymentMethods,
                'tenant_context' => $tenantContextData,
                'branch_context' => $branchContextData,
                'machine_profile_context' => $machineProfileContext,
                'permissions' => $permissions,
                'tax_configuration_version_hash' => $taxHash,
                'catalog_version_hash' => $catalogHash,
                'layout_version_hash' => $layoutHash,
                'discount_rules_version_hash' => $discountRulesHash,
                'payment_methods_version_hash' => $paymentMethodsHash,
                'terminal_policy_version_hash' => $terminalPolicyHash,
                'printer_profile_version_hash' => $printerProfileHash,
                'config_snapshot_hash' => $snapshotHash,
                'config_snapshot' => $snapshot,
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

        return $this->hashCanonical([
            'tax_categories' => $taxCategories,
            'tenant_settings' => $tenantTaxSettings,
        ]);
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

        return $this->hashCanonical([
            'categories' => $categories,
            'products' => $products,
            'branch_pricing_overrides' => $branchPricingOverrides,
        ]);
    }

    public function calculateLayoutVersionHash(string $tenantId, string $branchId, ?SalesMachineProfile $profile = null): string
    {
        if ($profile?->pos_layout_id) {
            $terminalLayout = PosLayout::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $profile->pos_layout_id)
                ->where('status', PosLayout::STATUS_PUBLISHED)
                ->first();

            if ($terminalLayout) {
                return $this->calculateLayoutVersionHashFromLayout($terminalLayout);
            }
        }

        $layout = PosLayout::query()
            ->join('branch_pos_layout', 'branch_pos_layout.pos_layout_id', '=', 'pos_layouts.id')
            ->where('pos_layouts.tenant_id', $tenantId)
            ->where('branch_pos_layout.tenant_id', $tenantId)
            ->where('branch_pos_layout.branch_id', $branchId)
            ->where('branch_pos_layout.is_active', true)
            ->where('pos_layouts.status', PosLayout::STATUS_PUBLISHED)
            ->orderByDesc('branch_pos_layout.published_at')
            ->orderByDesc('branch_pos_layout.created_at')
            ->select([
                'pos_layouts.id',
                'pos_layouts.name',
                'pos_layouts.version',
                'pos_layouts.schema',
                'pos_layouts.status',
                'pos_layouts.updated_at',
                'branch_pos_layout.id as assignment_id',
                'branch_pos_layout.published_at',
                'branch_pos_layout.updated_at as assignment_updated_at',
            ])
            ->first();

        return $this->calculateLayoutVersionHashFromLayout($layout);
    }

    /**
     * Build a canonical layout version hash from a pre-resolved PosLayout model.
     *
     * This is the authoritative hash method. All consumers (TerminalLayoutResolver,
     * TerminalConfigDriftService, TerminalHeartbeatController) must call this to
     * guarantee consistent hashing regardless of how the layout was resolved.
     *
     * The hash covers layout identity AND content so that:
     *   - Content changes         → new hash
     *   - Different layout ID     → new hash (even if content is identical)
     *   - Layout archived/deleted → terminal detects drift on next heartbeat
     *
     * @param  PosLayout|null  $layout  Already-resolved layout (null when no layout exists)
     * @return string
     */
    public function calculateLayoutVersionHashFromLayout(?PosLayout $layout): string
    {
        return $this->hashCanonical([
            'layout' => $layout ? [
                'id'      => $layout->id,
                'name'    => $layout->name,
                'version' => (int) $layout->version,
                'status'  => $layout->status,
                'schema'  => $this->decodeJsonValue($layout->schema),
                'updated_at' => $this->isoDate($layout->updated_at),
                // assignment_id / published_at are present on branch-resolved rows;
                // terminal-override layouts may not carry them, so fall back to null.
                'assignment_id' => $layout->assignment_id ?? null,
                'published_at'  => isset($layout->published_at)
                    ? $this->isoDate($layout->published_at)
                    : null,
                'assignment_updated_at' => isset($layout->assignment_updated_at)
                    ? $this->isoDate($layout->assignment_updated_at)
                    : null,
            ] : null,
        ]);
    }

    public function calculateDiscountRulesVersionHash(string $tenantId): string
    {
        $discountTypes = DiscountType::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->orderBy('id')
            ->get([
                'id',
                'code',
                'name',
                'statutory_category',
                'default_rate',
                'vat_treatment',
                'requires_identity',
                'requires_approval',
                'applies_to_fnb',
                'applies_to_retail',
                'is_active',
                'updated_at',
            ])
            ->map(fn (DiscountType $discountType) => [
                'id' => $discountType->id,
                'code' => $discountType->code,
                'name' => $discountType->name,
                'statutory_category' => $discountType->statutory_category,
                'default_rate' => number_format((float) $discountType->default_rate, 4, '.', ''),
                'vat_treatment' => $discountType->vat_treatment,
                'requires_identity' => (bool) $discountType->requires_identity,
                'requires_approval' => (bool) $discountType->requires_approval,
                'applies_to_fnb' => (bool) $discountType->applies_to_fnb,
                'applies_to_retail' => (bool) $discountType->applies_to_retail,
                'is_active' => (bool) $discountType->is_active,
                'updated_at' => $discountType->updated_at?->toIso8601String(),
            ])
            ->toArray();

        return $this->hashCanonical([
            'discount_types' => $discountTypes,
        ]);
    }

    public function calculatePaymentMethodsVersionHash(string $tenantId, string $branchId): string
    {
        $paymentMethods = PaymentMethod::active()
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentMethod $method) => $method->getSettingsForBranch($branchId))
            ->toArray();

        return $this->hashCanonical([
            'payment_methods' => $paymentMethods,
        ]);
    }

    public function calculateTerminalPolicyVersionHash(Tenant $tenant, Branch $branch, ?SalesMachineProfile $profile): string
    {
        return $this->hashCanonical([
            'tenant' => [
                'id' => $tenant->id,
                'offline_sales_enabled' => (bool) $tenant->offline_sales_enabled,
            ],
            'branch' => [
                'id' => $branch->id,
                'offline_sales_enabled' => (bool) $branch->offline_sales_enabled,
                'inventory_deduction_policy' => $branch->inventory_deduction_policy ?: 'strict_block',
            ],
            'terminal' => $profile ? [
                'id' => $profile->id,
                'profile_code' => $profile->profile_code,
                'terminal_identifier' => $profile->terminal_identifier,
                'status' => $profile->status,
                'offline_sales_enabled' => (bool) $profile->offline_sales_enabled,
                'offline_sequence_prefix' => $profile->offline_sequence_prefix,
                'offline_sequence_status' => $profile->offline_sequence_status ?: 'active',
            ] : null,
        ]);
    }

    public function calculatePrinterProfileVersionHash(string $tenantId, string $branchId, ?string $profileId): string
    {
        return $this->hashCanonical([
            'schema_version' => 1,
            'status' => 'placeholder',
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'sales_machine_profile_id' => $profileId,
            'hardware_validation' => 'deferred',
        ]);
    }

    private function hashCanonical(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        return $value;
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
