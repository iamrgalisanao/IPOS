<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TaxCategory;
use App\Models\PaymentMethod;
use App\Services\TenantContext;
use App\Services\ConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxAndPaymentConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_tax_category_can_be_created_with_validation(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        // 1. Tax category can be created for active tenant
        $tax = app(ConfigurationService::class)->createTaxCategory([
            'code' => 'VAT',
            'name' => 'VATable',
            'tax_type' => 'vatable',
            'rate' => 12.00,
            'is_default' => true
        ]);

        $this->assertEquals('VAT', $tax->code);
        $this->assertEquals(12.00, $tax->rate);
        $this->assertTrue($tax->is_default);

        // 17. Configuration changes are audit-logged
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tax_category_created',
            'auditable_id' => $tax->id,
            'tenant_id' => $tenant->id
        ]);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_tax_category_uniqueness_per_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        app(TenantContext::class)->setTenant($tenantA);
        TaxCategory::create(['code' => 'VAT', 'name' => 'VAT A', 'tax_type' => 'vatable', 'rate' => 12]);

        // 2. Tax category code is unique per tenant
        try {
            TaxCategory::create(['code' => 'VAT', 'name' => 'VAT A2', 'tax_type' => 'vatable', 'rate' => 12]);
            $this->fail('Duplicate tax code allowed in same tenant');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
        app(TenantContext::class)->clear();

        // 3. Same tax category code can exist in different tenants
        app(TenantContext::class)->setTenant($tenantB);
        $taxB = TaxCategory::create(['code' => 'VAT', 'name' => 'VAT B', 'tax_type' => 'vatable', 'rate' => 12]);
        $this->assertNotNull($taxB);
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_invalid_tax_category_rejected(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        // 4. Invalid tax type is rejected
        try {
            app(ConfigurationService::class)->createTaxCategory([
                'code' => 'INV',
                'name' => 'Invalid',
                'tax_type' => 'invalid-type',
                'rate' => 0
            ]);
            $this->fail('Invalid tax type allowed');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        // 5. Invalid tax rate is rejected (e.g. negative)
        try {
            app(ConfigurationService::class)->createTaxCategory([
                'code' => 'INV2',
                'name' => 'Invalid Rate',
                'tax_type' => 'vatable',
                'rate' => -1
            ]);
            $this->fail('Negative tax rate allowed');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }
        
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_inactive_tax_category_is_excluded(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        TaxCategory::create(['code' => 'ACT', 'name' => 'Active', 'tax_type' => 'vatable', 'rate' => 12, 'status' => 'active']);
        TaxCategory::create(['code' => 'INA', 'name' => 'Inactive', 'tax_type' => 'vatable', 'rate' => 12, 'status' => 'inactive']);

        // 6. Inactive tax category is excluded from active query
        $this->assertCount(1, TaxCategory::active()->get());
        
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_tax_category_isolation(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        app(TenantContext::class)->setTenant($tenantA);
        $taxA = TaxCategory::create(['code' => 'TAX-A', 'name' => 'Tax A', 'tax_type' => 'vatable', 'rate' => 12]);
        app(TenantContext::class)->clear();

        // 7. Tenant A cannot access/update Tenant B tax category
        app(TenantContext::class)->setTenant($tenantB);
        $this->assertNull(TaxCategory::where('id', $taxA->id)->first());
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_payment_method_can_be_created_and_scoped(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        // 8. Payment method can be created for active tenant
        // 12. Digital payment method can require reference number
        // 13. Strict reference mode can be enabled
        $payment = app(ConfigurationService::class)->createPaymentMethod([
            'code' => 'GCASH',
            'name' => 'GCash',
            'type' => 'e-wallet',
            'reference_required' => true,
            'strict_reference_mode' => true
        ]);

        $this->assertEquals('GCASH', $payment->code);
        $this->assertTrue($payment->reference_required);
        $this->assertTrue($payment->strict_reference_mode);

        // 9. Payment method is tenant-scoped
        $this->assertEquals($tenant->id, $payment->tenant_id);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_cash_payment_method_normalization(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        // 11. Cash payment method cannot require reference number (safely normalized)
        $payment = app(ConfigurationService::class)->createPaymentMethod([
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'reference_required' => true,
            'strict_reference_mode' => true
        ]);

        $this->assertFalse($payment->reference_required);
        $this->assertFalse($payment->strict_reference_mode);

        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_inactive_payment_method_is_excluded(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($tenant);

        PaymentMethod::create(['code' => 'ACT', 'name' => 'Active', 'type' => 'cash', 'status' => 'active']);
        PaymentMethod::create(['code' => 'INA', 'name' => 'Inactive', 'type' => 'cash', 'status' => 'inactive']);

        // 10. Inactive payment method is excluded from active query
        $this->assertCount(1, PaymentMethod::active()->get());
        
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_default_setup_seeding(): void
    {
        $tenantA = Tenant::factory()->create(['status' => 'active']);
        $tenantB = Tenant::factory()->create(['status' => 'active']);

        // 15. Default tax/payment setup can be seeded
        app(ConfigurationService::class)->seedDefaults($tenantA);

        app(TenantContext::class)->setTenant($tenantA);
        $this->assertCount(2, TaxCategory::all());
        $this->assertCount(2, PaymentMethod::all());
        $this->assertEquals('VAT', TaxCategory::where('is_default', true)->first()->code);
        $this->assertEquals('CASH', PaymentMethod::where('is_default', true)->first()->code);
        app(TenantContext::class)->clear();

        // 16. Default setup does not leak across tenants
        app(TenantContext::class)->setTenant($tenantB);
        $this->assertCount(0, TaxCategory::all());
        $this->assertCount(0, PaymentMethod::all());
        app(TenantContext::class)->clear();
    }

    /** @test */
    public function test_default_payment_methods_can_be_ensured_idempotently(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);

        app(ConfigurationService::class)->ensureDefaultPaymentMethods($tenant);
        app(ConfigurationService::class)->ensureDefaultPaymentMethods($tenant);

        app(TenantContext::class)->setTenant($tenant);

        $this->assertCount(2, PaymentMethod::all());
        $this->assertTrue(PaymentMethod::where('code', 'CASH')->first()->is_default);
        $this->assertTrue(PaymentMethod::where('code', 'GCASH')->first()->reference_required);

        app(TenantContext::class)->clear();
    }
}
