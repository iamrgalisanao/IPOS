<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TenantProvisioningController extends Controller
{
    public function index(Request $request)
    {
        $query = Tenant::query()->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        $tenants = $query->paginate(20)->withQueryString();

        $tenants->getCollection()->transform(function (Tenant $tenant) {
            $tenant->setAttribute('readiness', $this->buildReadiness($tenant));
            return $tenant;
        });

        return Inertia::render('SystemAdmin/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => $request->only(['search']),
            'plans' => $this->availablePlans(),
            'featureCoverage' => $this->featureCoverageSummary(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['trial', 'active', 'suspended'])],
            'currency' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'tax_mode' => ['nullable', Rule::in(['inclusive', 'exclusive'])],
            'business_registration_number' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', Rule::in(array_keys($this->availablePlans()))],
            'feature_overrides' => ['nullable', 'array'],
        ]);

        $metadata = [
            'plan' => $validated['plan'] ?? config('subscriptions.default_tier', 'basic'),
        ];

        if (!empty($validated['feature_overrides'])) {
            $metadata['features'] = array_map(fn ($value) => (bool) $value, $validated['feature_overrides']);
        }

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'status' => $validated['status'],
            'currency' => $validated['currency'] ?? 'PHP',
            'timezone' => $validated['timezone'] ?? 'Asia/Manila',
            'tax_mode' => $validated['tax_mode'] ?? 'exclusive',
            'business_registration_number' => $validated['business_registration_number'] ?? null,
            'subscription_metadata' => $metadata,
        ]);

        return redirect()->route('system-admin.tenants.index')->with('success', "Tenant {$tenant->name} created.");
    }

    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['trial', 'active', 'suspended'])],
            'currency' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'tax_mode' => ['nullable', Rule::in(['inclusive', 'exclusive'])],
            'business_registration_number' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', Rule::in(array_keys($this->availablePlans()))],
            'feature_overrides' => ['nullable', 'array'],
        ]);

        $metadata = $tenant->subscription_metadata ?? [];
        $metadata['plan'] = $validated['plan'] ?? ($metadata['plan'] ?? config('subscriptions.default_tier', 'basic'));

        if (array_key_exists('feature_overrides', $validated)) {
            $metadata['features'] = array_map(fn ($value) => (bool) $value, $validated['feature_overrides'] ?? []);
        }

        $tenant->update([
            'name' => $validated['name'],
            'status' => $validated['status'],
            'currency' => $validated['currency'] ?? $tenant->currency,
            'timezone' => $validated['timezone'] ?? $tenant->timezone,
            'tax_mode' => $validated['tax_mode'] ?? $tenant->tax_mode,
            'business_registration_number' => $validated['business_registration_number'] ?? null,
            'subscription_metadata' => $metadata,
        ]);

        return redirect()->route('system-admin.tenants.index')->with('success', "Tenant {$tenant->name} updated.");
    }

    private function buildReadiness(Tenant $tenant): array
    {
        $missing = [];

        if (Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count() === 0) {
            $missing[] = 'initial_branch_missing';
        }

        $hasOwner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('actor_type', 'tenant_user')
            ->where('status', 'active')
            ->exists();

        if (!$hasOwner) {
            $missing[] = 'owner_admin_missing';
        }

        $profiles = SalesMachineProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();

        if ($profiles->count() === 0) {
            $missing[] = 'machine_profile_missing';
        } elseif ($profiles->every(fn (SalesMachineProfile $profile) => !$this->isMachineProfileComplianceComplete($profile))) {
            $missing[] = 'machine_profile_compliance_incomplete';
        }

        $plan = ($tenant->subscription_metadata ?? [])['plan'] ?? null;
        if (!$plan) {
            $missing[] = 'subscription_plan_missing';
        }

        return [
            'ready' => count($missing) === 0,
            'missing' => $missing,
        ];
    }

    private function availablePlans(): array
    {
        return config('subscriptions.tiers', []);
    }

    private function isMachineProfileComplianceComplete(SalesMachineProfile $profile): bool
    {
        $requiredFields = [
            'machine_identification_number',
            'machine_serial_number',
            'permit_to_use_number',
            'authority_to_generate_control_number',
            'supplier_accreditation_number',
        ];

        foreach ($requiredFields as $field) {
            if (blank($profile->{$field})) {
                return false;
            }
        }

        return true;
    }

    private function featureCoverageSummary(): array
    {
        $configured = collect(config('subscriptions.tiers', []))
            ->flatMap(fn ($tier) => array_keys($tier['features'] ?? []))
            ->unique()
            ->sort()
            ->values();

        $enforced = [
            'quickbooks.sync' => 'route-gated',
            'layout.custom' => 'route-gated',
        ];

        return $configured->map(function (string $flag) use ($enforced) {
            return [
                'feature_flag' => $flag,
                'config_exists' => true,
                'middleware_enforced' => array_key_exists($flag, $enforced),
                'enforcement_status' => $enforced[$flag] ?? 'partial_or_missing',
                'notes' => $enforced[$flag] ?? 'Configured in plan matrix; explicit route gate coverage pending.',
            ];
        })->all();
    }
}
