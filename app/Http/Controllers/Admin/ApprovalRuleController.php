<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApprovalRuleController extends Controller
{
    public function index(TenantContext $tenantContext)
    {
        return Inertia::render('Admin/ApprovalRules/Index', [
            'rules' => ApprovalRule::where('action', ApprovalRule::ACTION_STATUTORY_DISCOUNT)->get(),
            'branches' => Branch::where('tenant_id', $tenantContext->getTenantId())->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, TenantContext $tenantContext, AuditLogger $auditLogger)
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'uuid'],
            'always_require_approval' => ['required', 'boolean'],
        ]);
        $branchId = $validated['branch_id'] ?? null;
        if ($branchId && !Branch::where('tenant_id', $tenantContext->getTenantId())->whereKey($branchId)->exists()) {
            abort(404);
        }
        $rule = ApprovalRule::updateOrCreate([
            'tenant_id' => $tenantContext->getTenantId(),
            'scope_key' => $branchId ? 'branch:' . $branchId : 'tenant',
            'action' => ApprovalRule::ACTION_STATUTORY_DISCOUNT,
        ], [
            'branch_id' => $branchId,
            'always_require_approval' => $validated['always_require_approval'],
            'updated_by' => $request->user()->id,
        ]);
        $auditLogger->log('statutory_discount_approval_rule_updated', $rule, metadata: [
            'branch_id' => $branchId, 'always_require_approval' => $rule->always_require_approval,
        ]);
        return back()->with('success', 'Statutory discount approval rule updated.');
    }
}
