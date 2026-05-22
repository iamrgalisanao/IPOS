<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\UnitConversion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UnitConversionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = UnitConversion::with('product');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('from_unit', 'like', "%{$search}%")
                  ->orWhere('to_unit', 'like', "%{$search}%")
                  ->orWhereHas('product', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%")
                         ->orWhere('sku', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('type')) {
            if ($request->type === 'global') {
                $query->whereNull('product_id');
            } elseif ($request->type === 'product') {
                $query->whereNotNull('product_id');
            }
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $conversions = $query->latest()->paginate(25)->withQueryString();

        $products = Product::select('id', 'name', 'sku')
            ->orderBy('name')
            ->get();

        return Inertia::render('Inventory/UnitConversions/Index', [
            'conversions' => $conversions,
            'products' => $products,
            'filters' => $request->only(['search', 'type', 'status']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $tenantId = app(\App\Services\TenantContext::class)->getTenantId();

        $validated = $request->validate([
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'from_unit' => ['required', 'string', 'max:50'],
            'to_unit' => ['required', 'string', 'max:50', 'different:from_unit'],
            'conversion_factor' => ['required', 'numeric', 'gt:0'],
            'is_active' => ['boolean'],
        ]);

        // Custom composite uniqueness check to avoid DB exceptions
        $existsQuery = UnitConversion::where('from_unit', $validated['from_unit'])
            ->where('to_unit', $validated['to_unit']);

        if (!empty($validated['product_id'])) {
            $existsQuery->where('product_id', $validated['product_id']);
        } else {
            $existsQuery->whereNull('product_id');
        }

        if ($existsQuery->exists()) {
            return back()->withErrors([
                'from_unit' => 'A conversion rule for these units already exists for this scope.',
            ]);
        }

        UnitConversion::create([
            'tenant_id' => $tenantId,
            'product_id' => $validated['product_id'] ?? null,
            'from_unit' => $validated['from_unit'],
            'to_unit' => $validated['to_unit'],
            'conversion_factor' => $validated['conversion_factor'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Unit conversion rule created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, UnitConversion $unitConversion)
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'from_unit' => ['required', 'string', 'max:50'],
            'to_unit' => ['required', 'string', 'max:50', 'different:from_unit'],
            'conversion_factor' => ['required', 'numeric', 'gt:0'],
            'is_active' => ['boolean'],
        ]);

        // Custom composite uniqueness check (excluding current record)
        $existsQuery = UnitConversion::where('id', '!=', $unitConversion->id)
            ->where('from_unit', $validated['from_unit'])
            ->where('to_unit', $validated['to_unit']);

        if (!empty($validated['product_id'])) {
            $existsQuery->where('product_id', $validated['product_id']);
        } else {
            $existsQuery->whereNull('product_id');
        }

        if ($existsQuery->exists()) {
            return back()->withErrors([
                'from_unit' => 'A conversion rule for these units already exists for this scope.',
            ]);
        }

        $unitConversion->update([
            'product_id' => $validated['product_id'] ?? null,
            'from_unit' => $validated['from_unit'],
            'to_unit' => $validated['to_unit'],
            'conversion_factor' => $validated['conversion_factor'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Unit conversion rule updated successfully.');
    }

    /**
     * Deactivate the specified resource (instead of hard-deleting).
     */
    public function destroy(UnitConversion $unitConversion)
    {
        $unitConversion->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Unit conversion rule deactivated successfully.');
    }
}
