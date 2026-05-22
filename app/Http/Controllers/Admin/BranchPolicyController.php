<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BranchPolicyController extends Controller
{
    /**
     * Display a listing of branches for editing policies.
     */
    public function index(Request $request)
    {
        $query = Branch::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('branch_code', 'like', "%{$search}%");
            });
        }

        return Inertia::render('Admin/Branches/Index', [
            'branches' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Update the branch inventory deduction policy.
     */
    public function update(Request $request, Branch $branch)
    {
        $validated = $request->validate([
            'inventory_deduction_policy' => ['required', 'string', 'in:strict_block,allow_negative_with_warning'],
        ]);

        $branch->update([
            'inventory_deduction_policy' => $validated['inventory_deduction_policy'],
        ]);

        return redirect()->back()->with('success', 'Branch inventory deduction policy updated successfully.');
    }
}
