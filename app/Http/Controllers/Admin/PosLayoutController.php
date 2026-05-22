<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePosLayoutRequest;
use App\Http\Requests\UpdatePosLayoutRequest;
use App\Models\PosLayout;
use App\Services\POS\PosLayoutSchemaValidator;
use App\Services\POS\PosLayoutPublishService;
use App\Services\CatalogService;
use App\Models\ProductCategory;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PosLayoutController extends Controller
{
    public function __construct(
        protected CatalogService $catalogService,
        protected PosLayoutPublishService $publishService
    ) {
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
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $this->authorize('create', PosLayout::class);

        return Inertia::render('Admin/PosLayouts/Create');
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

        // Fetch products, categories, and branches for the editor/publisher
        $products = $this->catalogService->search('');
        $categories = ProductCategory::active()->get();
        $branches = Branch::active()->get();

        // Fetch deployment history for this layout
        $history = \Illuminate\Support\Facades\DB::table('branch_pos_layout')
            ->join('branches', 'branch_pos_layout.branch_id', '=', 'branches.id')
            ->where('branch_pos_layout.pos_layout_id', $posLayout->id)
            ->select(
                'branches.name as branch_name',
                'branch_pos_layout.*'
            )
            ->latest('branch_pos_layout.published_at')
            ->get();

        return Inertia::render('Admin/PosLayouts/Show', [
            'layout' => $posLayout,
            'registry' => [
                'products' => $products,
                'categories' => $categories,
                'branches' => $branches,
            ],
            'history' => $history,
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
     * Publish the specified resource.
     */
    public function publish(Request $request, PosLayout $posLayout)
    {
        $this->authorize('publish', $posLayout);

        $request->validate([
            'branch_ids' => 'required|array',
            'branch_ids.*' => 'required|uuid|exists:branches,id',
            'active_from' => 'nullable|date',
        ]);

        try {
            $this->publishService->publish(
                $posLayout,
                $request->branch_ids,
                Auth::user(),
                $request->active_from ? \Carbon\Carbon::parse($request->active_from) : null
            );

            return redirect()->route('admin.pos-layouts.index')
                ->with('success', 'POS layout published and deployed successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['publish' => $e->getMessage()]);
        }
    }

    /**
     * Rollback the specified branch to a previous layout.
     */
    public function rollback(Request $request, PosLayout $posLayout)
    {
        $this->authorize('publish', $posLayout);

        $request->validate([
            'branch_id' => 'required|uuid|exists:branches,id',
        ]);

        try {
            $this->publishService->publish(
                $posLayout,
                [$request->branch_id],
                Auth::user()
            );

            // Log rollback completion
            \App\Models\AuditLog::create([
                'tenant_id' => $posLayout->tenant_id,
                'branch_id' => $request->branch_id,
                'actor_user_id' => Auth::id(),
                'actor_type' => 'user',
                'action' => 'pos_layout_rollback_completed',
                'auditable_type' => PosLayout::class,
                'auditable_id' => $posLayout->id,
                'metadata' => [
                    'branch_id' => $request->branch_id,
                    'layout_id' => $posLayout->id,
                    'layout_version' => $posLayout->version,
                ],
            ]);

            return back()->with('success', 'POS layout rolled back and deployed successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['rollback' => $e->getMessage()]);
        }
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
            case 'publish':
                abort_unless(
                    $user->hasPermission('pos-layouts.publish'),
                    403,
                    'Publishing permission required for POS layouts.'
                );
                break;
        }
    }
}
