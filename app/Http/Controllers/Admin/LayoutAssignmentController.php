<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PosLayout;
use App\Models\SalesMachineProfile;
use App\Services\POS\TerminalLayoutResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * LayoutAssignmentController
 *
 * Manages the optional per-terminal POS layout override on SalesMachineProfile.
 *
 * Business rules enforced here:
 *   1. Only published layouts may be assigned.
 *   2. The layout must belong to the same tenant as the profile.
 *   3. The layout must be associated with the same branch as the profile
 *      via branch_pos_layout (or the profile's branch — no cross-branch assignment).
 *   4. Requires `pos-layouts.manage` permission.
 *   5. Every assignment or removal is audit-logged.
 */
class LayoutAssignmentController extends Controller
{
    public function __construct(
        protected TerminalLayoutResolver $layoutResolver
    ) {}

    /**
     * PUT /admin/sales-machine-profiles/{profile}/layout-assignment
     *
     * Assign a specific published layout override to a terminal.
     */
    public function update(Request $request, SalesMachineProfile $profile): JsonResponse
    {
        $this->authorizeManage();

        $tenantId = $profile->tenant_id;
        $branchId = $profile->branch_id;

        $request->validate([
            'pos_layout_id' => 'required|uuid|exists:pos_layouts,id',
        ]);

        $layoutId = $request->input('pos_layout_id');

        // 1. Resolve the requested layout
        $layout = PosLayout::withoutGlobalScopes()->find($layoutId);

        if (!$layout) {
            return response()->json([
                'error'   => 'LAYOUT_NOT_FOUND',
                'message' => 'The selected layout does not exist.',
            ], 422);
        }

        // 2. Tenant guard: layout must belong to the same tenant
        if ($layout->tenant_id !== $tenantId) {
            return response()->json([
                'error'   => 'CROSS_TENANT_ASSIGNMENT',
                'message' => 'Cannot assign a layout from a different tenant.',
            ], 422);
        }

        // 3. Published guard: only published layouts can be assigned
        if ($layout->status !== PosLayout::STATUS_PUBLISHED) {
            return response()->json([
                'error'   => 'LAYOUT_NOT_PUBLISHED',
                'message' => 'Only published layouts can be assigned to terminals. Publish the layout first.',
            ], 422);
        }

        // 4. Branch guard: the layout must be associated with the terminal's branch via branch_pos_layout
        $branchAssociated = \Illuminate\Support\Facades\DB::table('branch_pos_layout')
            ->where('pos_layout_id', $layoutId)
            ->where('branch_id', $branchId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (!$branchAssociated) {
            return response()->json([
                'error'   => 'CROSS_BRANCH_ASSIGNMENT',
                'message' => 'The selected layout is not published to this terminal\'s branch.',
            ], 422);
        }

        // --- Capture before state for audit log ---
        $previousLayoutId   = $profile->pos_layout_id;
        $previousLayoutName = $previousLayoutId
            ? PosLayout::find($previousLayoutId)?->name ?? 'Unknown'
            : null;

        // --- Apply the override ---
        $profile->update(['pos_layout_id' => $layoutId]);

        AuditLog::create([
            'tenant_id'      => $tenantId,
            'branch_id'      => $branchId,
            'actor_user_id'  => Auth::id(),
            'actor_type'     => 'user',
            'action'         => 'terminal_layout_override_updated',
            'auditable_type' => SalesMachineProfile::class,
            'auditable_id'   => $profile->id,
            'metadata'       => [
                'sales_machine_profile_id' => $profile->id,
                'terminal_code'            => $profile->terminal_identifier ?? $profile->profile_code,
                'previous_pos_layout_id'   => $previousLayoutId,
                'new_pos_layout_id'        => $layoutId,
                'previous_layout_name'     => $previousLayoutName ?? 'Branch Default',
                'new_layout_name'          => $layout->name,
                'changed_by'               => Auth::id(),
            ],
        ]);

        return response()->json([
            'success'         => true,
            'message'         => "Layout override assigned: {$layout->name}",
            'pos_layout_id'   => $layoutId,
            'layout_name'     => $layout->name,
            'resolution_source' => TerminalLayoutResolver::SOURCE_TERMINAL_OVERRIDE,
        ]);
    }

    /**
     * DELETE /admin/sales-machine-profiles/{profile}/layout-assignment
     *
     * Remove the per-terminal layout override, reverting to the branch-active layout.
     */
    public function destroy(SalesMachineProfile $profile): JsonResponse
    {
        $this->authorizeManage();

        $tenantId = $profile->tenant_id;
        $branchId = $profile->branch_id;

        if (!$profile->pos_layout_id) {
            return response()->json([
                'success' => true,
                'message' => 'No layout override was set; terminal already uses the branch default layout.',
            ]);
        }

        $previousLayoutId   = $profile->pos_layout_id;
        $previousLayoutName = PosLayout::find($previousLayoutId)?->name ?? 'Unknown';

        $profile->update(['pos_layout_id' => null]);

        AuditLog::create([
            'tenant_id'      => $tenantId,
            'branch_id'      => $branchId,
            'actor_user_id'  => Auth::id(),
            'actor_type'     => 'user',
            'action'         => 'terminal_layout_override_removed',
            'auditable_type' => SalesMachineProfile::class,
            'auditable_id'   => $profile->id,
            'metadata'       => [
                'sales_machine_profile_id' => $profile->id,
                'terminal_code'            => $profile->terminal_identifier ?? $profile->profile_code,
                'previous_pos_layout_id'   => $previousLayoutId,
                'previous_layout_name'     => $previousLayoutName,
                'fallback'                 => 'branch_active_layout',
                'changed_by'               => Auth::id(),
            ],
        ]);

        return response()->json([
            'success'         => true,
            'message'         => 'Layout override removed. Terminal will use the branch-active layout.',
            'resolution_source' => TerminalLayoutResolver::SOURCE_BRANCH_DEFAULT,
        ]);
    }

    /**
     * Abort with 403 if the authenticated user lacks pos-layouts.manage permission.
     */
    private function authorizeManage(): void
    {
        abort_unless(
            Auth::user()->hasPermission('pos-layouts.manage'),
            403,
            'Management permission required to assign terminal layouts.'
        );
    }
}
