<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesMachineProfile;
use App\Models\Branch;
use App\Models\PrinterProfile;
use App\Services\POS\OfflineReadiness\OfflineSettingsValidator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesMachineProfileController extends Controller
{
    public function __construct(
        protected OfflineSettingsValidator $validator
    ) {}

    /**
     * List all terminals and their offline settings across branches.
     */
    public function index(Request $request)
    {
        $query = SalesMachineProfile::query()
            ->with(['branch', 'posLayout', 'latestHeartbeat']);

        $user = $request->user();
        if ($user->actor_type !== 'system_admin' && !$user->hasRole('Owner/Admin')) {
            $authorizedBranchIds = $user->branches()->pluck('branches.id');
            $query->whereIn('branch_id', $authorizedBranchIds);
            $branches = Branch::whereIn('id', $authorizedBranchIds)
                ->orderBy('name')
                ->get(['id', 'name', 'branch_code']);
        } else {
            $branches = Branch::orderBy('name')->get(['id', 'name', 'branch_code']);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $profiles = $query->orderBy('profile_code')->paginate(20)->withQueryString();

        return Inertia::render('Admin/SalesMachineProfiles/Index', [
            'profiles' => $profiles,
            'branches' => $branches,
            'filters'  => $request->only(['branch_id']),
            'flash'    => [
                'success' => $request->session()->get('success'),
                'activation_code_raw' => $request->session()->get('activation_code_raw'),
            ],
        ]);
    }

    /**
     * Show the offline settings editor for a specific terminal.
     */
    public function edit(SalesMachineProfile $salesMachineProfile)
    {
        $this->authorizeTerminalBranch(request(), $salesMachineProfile);
        $salesMachineProfile->load(['branch.tenant', 'latestHeartbeat']);

        $validationResult = $this->validator->validate(
            $salesMachineProfile->branch->tenant,
            $salesMachineProfile->branch,
            $salesMachineProfile
        );

        $printerProfiles = PrinterProfile::where('tenant_id', $salesMachineProfile->tenant_id)
            ->where('branch_id', $salesMachineProfile->branch_id)
            ->active()
            ->where('role', 'receipt')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/SalesMachineProfiles/Edit', [
            'profile'          => $salesMachineProfile,
            'offlineStatus'    => $validationResult,
            'printerProfiles'  => $printerProfiles,
        ]);
    }

    /**
     * Update the offline settings for a specific terminal.
     */
    public function update(Request $request, SalesMachineProfile $salesMachineProfile)
    {
        $this->authorizeTerminalBranch($request, $salesMachineProfile);

        if ($request->exists('printer_profile_id')) {
            abort_unless($request->user()->hasPermission('manage_printer_profiles'), 403);
        }

        $validated = $request->validate([
            'offline_sales_enabled'      => ['nullable', 'boolean'],
            'offline_sequence_prefix'    => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[A-Z0-9\-]+$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($salesMachineProfile) {
                    if ($value === null) {
                        return;
                    }
                    $exists = SalesMachineProfile::where('tenant_id', $salesMachineProfile->tenant_id)
                        ->where('offline_sequence_prefix', $value)
                        ->where('id', '!=', $salesMachineProfile->id)
                        ->exists();

                    if ($exists) {
                        $fail("The prefix \"{$value}\" is already assigned to another terminal in this tenant.");
                    }
                },
            ],
            'offline_sequence_next_value' => ['nullable', 'integer', 'min:1'],
            'offline_sequence_status'     => ['nullable', 'string', 'in:active,suspended,depleted'],
            'printer_profile_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, \Closure $fail) use ($salesMachineProfile) {
                    if ($value === null) {
                        return;
                    }
                    $printer = PrinterProfile::where('id', $value)->first();
                    if (!$printer) {
                        $fail('The selected printer profile does not exist.');
                        return;
                    }
                    if ($printer->tenant_id !== $salesMachineProfile->tenant_id) {
                        $fail('The selected printer profile belongs to a different tenant.');
                    }
                    if ($printer->branch_id !== $salesMachineProfile->branch_id) {
                        $fail('The selected printer profile belongs to a different branch.');
                    }
                    if (!$printer->is_active) {
                        $fail('Inactive printer profile cannot be assigned to a terminal.');
                    }
                    if ($printer->role !== 'receipt') {
                        $fail('Only a receipt printer profile can be assigned to a terminal.');
                    }
                }
            ],
        ]);

        // Enforce no-decrement guard at controller level as well
        if (
            isset($validated['offline_sequence_next_value']) &&
            $validated['offline_sequence_next_value'] < $salesMachineProfile->offline_sequence_next_value
        ) {
            return back()->withErrors([
                'offline_sequence_next_value' => 'The offline sequence next value cannot be decreased.',
            ]);
        }

        // Prevent sequence edits if the terminal has unsynced offline sales and no override is passed
        $latestHeartbeat = $salesMachineProfile->latestHeartbeat;
        if ($latestHeartbeat && $latestHeartbeat->queue_count > 0 && !$request->boolean('admin_override')) {
            if (
                isset($validated['offline_sequence_prefix']) &&
                $validated['offline_sequence_prefix'] !== $salesMachineProfile->offline_sequence_prefix
            ) {
                return back()->withErrors([
                    'offline_sequence_prefix' => 'The sequence prefix cannot be changed while the terminal has unsynced offline sales.',
                ]);
            }
            if (
                isset($validated['offline_sequence_next_value']) &&
                (int)$validated['offline_sequence_next_value'] !== (int)$salesMachineProfile->offline_sequence_next_value
            ) {
                return back()->withErrors([
                    'offline_sequence_next_value' => 'The next sequence value cannot be changed while the terminal has unsynced offline sales.',
                ]);
            }
        }

        $salesMachineProfile->update($validated);

        return redirect()
            ->route('admin.sales-machine-profiles.index')
            ->with('success', "Terminal {$salesMachineProfile->profile_code} offline settings updated.");
    }

    /**
     * API endpoint: return current offline validation status for a terminal (read-only).
     */
    public function offlineStatus(SalesMachineProfile $salesMachineProfile)
    {
        $this->authorizeTerminalBranch(request(), $salesMachineProfile);
        $salesMachineProfile->load('branch.tenant');

        $result = $this->validator->validate(
            $salesMachineProfile->branch->tenant,
            $salesMachineProfile->branch,
            $salesMachineProfile
        );

        return response()->json([
            'terminal_id'     => $salesMachineProfile->id,
            'profile_code'    => $salesMachineProfile->profile_code,
            'offline_status'  => $result,
        ]);
    }

    /**
     * Generate a short-lived activation code for a terminal profile.
     */
    public function generateActivationCode(SalesMachineProfile $salesMachineProfile)
    {
        $this->authorizeTerminalBranch(request(), $salesMachineProfile);
        $code = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8));
        $hash = hash('sha256', $code);

        $salesMachineProfile->update([
            'activation_token_hash'       => $hash,
            'activation_token_expires_at' => now()->addHours(24),
            'activation_status'           => SalesMachineProfile::STATUS_PENDING_ACTIVATION,
            'activated_at'                => null,
            'activated_device_id'         => null,
        ]);

        app(\App\Services\AuditLogger::class)->log(
            'terminal_activation_code_generated',
            $salesMachineProfile,
            null,
            ['activation_status' => $salesMachineProfile->activation_status],
            null,
            "Generated activation code for terminal {$salesMachineProfile->profile_code}"
        );

        return back()->with([
            'success'             => "Activation code generated for terminal {$salesMachineProfile->profile_code}.",
            'activation_code_raw' => $code,
        ]);
    }

    /**
     * Revoke activation for a terminal profile.
     */
    public function revokeActivation(SalesMachineProfile $salesMachineProfile)
    {
        $this->authorizeTerminalBranch(request(), $salesMachineProfile);
        $salesMachineProfile->update([
            'activation_status'           => SalesMachineProfile::STATUS_REVOKED,
            'activation_token_hash'       => null,
            'activation_token_expires_at' => null,
            'activated_device_id'         => null,
            'activated_at'                => null,
            'last_activated_ip'           => null,
        ]);

        app(\App\Services\AuditLogger::class)->log(
            'terminal_activation_revoked',
            $salesMachineProfile,
            null,
            ['activation_status' => $salesMachineProfile->activation_status],
            null,
            "Revoked activation for terminal {$salesMachineProfile->profile_code}"
        );

        return back()->with('success', "Activation revoked for terminal {$salesMachineProfile->profile_code}.");
    }

    private function authorizeTerminalBranch(Request $request, SalesMachineProfile $salesMachineProfile): void
    {
        $user = $request->user();

        abort_unless($salesMachineProfile->tenant_id === $user->tenant_id, 403);

        if ($user->actor_type !== 'system_admin' && !$user->hasRole('Owner/Admin')) {
            abort_unless(
                $user->branches()->whereKey($salesMachineProfile->branch_id)->exists(),
                403,
                'Unauthorized branch access.'
            );
        }
    }
}
