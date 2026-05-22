<?php

namespace App\Services\Tax;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleStatutoryDiscount;
use App\Models\SalesMachineProfile;

class TaxSourceSnapshotService
{
    public function prepareSaleTaxProfileSnapshot(?SalesMachineProfile $profile, array $metadata = []): array
    {
        return [
            'sales_machine_profile_id' => $profile?->id,
            'profile_code' => $profile?->profile_code,
            'machine_identification_number' => $profile?->machine_identification_number,
            'machine_serial_number' => $profile?->machine_serial_number,
            'software_license_number' => $profile?->software_license_number,
            'permit_to_use_number' => $profile?->permit_to_use_number,
            'authority_to_generate_control_number' => $profile?->authority_to_generate_control_number,
            'supplier_name' => $profile?->supplier_name,
            'supplier_tin' => $profile?->supplier_tin,
            'supplier_branch_code' => $profile?->supplier_branch_code,
            'supplier_accreditation_number' => $profile?->supplier_accreditation_number,
            'status' => $profile?->status,
            'source_metadata' => $this->prepareSourceMetadata(
                $metadata['tax_source'] ?? null,
                $metadata['tax_computation_source'] ?? null,
                $metadata['tax_source_version'] ?? null,
            ),
        ];
    }

    public function prepareSaleItemTaxSnapshot(array $item): array
    {
        return [
            'tax_category_id' => $item['tax_category_id'] ?? null,
            'tax_type' => $item['tax_type'] ?? null,
            'tax_rate' => $this->formatDecimal($item['tax_rate'] ?? 0),
            'tax_bucket' => $this->normalizeValue(
                $item['tax_bucket'] ?? null,
                SaleItem::taxBuckets(),
                SaleItem::TAX_BUCKET_UNKNOWN,
            ),
            'net_amount' => $this->formatDecimal($item['net_amount'] ?? 0),
            'vatable_amount' => $this->formatDecimal($item['vatable_amount'] ?? 0),
            'vat_exempt_amount' => $this->formatDecimal($item['vat_exempt_amount'] ?? 0),
            'zero_rated_amount' => $this->formatDecimal($item['zero_rated_amount'] ?? 0),
            'non_vat_amount' => $this->formatDecimal($item['non_vat_amount'] ?? 0),
            'tax_source' => $this->normalizeValue(
                $item['tax_source'] ?? null,
                SaleItem::taxSources(),
                SaleItem::TAX_SOURCE_UNKNOWN,
            ),
            'is_discountable' => (bool) ($item['is_discountable'] ?? false),
        ];
    }

    public function prepareStatutoryDiscountSnapshot(array $discount): array
    {
        return [
            'discount_type' => $this->normalizeValue(
                $discount['discount_type'] ?? null,
                SaleStatutoryDiscount::discountTypes(),
                SaleStatutoryDiscount::DISCOUNT_TYPE_UNKNOWN,
            ),
            'discount_code' => $discount['discount_code'] ?? null,
            'discount_rate' => $this->nullableDecimal($discount['discount_rate'] ?? null),
            'discount_basis_amount' => $this->formatDecimal($discount['discount_basis_amount'] ?? 0),
            'discount_amount' => $this->formatDecimal($discount['discount_amount'] ?? 0),
            'vat_adjustment_amount' => $this->nullableDecimal($discount['vat_adjustment_amount'] ?? null),
            'vat_exempt_amount' => $this->nullableDecimal($discount['vat_exempt_amount'] ?? null),
            'beneficiary_reference' => $discount['beneficiary_reference'] ?? null,
            'beneficiary_hash' => $discount['beneficiary_hash'] ?? null,
            'source' => $this->normalizeValue(
                $discount['source'] ?? null,
                Sale::taxSources(),
                Sale::TAX_SOURCE_UNKNOWN,
            ),
        ];
    }

    public function prepareSourceMetadata(
        ?string $taxSource = null,
        ?string $taxComputationSource = null,
        ?string $taxSourceVersion = null
    ): array {
        return [
            'tax_source' => $this->normalizeValue($taxSource, Sale::taxSources(), Sale::TAX_SOURCE_UNKNOWN),
            'tax_computation_source' => $this->normalizeValue(
                $taxComputationSource,
                Sale::taxComputationSources(),
                Sale::TAX_SOURCE_UNKNOWN,
            ),
            'tax_source_version' => $taxSourceVersion,
        ];
    }

    protected function normalizeValue(?string $value, array $allowed, string $fallback): string
    {
        if ($value === null) {
            return $fallback;
        }

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    protected function formatDecimal(int|float|string $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    protected function nullableDecimal(int|float|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->formatDecimal($value);
    }
}