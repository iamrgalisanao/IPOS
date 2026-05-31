<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchProductPricing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductRecipe;
use App\Models\TaxCategory;
use App\Services\AuditLogger;
use App\Services\Catalog\CatalogCsvExportService;
use App\Services\Catalog\CatalogImportPreviewService;
use App\Services\Inventory\RecipeCostingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $this->filteredIndexQuery($request);

        return Inertia::render('Admin/Products/Index', [
            'products' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'categories' => ProductCategory::active()->orderBy('name')->get(),
            'filters' => $request->only(['search', 'category_id', 'status']),
            'importPreview' => $request->session()->get('importPreview'),
        ]);
    }

    /**
     * Download product import template CSV.
     */
    public function importTemplate(CatalogImportPreviewService $importPreviewService, AuditLogger $auditLogger)
    {
        $auditLogger->log(
            action: 'product_catalog_import_template_downloaded',
            remarks: 'Product import template downloaded.',
            metadata: [
                'template_type' => 'products',
                'generated_at' => now()->toIso8601String(),
            ]
        );

        return response($importPreviewService->productTemplateCsv())
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $importPreviewService->productTemplateFilename() . '"')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Preview product import CSV without mutating catalog data.
     */
    public function previewImport(Request $request, CatalogImportPreviewService $importPreviewService, AuditLogger $auditLogger)
    {
        $validated = $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $preview = $importPreviewService->previewProducts($validated['csv_file']);

        $auditLogger->log(
            action: 'product_catalog_import_previewed',
            remarks: 'Product import preview executed without writes.',
            metadata: [
                'preview_type' => 'products',
                'summary' => $preview['summary'],
                'missing_columns' => $preview['missing_columns'],
                'unexpected_columns' => $preview['unexpected_columns'],
                'generated_at' => now()->toIso8601String(),
            ]
        );

        return redirect()->route('admin.products.index')
            ->with('importPreview', $preview);
    }

    /**
     * Export filtered catalog products as CSV.
     */
    public function export(Request $request, CatalogCsvExportService $csvExportService, AuditLogger $auditLogger)
    {
        $products = $this->filteredIndexQuery($request)
            ->with('category')
            ->orderBy('name')
            ->get();

        $auditLogger->log(
            action: 'product_catalog_exported',
            remarks: 'Catalog products exported as CSV.',
            metadata: [
                'export_format' => 'csv',
                'scope' => 'products',
                'record_count' => $products->count(),
                'filters' => $request->only(['search', 'category_id', 'status']),
                'generated_at' => now()->toIso8601String(),
            ]
        );

        $csv = $csvExportService->exportProducts($products, $request->user());

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $csvExportService->productFilename() . '"')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Products/Create', [
            'categories' => ProductCategory::active()->orderBy('name')->get(),
            'taxCategories' => TaxCategory::active()->orderBy('name')->get(),
            'uomOptions' => ['piece', 'kg', 'liter', 'pack', 'set', 'hour', 'gram', 'ml'],
            'productTypes' => [
                ['value' => 'finished_good', 'label' => 'Finished Good (Sellable)'],
                ['value' => 'raw_material', 'label' => 'Raw Material (Ingredient)'],
                ['value' => 'semi_finished', 'label' => 'Semi-Finished (In-House Prep)'],
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_category_id' => 'required|exists:product_categories,id',
            'tax_category_id' => 'nullable|exists:tax_categories,id',
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:255',
            'barcode' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'unit_of_measure' => 'required|string',
            'selling_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'is_taxable' => 'required|boolean',
            'is_inventory_tracked' => 'required|boolean',
            'is_discountable' => 'required|boolean',
            'status' => 'required|in:active,inactive',
            'product_type' => 'required|in:finished_good,raw_material,semi_finished',
            'is_sellable' => 'required|boolean',
        ]);

        $validated['sku'] = Str::upper($validated['sku']);

        Product::create($validated);

        return redirect()->route('admin.products.index')
            ->with('success', 'Product created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product $product)
    {
        $product->load(['category', 'taxCategory', 'recipes.ingredient']);
        
        // Load branch-specific pricing
        $branchPrices = BranchProductPricing::where('product_id', $product->id)
            ->with('branch')
            ->get();

        return Inertia::render('Admin/Products/Edit', [
            'product' => $product,
            'categories' => ProductCategory::active()->orderBy('name')->get(),
            'taxCategories' => TaxCategory::active()->orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
            'branchPrices' => $branchPrices,
            'allProducts' => Product::active()->orderBy('name')->get(['id', 'name', 'sku', 'unit_of_measure', 'product_type', 'is_sellable']),
            'uomOptions' => ['piece', 'kg', 'liter', 'pack', 'set', 'hour', 'gram', 'ml'],
            'productTypes' => [
                ['value' => 'finished_good', 'label' => 'Finished Good (Sellable)'],
                ['value' => 'raw_material', 'label' => 'Raw Material (Ingredient)'],
                ['value' => 'semi_finished', 'label' => 'Semi-Finished (In-House Prep)'],
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'product_category_id' => 'required|exists:product_categories,id',
            'tax_category_id' => 'nullable|exists:tax_categories,id',
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:255',
            'barcode' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'unit_of_measure' => 'required|string',
            'selling_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'is_taxable' => 'required|boolean',
            'is_inventory_tracked' => 'required|boolean',
            'is_discountable' => 'required|boolean',
            'status' => 'required|in:active,inactive',
            'product_type' => 'required|in:finished_good,raw_material,semi_finished',
            'is_sellable' => 'required|boolean',
        ]);

        $validated['sku'] = Str::upper($validated['sku']);

        $product->update($validated);

        return redirect()->route('admin.products.index')
            ->with('success', 'Product updated successfully.');
    }

    /**
     * Update branch-specific pricing.
     */
    public function updateBranchPricing(Request $request, Product $product)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'selling_price' => 'required|numeric|min:0',
            'is_active' => 'required|boolean',
        ]);

        BranchProductPricing::updateOrCreate(
            [
                'tenant_id' => $product->tenant_id,
                'branch_id' => $validated['branch_id'],
                'product_id' => $product->id,
            ],
            [
                'selling_price' => $validated['selling_price'],
                'status' => $validated['is_active'] ? 'active' : 'inactive',
            ]
        );

        return redirect()->back()->with('success', 'Branch pricing updated.');
    }

    /**
     * Remove branch-specific pricing override.
     */
    public function destroyBranchPricing(Product $product, BranchProductPricing $branchPricing)
    {
        $branchPricing->delete();
        return redirect()->back()->with('success', 'Branch pricing override removed.');
    }

    /**
     * Update the product's recipe.
     */
    public function updateRecipe(Request $request, Product $product)
    {
        $validated = $request->validate([
            'ingredients' => 'present|array',
            'ingredients.*.ingredient_id' => 'required|exists:products,id',
            'ingredients.*.quantity' => 'required|numeric|min:0.0001',
            'ingredients.*.unit' => 'required|string',
        ]);

        // Sync ingredients: Delete old, insert new
        ProductRecipe::where('product_id', $product->id)->delete();

        foreach ($validated['ingredients'] as $item) {
            ProductRecipe::create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'ingredient_id' => $item['ingredient_id'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
            ]);
        }

        return redirect()->back()->with('success', 'Product recipe updated successfully.');
    }

    /**
     * Return the WAC-based recipe cost for the given product.
     *
     * GET /admin/products/{product}/recipe-cost?branch_id={uuid}
     */
    public function recipeCost(Request $request, Product $product, RecipeCostingService $costingService)
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $result = $costingService->compute($product, $validated['branch_id'] ?? null);

        return response()->json($result);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        // Check for sales before deleting
        // For now, just allow deletion but in a real app we'd check relationships
        $product->delete();

        return redirect()->route('admin.products.index')
            ->with('success', 'Product deleted successfully.');
    }

    private function filteredIndexQuery(Request $request): Builder
    {
        $query = Product::with(['category', 'taxCategory']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('product_category_id', $request->category_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $query;
    }
}
