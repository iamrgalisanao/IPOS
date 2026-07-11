<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\BranchPaymentMethodSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BranchPaymentSettingsController extends Controller
{
    /**
     * Show the branch payment settings screen.
     */
    public function edit(Request $request, Branch $branch)
    {
        if (!$request->user()->hasPermission('manage_payment_methods')) {
            abort(403, 'Unauthorized.');
        }

        $paymentMethods = PaymentMethod::active()
            ->where('tenant_id', $branch->tenant_id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $resolvedMethods = $paymentMethods->map(fn (PaymentMethod $method) =>
            $method->getSettingsForBranch($branch->id)
        );

        return Inertia::render('Admin/Branches/PaymentSettings', [
            'branch'         => $branch,
            'paymentMethods' => $resolvedMethods,
        ]);
    }

    /**
     * Update the payment method settings for a branch.
     */
    public function update(Request $request, Branch $branch)
    {
        if (!$request->user()->hasPermission('manage_payment_methods')) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.payment_method_id' => ['required', 'uuid', 'exists:payment_methods,id'],
            'settings.*.enabled' => ['required', 'boolean'],
            'settings.*.allow_offline' => ['required', 'boolean'],
            'settings.*.offline_max_limit_centavos' => ['nullable', 'integer', 'min:0'],
            'settings.*.requires_reference' => ['required', 'boolean'],
            'settings.*.sort_order' => ['required', 'integer'],
            'settings.*.offline_policy_note' => ['nullable', 'string', 'max:500'],
        ]);

        // Loop and upsert settings
        foreach ($validated['settings'] as $item) {
            $paymentMethod = PaymentMethod::findOrFail($item['payment_method_id']);

            // Validate that we only allow offline settings for Cash or custom payment methods
            $isCash = $paymentMethod->isCash();
            $isCustom = strtolower($paymentMethod->type) === 'custom' || strtolower($paymentMethod->type) === 'custom_offline';

            if ($item['allow_offline'] && !$isCash && !$isCustom) {
                return back()->withErrors([
                    "settings" => "Payment method {$paymentMethod->name} of type {$paymentMethod->type} cannot be enabled for offline use."
                ]);
            }

            // Get original/before values for audit logging
            $oldSetting = BranchPaymentMethodSetting::where('branch_id', $branch->id)
                ->where('payment_method_id', $paymentMethod->id)
                ->first();

            $beforeValues = $oldSetting ? [
                'enabled' => (bool) $oldSetting->enabled,
                'allow_offline' => (bool) $oldSetting->allow_offline,
                'offline_max_limit_centavos' => $oldSetting->offline_max_limit_centavos,
                'requires_reference' => (bool) $oldSetting->requires_reference,
                'sort_order' => (int) $oldSetting->sort_order,
                'offline_policy_note' => $oldSetting->offline_policy_note,
            ] : null;

            $newValues = [
                'tenant_id' => $branch->tenant_id,
                'enabled' => $item['enabled'],
                'allow_offline' => $item['allow_offline'],
                'offline_max_limit_centavos' => $item['offline_max_limit_centavos'],
                'requires_reference' => $item['requires_reference'],
                'sort_order' => $item['sort_order'],
                'offline_policy_note' => $item['offline_policy_note'],
            ];

            $newSetting = BranchPaymentMethodSetting::updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'payment_method_id' => $paymentMethod->id,
                ],
                $newValues
            );

            // Audit log the update
            app(\App\Services\AuditLogger::class)->log(
                'branch_payment_method_settings_updated',
                $newSetting,
                $beforeValues,
                $newValues,
                null,
                "Updated payment overrides for branch {$branch->name} and method {$paymentMethod->name}"
            );
        }

        return redirect()->back()->with('success', 'Branch payment method settings updated successfully.');
    }
}
