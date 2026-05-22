<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TaxCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class CatalogImportPreviewService
{
    private const PRODUCT_REQUIRED_COLUMNS = [
        'name',
        'sku',
        'category_code',
        'unit_of_measure',
        'selling_price',
        'status',
        'product_type',
        'is_sellable',
        'is_inventory_tracked',
        'is_taxable',
        'is_discountable',
    ];

    private const PRODUCT_OPTIONAL_COLUMNS = [
        'barcode',
        'description',
        'cost_price',
        'tax_category_code',
    ];

    private const CATEGORY_REQUIRED_COLUMNS = [
        'name',
        'code',
        'status',
    ];

    private const CATEGORY_OPTIONAL_COLUMNS = [
        'description',
    ];

    private const PRODUCT_TEMPLATE_SAMPLE = [
        'name' => 'Sample Product',
        'sku' => 'SKU-001',
        'category_code' => 'BEV',
        'unit_of_measure' => 'piece',
        'selling_price' => '99.9500',
        'status' => 'active',
        'product_type' => 'finished_good',
        'is_sellable' => 'true',
        'is_inventory_tracked' => 'true',
        'is_taxable' => 'true',
        'is_discountable' => 'true',
        'barcode' => '1234567890123',
        'description' => 'Optional description',
        'cost_price' => '55.0000',
        'tax_category_code' => 'VAT',
    ];

    private const CATEGORY_TEMPLATE_SAMPLE = [
        'name' => 'Beverages',
        'code' => 'BEV',
        'status' => 'active',
        'description' => 'Optional category description',
    ];

    public function productTemplateCsv(): string
    {
        return $this->buildTemplateCsv(
            array_merge(self::PRODUCT_REQUIRED_COLUMNS, self::PRODUCT_OPTIONAL_COLUMNS),
            self::PRODUCT_TEMPLATE_SAMPLE
        );
    }

    public function categoryTemplateCsv(): string
    {
        return $this->buildTemplateCsv(
            array_merge(self::CATEGORY_REQUIRED_COLUMNS, self::CATEGORY_OPTIONAL_COLUMNS),
            self::CATEGORY_TEMPLATE_SAMPLE
        );
    }

    public function productTemplateFilename(): string
    {
        return 'ipos-product-import-template.csv';
    }

    public function categoryTemplateFilename(): string
    {
        return 'ipos-product-category-import-template.csv';
    }

    public function previewProducts(UploadedFile $file): array
    {
        [$headers, $rows] = $this->parseCsv($file);

        $missingColumns = array_values(array_diff(self::PRODUCT_REQUIRED_COLUMNS, $headers));
        $unexpectedColumns = array_values(array_diff($headers, array_merge(self::PRODUCT_REQUIRED_COLUMNS, self::PRODUCT_OPTIONAL_COLUMNS)));

        $existingCategoryCodes = ProductCategory::query()
            ->get()
            ->keyBy(fn (ProductCategory $category) => strtoupper($category->code));

        $existingTaxCodes = TaxCategory::query()
            ->get()
            ->keyBy(fn (TaxCategory $taxCategory) => strtoupper($taxCategory->code));

        $existingSkus = Product::query()->pluck('sku')->filter()->map(fn ($sku) => strtoupper((string) $sku))->all();
        $existingBarcodes = Product::query()->pluck('barcode')->filter()->map(fn ($barcode) => strtoupper((string) $barcode))->all();

        $seenSkus = [];
        $seenBarcodes = [];
        $previewRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $errors = [];

            foreach (self::PRODUCT_REQUIRED_COLUMNS as $column) {
                if (!array_key_exists($column, $row) || trim((string) $row[$column]) === '') {
                    $errors[] = "{$column} is required.";
                }
            }

            $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
            $barcode = strtoupper(trim((string) ($row['barcode'] ?? '')));
            $categoryCode = strtoupper(trim((string) ($row['category_code'] ?? '')));
            $taxCode = strtoupper(trim((string) ($row['tax_category_code'] ?? '')));
            $status = trim((string) ($row['status'] ?? ''));
            $productType = trim((string) ($row['product_type'] ?? ''));

            if ($sku !== '') {
                if (in_array($sku, $existingSkus, true)) {
                    $errors[] = 'sku already exists in the current tenant catalog.';
                }
                if (in_array($sku, $seenSkus, true)) {
                    $errors[] = 'sku is duplicated within the uploaded file.';
                }
                $seenSkus[] = $sku;
            }

            if ($barcode !== '') {
                if (in_array($barcode, $existingBarcodes, true)) {
                    $errors[] = 'barcode already exists in the current tenant catalog.';
                }
                if (in_array($barcode, $seenBarcodes, true)) {
                    $errors[] = 'barcode is duplicated within the uploaded file.';
                }
                $seenBarcodes[] = $barcode;
            }

            if ($categoryCode === '' || !$existingCategoryCodes->has($categoryCode)) {
                $errors[] = 'category_code does not match an existing category in the current tenant.';
            }

            if ($categoryCode !== '' && $existingCategoryCodes->has($categoryCode) && $existingCategoryCodes[$categoryCode]->status !== 'active') {
                $errors[] = 'category_code references an inactive category.';
            }

            if ($taxCode !== '') {
                if (!$existingTaxCodes->has($taxCode)) {
                    $errors[] = 'tax_category_code does not match an existing tax category in the current tenant.';
                } elseif ($existingTaxCodes[$taxCode]->status !== 'active') {
                    $errors[] = 'tax_category_code references an inactive tax category.';
                }
            }

            if ($status !== '' && !in_array($status, ['active', 'inactive'], true)) {
                $errors[] = 'status must be active or inactive.';
            }

            if ($productType !== '' && !in_array($productType, ['finished_good', 'raw_material', 'semi_finished'], true)) {
                $errors[] = 'product_type must be finished_good, raw_material, or semi_finished.';
            }

            if (($row['selling_price'] ?? '') !== '' && !is_numeric($row['selling_price'])) {
                $errors[] = 'selling_price must be numeric.';
            }

            if (($row['cost_price'] ?? '') !== '' && !is_numeric($row['cost_price'])) {
                $errors[] = 'cost_price must be numeric when provided.';
            }

            foreach (['is_sellable', 'is_inventory_tracked', 'is_taxable', 'is_discountable'] as $booleanColumn) {
                $value = strtolower(trim((string) ($row[$booleanColumn] ?? '')));
                if ($value !== '' && !in_array($value, ['true', 'false', '1', '0', 'yes', 'no'], true)) {
                    $errors[] = "{$booleanColumn} must be true/false compatible.";
                }
            }

            $previewRows[] = [
                'row_number' => $rowNumber,
                'status' => empty($errors) ? 'valid' : 'invalid',
                'errors' => $errors,
                'data' => $row,
            ];
        }

        return $this->buildPreviewPayload('products', $headers, $missingColumns, $unexpectedColumns, $previewRows);
    }

    public function previewCategories(UploadedFile $file): array
    {
        [$headers, $rows] = $this->parseCsv($file);

        $missingColumns = array_values(array_diff(self::CATEGORY_REQUIRED_COLUMNS, $headers));
        $unexpectedColumns = array_values(array_diff($headers, array_merge(self::CATEGORY_REQUIRED_COLUMNS, self::CATEGORY_OPTIONAL_COLUMNS)));

        $existingCodes = ProductCategory::query()->pluck('code')->filter()->map(fn ($code) => strtoupper((string) $code))->all();
        $seenCodes = [];
        $previewRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $errors = [];

            foreach (self::CATEGORY_REQUIRED_COLUMNS as $column) {
                if (!array_key_exists($column, $row) || trim((string) $row[$column]) === '') {
                    $errors[] = "{$column} is required.";
                }
            }

            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $status = trim((string) ($row['status'] ?? ''));

            if ($code !== '') {
                if (in_array($code, $existingCodes, true)) {
                    $errors[] = 'code already exists in the current tenant catalog.';
                }
                if (in_array($code, $seenCodes, true)) {
                    $errors[] = 'code is duplicated within the uploaded file.';
                }
                $seenCodes[] = $code;
            }

            if ($status !== '' && !in_array($status, ['active', 'inactive'], true)) {
                $errors[] = 'status must be active or inactive.';
            }

            $previewRows[] = [
                'row_number' => $rowNumber,
                'status' => empty($errors) ? 'valid' : 'invalid',
                'errors' => $errors,
                'data' => $row,
            ];
        }

        return $this->buildPreviewPayload('categories', $headers, $missingColumns, $unexpectedColumns, $previewRows);
    }

    private function buildTemplateCsv(array $headers, array $sampleRow): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        fputcsv($handle, array_map(fn ($header) => $sampleRow[$header] ?? '', $headers));
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function parseCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle) ?: [];
        $headers = array_map(fn ($value) => trim((string) $value), $headers);

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === false) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $assoc[$header] = trim((string) ($values[$index] ?? ''));
            }

            if (collect($assoc)->every(fn ($value) => $value === '')) {
                continue;
            }

            $rows[] = $assoc;
        }

        fclose($handle);

        return [$headers, $rows];
    }

    private function buildPreviewPayload(string $type, array $headers, array $missingColumns, array $unexpectedColumns, array $previewRows): array
    {
        $rowCollection = Collection::make($previewRows);

        return [
            'type' => $type,
            'headers' => $headers,
            'missing_columns' => $missingColumns,
            'unexpected_columns' => $unexpectedColumns,
            'summary' => [
                'total_rows' => $rowCollection->count(),
                'valid_rows' => $rowCollection->where('status', 'valid')->count(),
                'invalid_rows' => $rowCollection->where('status', 'invalid')->count(),
            ],
            'rows' => $previewRows,
        ];
    }
}