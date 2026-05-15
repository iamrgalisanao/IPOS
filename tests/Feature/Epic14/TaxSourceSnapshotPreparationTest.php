<?php

namespace Tests\Feature\Epic14;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleStatutoryDiscount;
use App\Models\SalesMachineProfile;
use App\Services\Tax\TaxSourceSnapshotService;
use Tests\TestCase;

class TaxSourceSnapshotPreparationTest extends TestCase
{
    public function test_sale_level_snapshot_shape_can_be_prepared(): void
    {
        $service = new TaxSourceSnapshotService();
        $profile = new SalesMachineProfile([
            'id' => '2c462e94-94d2-46c6-b8e4-cb5f1b3fd329',
            'profile_code' => 'MAIN-POS',
            'machine_identification_number' => 'MIN-001',
            'machine_serial_number' => 'SER-001',
            'software_license_number' => 'LIC-001',
            'permit_to_use_number' => 'PTU-001',
            'authority_to_generate_control_number' => 'ATG-001',
            'supplier_name' => 'Supplier',
            'supplier_tin' => '123-456-789-000',
            'supplier_branch_code' => '00001',
            'supplier_accreditation_number' => 'ACC-001',
            'status' => 'active',
        ]);

        $snapshot = $service->prepareSaleTaxProfileSnapshot($profile, [
            'tax_source' => Sale::TAX_SOURCE_SYSTEM,
            'tax_computation_source' => Sale::TAX_SOURCE_POS,
            'tax_source_version' => 'EPIC14_V1',
        ]);

        $this->assertSame('MAIN-POS', $snapshot['profile_code']);
        $this->assertSame('MIN-001', $snapshot['machine_identification_number']);
        $this->assertSame(Sale::TAX_SOURCE_SYSTEM, $snapshot['source_metadata']['tax_source']);
        $this->assertSame(Sale::TAX_SOURCE_POS, $snapshot['source_metadata']['tax_computation_source']);
        $this->assertSame('EPIC14_V1', $snapshot['source_metadata']['tax_source_version']);
    }

    public function test_sale_item_tax_bucket_snapshot_shape_can_be_prepared(): void
    {
        $service = new TaxSourceSnapshotService();

        $snapshot = $service->prepareSaleItemTaxSnapshot([
            'tax_category_id' => 'tax-cat-1',
            'tax_type' => 'vatable',
            'tax_rate' => '12',
            'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
            'net_amount' => '100',
            'vatable_amount' => '100',
            'vat_exempt_amount' => '0',
            'zero_rated_amount' => '0',
            'non_vat_amount' => '0',
            'tax_source' => SaleItem::TAX_SOURCE_SYSTEM,
        ]);

        $this->assertSame('tax-cat-1', $snapshot['tax_category_id']);
        $this->assertSame(SaleItem::TAX_BUCKET_VATABLE, $snapshot['tax_bucket']);
        $this->assertSame('12.0000', $snapshot['tax_rate']);
        $this->assertSame('100.0000', $snapshot['net_amount']);
        $this->assertSame(SaleItem::TAX_SOURCE_SYSTEM, $snapshot['tax_source']);
    }

    public function test_statutory_discount_snapshot_shape_can_be_prepared(): void
    {
        $service = new TaxSourceSnapshotService();

        $snapshot = $service->prepareStatutoryDiscountSnapshot([
            'discount_type' => SaleStatutoryDiscount::DISCOUNT_TYPE_SENIOR_CITIZEN,
            'discount_code' => 'SC',
            'discount_rate' => '0.20',
            'discount_basis_amount' => '100',
            'discount_amount' => '20',
            'vat_adjustment_amount' => '2.4',
            'vat_exempt_amount' => '12',
            'beneficiary_reference' => 'BEN-001',
            'beneficiary_hash' => 'hash-001',
            'source' => Sale::TAX_SOURCE_MANUAL,
        ]);

        $this->assertSame(SaleStatutoryDiscount::DISCOUNT_TYPE_SENIOR_CITIZEN, $snapshot['discount_type']);
        $this->assertSame('0.2000', $snapshot['discount_rate']);
        $this->assertSame('20.0000', $snapshot['discount_amount']);
        $this->assertSame('BEN-001', $snapshot['beneficiary_reference']);
        $this->assertSame(Sale::TAX_SOURCE_MANUAL, $snapshot['source']);
    }

    public function test_source_metadata_reuses_existing_constants_and_normalizes_unknown_values(): void
    {
        $service = new TaxSourceSnapshotService();

        $metadata = $service->prepareSourceMetadata('unsupported', Sale::TAX_SOURCE_POS, 'BIR_VAT_2026_BASELINE');

        $this->assertSame(Sale::TAX_SOURCE_UNKNOWN, $metadata['tax_source']);
        $this->assertSame(Sale::TAX_SOURCE_POS, $metadata['tax_computation_source']);
        $this->assertSame('BIR_VAT_2026_BASELINE', $metadata['tax_source_version']);
    }
}