<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Str;

class SupplierController extends Controller
{
    /**
     * Display a listing of the suppliers.
     */
    public function index(Request $request)
    {
        $query = Supplier::query();

        // Search / filter by name or code
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Filter by active status
        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $suppliers = $query->orderBy('name')->get();

        return Inertia::render('Procurement/Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new supplier.
     */
    public function create()
    {
        return Inertia::render('Procurement/Suppliers/Create');
    }

    /**
     * Store a newly created supplier.
     */
    public function store(Request $request)
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('suppliers')->where('tenant_id', $tenantId),
            ],
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'payment_terms' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        // Standardize code to uppercase
        $validated['code'] = Str::upper($validated['code']);
        $validated['is_active'] = $request->boolean('is_active', true);

        Supplier::create($validated);

        return redirect()->route('procurement.suppliers.index')
            ->with('success', 'Supplier created successfully.');
    }

    /**
     * Display the specified supplier details.
     */
    public function show(Supplier $supplier)
    {
        return Inertia::render('Procurement/Suppliers/Show', [
            'supplier' => $supplier,
        ]);
    }

    /**
     * Show the form for editing the specified supplier.
     */
    public function edit(Supplier $supplier)
    {
        return Inertia::render('Procurement/Suppliers/Edit', [
            'supplier' => $supplier,
        ]);
    }

    /**
     * Update the specified supplier in storage.
     */
    public function update(Request $request, Supplier $supplier)
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('suppliers')->ignore($supplier->id)->where('tenant_id', $tenantId),
            ],
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'payment_terms' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        // Standardize code to uppercase
        $validated['code'] = Str::upper($validated['code']);
        $validated['is_active'] = $request->boolean('is_active', $supplier->is_active);

        $supplier->update($validated);

        return redirect()->route('procurement.suppliers.index')
            ->with('success', 'Supplier updated successfully.');
    }

    /**
     * Toggle active/inactive status.
     */
    public function toggleStatus(Supplier $supplier)
    {
        $supplier->update([
            'is_active' => !$supplier->is_active
        ]);

        return redirect()->back()
            ->with('success', 'Supplier status updated successfully.');
    }
}
