<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustmentReason;
use App\Services\Inventory\InventoryAdjustmentReasonService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class InventoryAdjustmentReasonController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected InventoryAdjustmentReasonService $reasons,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('inventory.adjustment.reason.manage'), 403);

        return Inertia::render('Admin/InventoryAdjustmentReasons/Index', [
            'reasons' => InventoryAdjustmentReason::where('tenant_id', $this->tenantContext->getTenantId())
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(),
            'categories' => InventoryAdjustmentReason::ALLOWED_CATEGORIES,
            'directions' => InventoryAdjustmentReason::ALLOWED_DIRECTIONS,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('inventory.adjustment.reason.manage'), 403);

        $validated = $this->validatePayload($request);
        $this->reasons->create($validated);

        return back()->with('success', 'Inventory adjustment reason created.');
    }

    public function update(Request $request, InventoryAdjustmentReason $reason)
    {
        abort_unless($request->user()->hasPermission('inventory.adjustment.reason.manage'), 403);
        abort_unless($reason->tenant_id === $this->tenantContext->getTenantId(), 403);

        $validated = $this->validatePayload($request, updating: true);
        $this->reasons->replace($reason, $validated);

        return back()->with('success', 'Inventory adjustment reason version updated.');
    }

    public function destroy(Request $request, InventoryAdjustmentReason $reason)
    {
        abort_unless($request->user()->hasPermission('inventory.adjustment.reason.manage'), 403);
        abort_unless($reason->tenant_id === $this->tenantContext->getTenantId(), 403);

        $this->reasons->deactivate($reason);

        return back()->with('success', 'Inventory adjustment reason deactivated.');
    }

    protected function validatePayload(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'code' => [$updating ? 'sometimes' : 'required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'reason_category' => ['required', 'string', Rule::in(InventoryAdjustmentReason::ALLOWED_CATEGORIES)],
            'direction_policy' => ['required', 'string', Rule::in(InventoryAdjustmentReason::ALLOWED_DIRECTIONS)],
            'requires_notes' => ['required', 'boolean'],
            'evidence_required' => ['required', 'boolean'],
            'is_opening_balance' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ], [
            'code.regex' => 'The code must be uppercase alphanumeric with underscores only.',
        ]);
    }
}
