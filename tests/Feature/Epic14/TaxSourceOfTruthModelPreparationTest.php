<?php

namespace Tests\Feature\Epic14;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleStatutoryDiscount;
use App\Models\SalesMachineProfile;
use Carbon\CarbonInterface;
use Tests\TestCase;

class TaxSourceOfTruthModelPreparationTest extends TestCase
{
    public function test_sale_model_accepts_epic14_fillable_fields_and_casts_metadata(): void
    {
        $sale = new Sale();

        $sale->fill([
            'sales_machine_profile_id' => '0f0d5f3d-9f76-4fc6-8fc0-244e46575c31',
            'principal_invoice_number' => 'INV-2026-0001',
            'principal_invoice_type' => 'vat',
            'principal_invoice_label' => 'Invoice',
            'invoice_issued_at' => '2026-05-13 09:15:00',
            'reporting_basis_at' => '2026-05-13 09:16:00',
            'gross_sales_amount' => '120.5000',
            'vatable_sales_amount' => '100.0000',
            'vat_exempt_sales_amount' => '5.0000',
            'zero_rated_sales_amount' => '10.0000',
            'non_vat_sales_amount' => '5.5000',
            'vat_amount' => '12.0000',
            'statutory_discount_total' => '4.0000',
            'commercial_discount_total' => '2.0000',
            'other_adjustment_total' => '1.5000',
            'contains_statutory_discount' => true,
            'compliance_version' => 'EPIC14_V1',
            'tax_source_version' => 'BIR_VAT_2026_BASELINE',
            'tax_computation_source' => 'system',
            'tax_profile_snapshot' => ['machine' => 'MIN-001'],
            'is_reversal' => true,
            'reversal_of_sale_id' => '9cefa932-b147-4ddd-b5dc-78b16477c3d8',
            'reversal_reason' => 'correction',
            'reversal_tax_impact_snapshot' => ['vat_delta' => '12.0000'],
        ]);

        $this->assertContains('tax_source_version', $sale->getFillable());
        $this->assertContains('reversal_tax_impact_snapshot', $sale->getFillable());
        $this->assertSame('decimal:4', $sale->getCasts()['gross_sales_amount']);
        $this->assertSame('array', $sale->getCasts()['tax_profile_snapshot']);
        $this->assertSame('boolean', $sale->getCasts()['is_reversal']);
        $this->assertInstanceOf(CarbonInterface::class, $sale->invoice_issued_at);
        $this->assertInstanceOf(CarbonInterface::class, $sale->reporting_basis_at);
        $this->assertSame('120.5000', $sale->gross_sales_amount);
        $this->assertTrue($sale->contains_statutory_discount);
        $this->assertTrue($sale->is_reversal);
        $this->assertSame(['machine' => 'MIN-001'], $sale->tax_profile_snapshot);
        $this->assertSame(['vat_delta' => '12.0000'], $sale->reversal_tax_impact_snapshot);
    }

    public function test_sale_item_model_accepts_ph_tax_bucket_fields_and_casts_metadata(): void
    {
        $saleItem = new SaleItem();

        $saleItem->fill([
            'tax_bucket' => 'vatable',
            'net_amount' => '100.0000',
            'vatable_amount' => '100.0000',
            'vat_exempt_amount' => '0.0000',
            'zero_rated_amount' => '0.0000',
            'non_vat_amount' => '0.0000',
            'tax_source' => 'system',
            'tax_snapshot' => ['bucket' => 'vatable'],
        ]);

        $this->assertContains('tax_bucket', $saleItem->getFillable());
        $this->assertContains('tax_snapshot', $saleItem->getFillable());
        $this->assertSame('decimal:4', $saleItem->getCasts()['net_amount']);
        $this->assertSame('array', $saleItem->getCasts()['tax_snapshot']);
        $this->assertSame('100.0000', $saleItem->net_amount);
        $this->assertSame('100.0000', $saleItem->vatable_amount);
        $this->assertSame(['bucket' => 'vatable'], $saleItem->tax_snapshot);
        $this->assertContains('tax_rate', $saleItem->getFillable());
    }

    public function test_sales_machine_profile_model_exists_and_supports_fillable_and_casts(): void
    {
        $profile = new SalesMachineProfile();

        $profile->fill([
            'tenant_id' => 'f7358649-7c7b-48f7-8e12-8689fe1d3a9f',
            'branch_id' => '267127fe-9525-43fc-9634-3e2e3dc64f7a',
            'profile_code' => 'DEFAULT-POS',
            'machine_identification_number' => 'MIN-0001',
            'machine_serial_number' => 'SERIAL-0001',
            'software_license_number' => 'LIC-2026-01',
            'permit_to_use_number' => 'PTU-001',
            'permit_issued_at' => '2026-05-01 00:00:00',
            'authority_to_generate_control_number' => 'ATG-001',
            'supplier_name' => 'Accredited Supplier',
            'supplier_tin' => '123-456-789-000',
            'supplier_branch_code' => '00001',
            'supplier_address' => 'Manila',
            'supplier_accreditation_number' => 'ACC-2026',
            'supplier_accreditation_issued_at' => '2026-01-01 00:00:00',
            'supplier_accreditation_expires_at' => '2027-01-01 00:00:00',
            'status' => 'active',
        ]);

        $this->assertContains('profile_code', $profile->getFillable());
        $this->assertContains('supplier_accreditation_number', $profile->getFillable());
        $this->assertSame('datetime', $profile->getCasts()['permit_issued_at']);
        $this->assertInstanceOf(CarbonInterface::class, $profile->permit_issued_at);
        $this->assertInstanceOf(CarbonInterface::class, $profile->supplier_accreditation_issued_at);
        $this->assertInstanceOf(CarbonInterface::class, $profile->supplier_accreditation_expires_at);
    }

    public function test_sale_statutory_discount_model_exists_and_supports_fillable_and_casts(): void
    {
        $discount = new SaleStatutoryDiscount();

        $discount->fill([
            'tenant_id' => 'f7358649-7c7b-48f7-8e12-8689fe1d3a9f',
            'branch_id' => '267127fe-9525-43fc-9634-3e2e3dc64f7a',
            'sale_id' => 'a9bddc2e-81fc-4f00-a6f0-092a228ffef1',
            'sale_item_id' => '711cff2d-c519-4b76-bb90-6d44efad4f8c',
            'discount_type' => 'senior',
            'discount_code' => 'SC',
            'discount_rate' => '0.2000',
            'discount_basis_amount' => '100.0000',
            'discount_amount' => '20.0000',
            'vat_adjustment_amount' => '2.4000',
            'vat_exempt_amount' => '12.0000',
            'beneficiary_reference' => 'SC-REF-001',
            'beneficiary_hash' => 'hash-value',
            'source' => 'pos',
            'snapshot' => ['discount' => 'senior'],
        ]);

        $this->assertContains('discount_type', $discount->getFillable());
        $this->assertContains('beneficiary_hash', $discount->getFillable());
        $this->assertSame('decimal:4', $discount->getCasts()['discount_rate']);
        $this->assertSame('array', $discount->getCasts()['snapshot']);
        $this->assertSame('20.0000', $discount->discount_amount);
        $this->assertSame(['discount' => 'senior'], $discount->snapshot);
    }
}