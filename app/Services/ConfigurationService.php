<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\TaxCategory;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ConfigurationService
{
    private const DEFAULT_PAYMENT_METHODS = [
        [
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'is_default' => true,
            'status' => 'active',
        ],
        [
            'code' => 'GCASH',
            'name' => 'GCash',
            'type' => 'e-wallet',
            'reference_required' => true,
            'strict_reference_mode' => true,
            'is_default' => false,
            'status' => 'active',
        ],
    ];

    public function __construct(protected AuditLogger $auditLogger)
    {
    }

    /**
     * Update tenant settings with validation and auditing.
     */
    public function updateTenant(Tenant $tenant, array $data): void
    {
        $validator = Validator::make($data, [
            'currency' => ['sometimes', 'string', Rule::in(['PHP', 'USD'])],
            'timezone' => ['sometimes', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'tax_mode' => ['sometimes', 'string', Rule::in(['exclusive', 'inclusive'])],
            'receipt_header' => ['sometimes', 'nullable', 'string'],
            'receipt_footer' => ['sometimes', 'nullable', 'string'],
            'business_registration_number' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        $before = $tenant->only(array_keys($data));
        $tenant->update($data);
        
        $this->auditLogger->log(
            action: 'tenant_config_updated',
            auditable: $tenant,
            beforeValues: $before,
            afterValues: $tenant->only(array_keys($data))
        );
    }

    /**
     * Update branch settings with validation and auditing.
     */
    public function updateBranch(Branch $branch, array $data): void
    {
        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string'],
            'address' => ['sometimes', 'nullable', 'string'],
            'contact_number' => ['sometimes', 'nullable', 'string'],
            'timezone' => ['sometimes', 'nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'receipt_prefix' => ['sometimes', 'nullable', 'string'],
            'receipt_next_number' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        $before = $branch->only(array_keys($data));
        $branch->update($data);

        $this->auditLogger->log(
            action: 'branch_config_updated',
            auditable: $branch,
            beforeValues: $before,
            afterValues: $branch->only(array_keys($data))
        );
    }

    /**
     * Create tax category.
     */
    public function createTaxCategory(array $data): TaxCategory
    {
        $validator = Validator::make($data, [
            'code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'tax_type' => ['required', 'string', Rule::in(['vatable', 'exempt', 'zero-rated', 'non-vat'])],
            'rate' => ['required', 'numeric', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        $taxCategory = TaxCategory::create($data);

        $this->auditLogger->log(
            action: 'tax_category_created',
            auditable: $taxCategory,
            afterValues: $taxCategory->toArray()
        );

        return $taxCategory;
    }

    /**
     * Create payment method.
     */
    public function createPaymentMethod(array $data): PaymentMethod
    {
        // 11. Cash payment method cannot require reference number
        if (($data['type'] ?? '') === 'cash') {
            $data['reference_required'] = false;
            $data['strict_reference_mode'] = false;
        }

        $validator = Validator::make($data, [
            'code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'type' => ['required', 'string', Rule::in(['cash', 'e-wallet', 'card', 'other'])],
            'reference_required' => ['sometimes', 'boolean'],
            'strict_reference_mode' => ['sometimes', 'boolean'],
            'settlement_tracking_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        $paymentMethod = PaymentMethod::create($data);

        $this->auditLogger->log(
            action: 'payment_method_created',
            auditable: $paymentMethod,
            afterValues: $paymentMethod->toArray()
        );

        return $paymentMethod;
    }

    /**
     * Ensure a tenant has the standard POS payment methods.
     */
    public function ensureDefaultPaymentMethods(Tenant $tenant): void
    {
        $tenantContext = app(TenantContext::class);
        $previousTenant = $tenantContext->getTenant();

        $tenantContext->setTenant($tenant);

        try {
            foreach (self::DEFAULT_PAYMENT_METHODS as $method) {
                $exists = PaymentMethod::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $method['code'])
                    ->exists();

                if (!$exists) {
                    $this->createPaymentMethod($method);
                }
            }
        } finally {
            if ($previousTenant) {
                $tenantContext->setTenant($previousTenant);
            } else {
                $tenantContext->clear();
            }
        }
    }

    /**
     * Seed default tax and payment methods for a tenant.
     */
    public function seedDefaults(Tenant $tenant): void
    {
        // Ensure context is set for auditing
        app(TenantContext::class)->setTenant($tenant);

        // 15. Default tax setup
        $this->createTaxCategory([
            'code' => 'VAT',
            'name' => 'VATable (12%)',
            'tax_type' => 'vatable',
            'rate' => 12.00,
            'is_default' => true
        ]);

        $this->createTaxCategory([
            'code' => 'EXEMPT',
            'name' => 'VAT Exempt',
            'tax_type' => 'exempt',
            'rate' => 0.00,
            'is_default' => false
        ]);

        // 15. Default payment setup
        $this->ensureDefaultPaymentMethods($tenant);

        app(TenantContext::class)->clear();
    }
}
