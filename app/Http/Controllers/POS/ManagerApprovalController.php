<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\ManagerApproval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ManagerApprovalController extends Controller
{
    /**
     * Authorize a specific action that requires manager override.
     */
    public function authorize(Request $request)
    {
        $request->validate([
            'approvable_type' => 'required|string',
            'approvable_id'   => 'required|uuid',
            'action'          => 'required|in:approve,deny',
            'reason'          => 'nullable|string|max:255',
            'metadata'        => 'nullable|array',
        ]);

        $manager = Auth::user();

        // Permission check: Only users with manager/supervisor roles can authorize
        if (!$manager->hasPermission('pos.manager_override')) {
            return response()->json([
                'status' => 'unauthorized',
                'message' => 'You do not have the required permissions to authorize this action.'
            ], 403);
        }

        $approval = ManagerApproval::create([
            'id'                => Str::uuid()->toString(),
            'tenant_id'         => $manager->tenant_id,
            'branch_id'         => $manager->branch_id,
            'user_id'           => $manager->id,
            'requesting_user_id' => $request->user()->id,
            'approvable_type'   => $request->approvable_type,
            'approvable_id'     => $request->approvable_id,
            'action'            => $request->action,
            'reason'            => $request->reason,
            'metadata'           => $request->metadata,
        ]);

        return response()->json([
            'status' => 'authorized',
            'approval_id' => $approval->id,
            'message' => 'Action successfully authorized by manager.'
        ]);
    }
}
