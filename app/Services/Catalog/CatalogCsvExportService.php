<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CatalogCsvExportService
{
    public function exportProducts(Collection $products, User $actor): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'product_name',
            'sku',
            'barcode',
            'category_code',
            'category_name',
            'unit_of_measure',
            'selling_price',
            'cost_price',
            'status',
            'product_type',
            'is_sellable',
            'is_inventory_tracked',
            'is_taxable',
            'is_discountable',
            'description',
            'generated_at',
            'generated_by',
        ]);

        $generatedAt = Carbon::now()->toIso8601String();
        $generatedBy = $actor->name;

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $row = [
                $this->sanitizeCell($product->name),
                $this->sanitizeCell($product->sku),
                $this->sanitizeCell($product->barcode),
                $this->sanitizeCell($product->category?->code),
                $this->sanitizeCell($product->category?->name),
                $this->sanitizeCell($product->unit_of_measure),
                number_format((float) $product->selling_price, 4, '.', ''),
                $product->cost_price !== null ? number_format((float) $product->cost_price, 4, '.', '') : '',
                $this->sanitizeCell($product->status),
                $this->sanitizeCell($product->product_type),
                $product->is_sellable ? 'true' : 'false',
                $product->is_inventory_tracked ? 'true' : 'false',
                $product->is_taxable ? 'true' : 'false',
                $product->is_discountable ? 'true' : 'false',
                $this->sanitizeCell($product->description),
                $generatedAt,
                $this->sanitizeCell($generatedBy),
            ];

            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function exportCategories(Collection $categories, User $actor): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'category_name',
            'category_code',
            'description',
            'status',
            'generated_at',
            'generated_by',
        ]);

        $generatedAt = Carbon::now()->toIso8601String();
        $generatedBy = $actor->name;

        foreach ($categories as $category) {
            if (!$category instanceof ProductCategory) {
                continue;
            }

            fputcsv($handle, [
                $this->sanitizeCell($category->name),
                $this->sanitizeCell($category->code),
                $this->sanitizeCell($category->description),
                $this->sanitizeCell($category->status),
                $generatedAt,
                $this->sanitizeCell($generatedBy),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function productFilename(): string
    {
        return 'ipos-product-catalog-' . Carbon::now()->format('Ymd-His') . '.csv';
    }

    public function categoryFilename(): string
    {
        return 'ipos-product-categories-' . Carbon::now()->format('Ymd-His') . '.csv';
    }

    private function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $string = trim((string) $value);

        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@'], true)) {
            return "'" . $string;
        }

        return $string;
    }
}