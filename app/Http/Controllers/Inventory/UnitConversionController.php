<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\UnitConversion;
use App\Services\Inventory\UnitConversionGovernanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UnitConversionController extends Controller
{
    public function __construct(
        protected UnitConversionGovernanceService $governanceService
    ) {}

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
        $validated = $request->validate([
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'from_unit' => ['required', 'string', 'max:50'],
            'to_unit' => ['required', 'string', 'max:50', 'different:from_unit'],
            'conversion_factor' => ['required', 'numeric', 'gt:0'],
            'source_unit_kind' => ['nullable', 'string', 'in:mass,volume,count,package,custom'],
            'target_unit_kind' => ['nullable', 'string', 'in:mass,volume,count,package,custom'],
            'is_active' => ['boolean'],
        ]);

        $this->governanceService->create($validated, $request->user()?->id);

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
            'source_unit_kind' => ['nullable', 'string', 'in:mass,volume,count,package,custom'],
            'target_unit_kind' => ['nullable', 'string', 'in:mass,volume,count,package,custom'],
            'is_active' => ['boolean'],
        ]);

        $this->governanceService->replace($unitConversion, $validated, $request->user()?->id);

        return redirect()->back()->with('success', 'Unit conversion rule version created successfully.');
    }

    /**
     * Deactivate the specified resource (instead of hard-deleting).
     */
    public function destroy(UnitConversion $unitConversion)
    {
        $this->governanceService->deactivate($unitConversion, request()->user()?->id);

        return redirect()->back()->with('success', 'Unit conversion rule deactivated successfully.');
    }
}
