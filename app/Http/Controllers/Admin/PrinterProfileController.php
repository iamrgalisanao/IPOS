<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PrinterProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PrinterProfileController extends Controller
{
    /**
     * Display a listing of printer profiles.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = PrinterProfile::where('tenant_id', $tenantId)
            ->withCount('salesMachineProfiles');

        // Scoping for Branch Managers
        if ($user->actor_type !== 'system_admin' && !$user->hasRole('Owner/Admin')) {
            $userBranchIds = $user->branches->pluck('id')->toArray();
            $query->whereIn('branch_id', $userBranchIds);
            $branches = $user->branches;
        } else {
            $branches = Branch::where('tenant_id', $tenantId)->orderBy('name')->get();
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $profiles = $query->orderBy('name')->get();

        return Inertia::render('Admin/PrinterProfiles/Index', [
            'profiles' => $profiles,
            'branches' => $branches,
            'filters' => $request->only(['branch_id']),
        ]);
    }

    /**
     * Store a newly created printer profile.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $validated = $this->validateRequest($request);

        // Branch verification
        if ($user->actor_type !== 'system_admin' && !$user->hasRole('Owner/Admin')) {
            $userBranchIds = $user->branches->pluck('id')->toArray();
            abort_unless(in_array($validated['branch_id'], $userBranchIds), 403, 'Unauthorized branch access.');
        }

        // Single-default rule for receipt printers per branch
        DB::transaction(function () use ($validated, $tenantId): void {
            $this->lockBranch($validated['branch_id']);
            $this->lockReceiptProfiles($tenantId, $validated['branch_id']);

            if ($validated['is_default']) {
                $this->clearReceiptDefault($tenantId, $validated['branch_id']);
            }

            PrinterProfile::create(array_merge($validated, [
                'tenant_id' => $tenantId,
            ]));
        });

        return back()->with('success', "Printer profile '{$validated['name']}' created successfully.");
    }

    /**
     * Update the specified printer profile.
     */
    public function update(Request $request, PrinterProfile $printerProfile)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        // Security check
        abort_unless($printerProfile->tenant_id === $tenantId, 403);

        $validated = $this->validateRequest($request);

        // Branch verification
        if ($user->actor_type !== 'system_admin' && !$user->hasRole('Owner/Admin')) {
            $userBranchIds = $user->branches->pluck('id')->toArray();
            abort_unless(in_array($validated['branch_id'], $userBranchIds), 403, 'Unauthorized branch access.');
            abort_unless(in_array($printerProfile->branch_id, $userBranchIds), 403, 'Unauthorized branch access.');
        }

        if ($printerProfile->salesMachineProfiles()->exists()) {
            if ($validated['branch_id'] !== $printerProfile->branch_id) {
                return back()->withErrors([
                    'branch_id' => 'An assigned receipt printer cannot be moved to another branch.',
                ]);
            }

            if ($validated['role'] !== 'receipt') {
                return back()->withErrors([
                    'role' => 'An assigned receipt printer cannot be changed to another role.',
                ]);
            }
        }

        // Single-default rule for receipt printers per branch
        DB::transaction(function () use ($validated, $tenantId, $printerProfile): void {
            $branchIds = array_values(array_unique([$printerProfile->branch_id, $validated['branch_id']]));
            sort($branchIds);

            foreach ($branchIds as $branchId) {
                $this->lockBranch($branchId);
                $this->lockReceiptProfiles($tenantId, $branchId);
            }

            if ($validated['is_default']) {
                $this->clearReceiptDefault($tenantId, $validated['branch_id'], $printerProfile->id);
            }

            $printerProfile->update($validated);
        });

        return back()->with('success', "Printer profile '{$printerProfile->name}' updated successfully.");
    }

    /**
     * Deactivate the specified printer profile (soft remove).
     */
    public function destroy(PrinterProfile $printerProfile)
    {
        $user = auth()->user();
        abort_unless($printerProfile->tenant_id === $user->tenant_id, 403);

        // Branch verification
        if ($user->actor_type !== 'system_admin' && !$user->hasRole('Owner/Admin')) {
            $userBranchIds = $user->branches->pluck('id')->toArray();
            abort_unless(in_array($printerProfile->branch_id, $userBranchIds), 403, 'Unauthorized branch access.');
        }

        // Set is_active = false as normal remove action (deactivation)
        $printerProfile->update([
            'is_active' => false,
            'is_default' => false,
        ]);

        return back()->with('success', "Printer profile '{$printerProfile->name}' has been deactivated.");
    }
    /**
     * Helper to validate inputs.
     */
    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'branch_id' => [
                'required',
                'uuid',
                'exists:branches,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $exists = Branch::where('id', $value)
                        ->where('tenant_id', auth()->user()->tenant_id)
                        ->exists();
                    if (!$exists) {
                        $fail('The selected branch does not belong to this tenant.');
                    }
                }
            ],
            'name' => ['required', 'string', 'max:255'],
            'connection_type' => ['required', 'string', 'in:usb,network,bluetooth,browser_print,system_default'],
            'identifier' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    $connectionType = $request->input('connection_type');
                    if ($connectionType === 'network') {
                        if (empty($value)) {
                            $fail('An IP address or hostname is required for network connection.');
                        } elseif (!filter_var($value, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9\.\-_]+$/', $value)) {
                            $fail('The identifier must be a valid IP address or hostname.');
                        }
                    } elseif ($connectionType === 'bluetooth') {
                        if (empty($value)) {
                            $fail('A bluetooth MAC address/identifier is required.');
                        }
                    } elseif ($connectionType === 'usb') {
                        if (empty($value)) {
                            $fail('A USB port name/identifier is required.');
                        }
                    }
                }
            ],
            'paper_width' => ['required', 'string', 'in:58mm,80mm'],
            'role' => ['required', 'string', 'in:receipt'],
            'template_type' => ['required', 'string', 'in:standard,custom'],
            'is_active' => ['required', 'boolean'],
            'is_default' => [
                'required',
                'boolean',
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    if (!$request->boolean('is_default')) {
                        return;
                    }

                    if (!$request->boolean('is_active')) {
                        $fail('Only an active printer profile can be the branch default.');
                    }

                    if ($request->input('role') !== 'receipt') {
                        $fail('Only a receipt printer profile can be the branch default.');
                    }
                },
            ],
        ]);
    }

    private function lockReceiptProfiles(string $tenantId, string $branchId): void
    {
        PrinterProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('role', 'receipt')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function lockBranch(string $branchId): void
    {
        Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
    }

    private function clearReceiptDefault(string $tenantId, string $branchId, ?string $exceptId = null): void
    {
        PrinterProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('role', 'receipt')
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }
}
