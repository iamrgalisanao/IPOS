<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePosLayoutRequest;
use App\Http\Requests\UpdatePosLayoutRequest;
use App\Models\PosLayout;
use App\Services\POS\PosLayoutSchemaValidator;
use App\Services\CatalogService;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PosLayoutController extends Controller
{
    public function __construct(protected CatalogService $catalogService)
    {
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', PosLayout::class);

        $layouts = PosLayout::query()
            ->latest()
            ->get();

        return Inertia::render('Admin/PosLayouts/Index', [
            'layouts' => $layouts,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePosLayoutRequest $request)
    {
        $data = $request->validated();
        
        // Default schema if not provided
        if (!isset($data['schema'])) {
            $data['schema'] = [
                'grid' => ['rows' => 4, 'columns' => 4],
                'tiles' => []
            ];
        }

        // Validate schema
        if (!PosLayoutSchemaValidator::validate($data['schema'])) {
            return back()->withErrors(['schema' => 'The provided POS layout schema is invalid or contains forbidden fields.']);
        }

        $layout = PosLayout::create([
            'name' => $data['name'],
            'schema' => $data['schema'],
            'status' => 'draft',
            'version' => 1,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()->route('admin.pos-layouts.show', $layout)
            ->with('success', 'POS layout created as draft.');
    }

    /**
     * Display the specified resource.
     */
    public function show(PosLayout $posLayout)
    {
        $this->authorize('view', $posLayout);

        // Fetch products and categories for the editor registry
        $products = $this->catalogService->search('');
        $categories = ProductCategory::active()->get();

        return Inertia::render('Admin/PosLayouts/Show', [
            'layout' => $posLayout,
            'registry' => [
                'products' => $products,
                'categories' => $categories,
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePosLayoutRequest $request, PosLayout $posLayout)
    {
        $this->authorize('update', $posLayout);

        if ($posLayout->status !== 'draft') {
            return back()->withErrors(['status' => 'Only draft layouts can be updated directly.']);
        }

        $data = $request->validated();

        // Validate schema
        if (!PosLayoutSchemaValidator::validate($data['schema'])) {
            return back()->withErrors(['schema' => 'The provided POS layout schema is invalid or contains forbidden fields.']);
        }

        $posLayout->update([
            'name' => $data['name'],
            'schema' => $data['schema'],
            'updated_by' => Auth::id(),
        ]);

        return back()->with('success', 'POS layout updated.');
    }

    /**
     * Archive the specified resource.
     */
    public function archive(Request $request, PosLayout $posLayout)
    {
        $this->authorize('manage', $posLayout);

        if ($posLayout->status === 'archived') {
            return back()->withErrors(['status' => 'Layout is already archived.']);
        }

        $posLayout->update([
            'status' => 'archived',
            'updated_by' => Auth::id(),
        ]);

        return back()->with('success', 'POS layout archived.');
    }

    /**
     * Authorize the action.
     */
    protected function authorize($ability, $arguments = [])
    {
        $user = Auth::user();
        
        switch ($ability) {
            case 'viewAny':
            case 'view':
                abort_unless(
                    $user->hasPermission('pos-layouts.view') || $user->hasPermission('pos-layouts.manage'),
                    403,
                    'Unauthorized access to POS layouts.'
                );
                break;
            case 'update':
            case 'manage':
                abort_unless(
                    $user->hasPermission('pos-layouts.manage'),
                    403,
                    'Management permission required for POS layouts.'
                );
                break;
        }
    }
}
