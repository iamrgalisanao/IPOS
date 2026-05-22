<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogCsvExportService;
use App\Services\Catalog\CatalogImportPreviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ProductCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('Admin/ProductCategories/Index', [
            'categories' => ProductCategory::orderBy('name')->get(),
            'importPreview' => request()->session()->get('importPreview'),
        ]);
    }

    /**
     * Download category import template CSV.
     */
    public function importTemplate(CatalogImportPreviewService $importPreviewService, AuditLogger $auditLogger)
    {
        $auditLogger->log(
            action: 'product_category_import_template_downloaded',
            remarks: 'Product category import template downloaded.',
            metadata: [
                'template_type' => 'categories',
                'generated_at' => now()->toIso8601String(),
            ]
        );

        return response($importPreviewService->categoryTemplateCsv())
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $importPreviewService->categoryTemplateFilename() . '"')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Preview category import CSV without mutating catalog data.
     */
    public function previewImport(Request $request, CatalogImportPreviewService $importPreviewService, AuditLogger $auditLogger)
    {
        $validated = $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $preview = $importPreviewService->previewCategories($validated['csv_file']);

        $auditLogger->log(
            action: 'product_category_import_previewed',
            remarks: 'Product category import preview executed without writes.',
            metadata: [
                'preview_type' => 'categories',
                'summary' => $preview['summary'],
                'missing_columns' => $preview['missing_columns'],
                'unexpected_columns' => $preview['unexpected_columns'],
                'generated_at' => now()->toIso8601String(),
            ]
        );

        return redirect()->route('admin.product-categories.index')
            ->with('importPreview', $preview);
    }

    /**
     * Export categories as CSV.
     */
    public function export(Request $request, CatalogCsvExportService $csvExportService, AuditLogger $auditLogger)
    {
        $categories = ProductCategory::orderBy('name')->get();

        $auditLogger->log(
            action: 'product_category_catalog_exported',
            remarks: 'Product categories exported as CSV.',
            metadata: [
                'export_format' => 'csv',
                'scope' => 'product_categories',
                'record_count' => $categories->count(),
                'generated_at' => now()->toIso8601String(),
            ]
        );

        $csv = $csvExportService->exportCategories($categories, $request->user());

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $csvExportService->categoryFilename() . '"')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Store a newly created resource in the resource.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        // Ensure code is upper case and slug-friendly if not provided
        $validated['code'] = Str::upper(Str::slug($validated['code']));

        ProductCategory::create($validated);

        return redirect()->route('admin.product-categories.index')
            ->with('success', 'Category created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductCategory $productCategory)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        $validated['code'] = Str::upper(Str::slug($validated['code']));

        $productCategory->update($validated);

        return redirect()->route('admin.product-categories.index')
            ->with('success', 'Category updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductCategory $productCategory)
    {
        // Check if category has products before deleting
        if ($productCategory->products()->count() > 0) {
            return redirect()->back()->with('error', 'Cannot delete category with associated products. Deactivate it instead.');
        }

        $productCategory->delete();

        return redirect()->route('admin.product-categories.index')
            ->with('success', 'Category deleted successfully.');
    }
}
