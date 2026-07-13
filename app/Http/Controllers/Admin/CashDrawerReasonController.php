<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashDrawerReason;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CashDrawerReasonController extends Controller
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Display a listing of the cash drawer reasons.
     */
    public function index(Request $request)
    {
        if (!$request->user()->hasPermission('manage_cash_drawer_reasons')) {
            abort(403, 'Unauthorized.');
        }

        $tenantId = $this->tenantContext->getTenantId();

        $reasons = CashDrawerReason::where('tenant_id', $tenantId)
            ->with('branch:id,name')
            ->orderBy('event_type')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $branches = Branch::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Admin/CashDrawerReasons/Index', [
            'reasons' => $reasons,
            'branches' => $branches,
        ]);
    }

    /**
     * Store a newly created cash drawer reason in storage.
     */
    public function store(Request $request)
    {
        if (!$request->user()->hasPermission('manage_cash_drawer_reasons')) {
            abort(403, 'Unauthorized.');
        }

        $tenantId = $this->tenantContext->getTenantId();

        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:cash_drop,cash_top_up'],
            'code' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9_]+$/',
                Rule::unique('cash_drawer_reasons')->where(function ($query) use ($tenantId, $request) {
                    return $query->where('tenant_id', $tenantId)
                        ->where('branch_id', $request->branch_id)
                        ->where('event_type', $request->event_type);
                }),
            ],
            'name' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'requires_manager_approval' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ], [
            'code.regex' => 'The code must be uppercase alphanumeric with underscores only (e.g. SPECIAL_SKIM).',
            'code.unique' => 'This code has already been registered for this event type at this scope.',
        ]);

        $validated['tenant_id'] = $tenantId;
        $validated['is_active'] = true;

        $reason = CashDrawerReason::create($validated);

        $this->auditLogger->log(
            'cash_drawer_reason_created',
            $reason,
            null,
            $reason->toArray(),
            null,
            "Created cash drawer reason {$reason->code} ({$reason->name})"
        );

        return redirect()->back()->with('success', 'Cash drawer reason created successfully.');
    }

    /**
     * Update the specified cash drawer reason in storage.
     */
    public function update(Request $request, CashDrawerReason $reason)
    {
        if (!$request->user()->hasPermission('manage_cash_drawer_reasons')) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'requires_manager_approval' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $beforeValues = [
            'name' => $reason->name,
            'requires_manager_approval' => (bool)$reason->requires_manager_approval,
            'is_active' => (bool)$reason->is_active,
            'sort_order' => (int)$reason->sort_order,
        ];

        $reason->update($validated);

        $newValues = [
            'name' => $reason->name,
            'requires_manager_approval' => (bool)$reason->requires_manager_approval,
            'is_active' => (bool)$reason->is_active,
            'sort_order' => (int)$reason->sort_order,
        ];

        $this->auditLogger->log(
            'cash_drawer_reason_updated',
            $reason,
            $beforeValues,
            $newValues,
            null,
            "Updated cash drawer reason {$reason->code}"
        );

        return redirect()->back()->with('success', 'Cash drawer reason updated successfully.');
    }

    /**
     * Deactivate the specified cash drawer reason.
     */
    public function destroy(Request $request, CashDrawerReason $reason)
    {
        if (!$request->user()->hasPermission('manage_cash_drawer_reasons')) {
            abort(403, 'Unauthorized.');
        }

        $beforeValues = ['is_active' => (bool)$reason->is_active];
        $reason->update(['is_active' => false]);
        $newValues = ['is_active' => false];

        $this->auditLogger->log(
            'cash_drawer_reason_deactivated',
            $reason,
            $beforeValues,
            $newValues,
            null,
            "Deactivated cash drawer reason {$reason->code}"
        );

        return redirect()->back()->with('success', 'Cash drawer reason deactivated successfully.');
    }
}
