import React, { useMemo, useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function TenantProvisioningIndex() {
    const {
        auth,
        tenants = { data: [] },
        plans = {},
        featureCoverage = [],
        filters = {},
    } = usePage().props;

    const [selectedTenantId, setSelectedTenantId] = useState(null);

    const planKeys = useMemo(() => Object.keys(plans || {}), [plans]);
    const allFeatureFlags = useMemo(
        () => Array.from(new Set(featureCoverage.map((row) => row.feature_flag))).sort(),
        [featureCoverage]
    );

    const createForm = useForm({
        name: '',
        status: 'trial',
        plan: planKeys[0] ?? 'basic',
        feature_overrides: {},
    });

    const editForm = useForm({
        name: '',
        status: 'trial',
        plan: planKeys[0] ?? 'basic',
        feature_overrides: {},
    });

    const selectedTenant = useMemo(
        () => tenants.data.find((tenant) => tenant.id === selectedTenantId) ?? null,
        [selectedTenantId, tenants.data]
    );

    const openEdit = (tenant) => {
        setSelectedTenantId(tenant.id);

        const overrides = tenant.subscription_metadata?.features ?? {};

        editForm.setData({
            name: tenant.name ?? '',
            status: tenant.status ?? 'trial',
            plan: tenant.subscription_metadata?.plan ?? (planKeys[0] ?? 'basic'),
            feature_overrides: overrides,
        });
    };

    const toggleCreateOverride = (featureFlag, enabled) => {
        createForm.setData('feature_overrides', {
            ...createForm.data.feature_overrides,
            [featureFlag]: enabled,
        });
    };

    const toggleEditOverride = (featureFlag, enabled) => {
        editForm.setData('feature_overrides', {
            ...editForm.data.feature_overrides,
            [featureFlag]: enabled,
        });
    };

    const submitCreate = (event) => {
        event.preventDefault();

        createForm.post(route('system-admin.tenants.store'), {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset('name');
                createForm.setData('status', 'trial');
                createForm.setData('plan', planKeys[0] ?? 'basic');
                createForm.setData('feature_overrides', {});
            },
        });
    };

    const submitEdit = (event) => {
        event.preventDefault();

        if (!selectedTenant) {
            return;
        }

        editForm.put(route('system-admin.tenants.update', selectedTenant.id), {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedTenantId(null);
            },
        });
    };

    return (
        <AuthenticatedLayout
            user={auth?.user}
            header={
                <div>
                    <h2 className="text-2xl font-semibold text-slate-900">System Admin Tenant Provisioning</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        Manage tenant plans and supported overrides using existing entitlement controls.
                    </p>
                </div>
            }
        >
            <Head title="System Admin - Tenant Provisioning" />

            <div className="mx-auto max-w-7xl space-y-6 px-6 py-8">
                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Search</h3>

                    <form method="GET" action={route('system-admin.tenants.index')} className="mt-3 flex gap-3">
                        <input
                            name="search"
                            defaultValue={filters.search ?? ''}
                            placeholder="Search tenant name"
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                        <button type="submit" className="rounded-md bg-slate-900 px-4 py-2 text-sm text-white">
                            Apply
                        </button>
                    </form>
                </section>

                <section className="grid gap-6 lg:grid-cols-2">
                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Create Tenant</h3>

                        <form className="mt-3 space-y-3" onSubmit={submitCreate}>
                            <input
                                value={createForm.data.name}
                                onChange={(event) => createForm.setData('name', event.target.value)}
                                placeholder="Tenant name"
                                className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            />

                            <div className="grid grid-cols-2 gap-3">
                                <select
                                    value={createForm.data.status}
                                    onChange={(event) => createForm.setData('status', event.target.value)}
                                    className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                                >
                                    <option value="trial">trial</option>
                                    <option value="active">active</option>
                                    <option value="suspended">suspended</option>
                                </select>

                                <select
                                    value={createForm.data.plan}
                                    onChange={(event) => createForm.setData('plan', event.target.value)}
                                    className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                                >
                                    {planKeys.map((plan) => (
                                        <option key={plan} value={plan}>
                                            {plan}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <p className="text-xs font-medium text-slate-500">Tenant-level overrides</p>
                                <div className="mt-2 grid gap-2 md:grid-cols-2">
                                    {allFeatureFlags.map((featureFlag) => (
                                        <label key={featureFlag} className="flex items-center justify-between rounded border border-slate-200 px-3 py-2 text-xs">
                                            <span>{featureFlag}</span>
                                            <input
                                                type="checkbox"
                                                checked={Boolean(createForm.data.feature_overrides?.[featureFlag])}
                                                onChange={(event) => toggleCreateOverride(featureFlag, event.target.checked)}
                                            />
                                        </label>
                                    ))}
                                </div>
                            </div>

                            <button
                                type="submit"
                                disabled={createForm.processing}
                                className="rounded-md bg-emerald-600 px-4 py-2 text-sm text-white disabled:opacity-50"
                            >
                                Create Tenant
                            </button>
                        </form>
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-5">
                        <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Edit Tenant</h3>

                        {!selectedTenant && (
                            <p className="mt-3 text-sm text-slate-500">Select a tenant from the table below to edit.</p>
                        )}

                        {selectedTenant && (
                            <form className="mt-3 space-y-3" onSubmit={submitEdit}>
                                <input
                                    value={editForm.data.name}
                                    onChange={(event) => editForm.setData('name', event.target.value)}
                                    className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                                />

                                <div className="grid grid-cols-2 gap-3">
                                    <select
                                        value={editForm.data.status}
                                        onChange={(event) => editForm.setData('status', event.target.value)}
                                        className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                                    >
                                        <option value="trial">trial</option>
                                        <option value="active">active</option>
                                        <option value="suspended">suspended</option>
                                    </select>

                                    <select
                                        value={editForm.data.plan}
                                        onChange={(event) => editForm.setData('plan', event.target.value)}
                                        className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                                    >
                                        {planKeys.map((plan) => (
                                            <option key={plan} value={plan}>
                                                {plan}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <p className="text-xs font-medium text-slate-500">Tenant-level overrides</p>
                                    <div className="mt-2 grid gap-2 md:grid-cols-2">
                                        {allFeatureFlags.map((featureFlag) => (
                                            <label key={featureFlag} className="flex items-center justify-between rounded border border-slate-200 px-3 py-2 text-xs">
                                                <span>{featureFlag}</span>
                                                <input
                                                    type="checkbox"
                                                    checked={Boolean(editForm.data.feature_overrides?.[featureFlag])}
                                                    onChange={(event) => toggleEditOverride(featureFlag, event.target.checked)}
                                                />
                                            </label>
                                        ))}
                                    </div>
                                </div>

                                <div className="flex gap-2">
                                    <button
                                        type="submit"
                                        disabled={editForm.processing}
                                        className="rounded-md bg-sky-600 px-4 py-2 text-sm text-white disabled:opacity-50"
                                    >
                                        Save Changes
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700"
                                        onClick={() => setSelectedTenantId(null)}
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Tenants</h3>
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-slate-500">
                                    <th className="pb-2 pr-4">Name</th>
                                    <th className="pb-2 pr-4">Status</th>
                                    <th className="pb-2 pr-4">Plan</th>
                                    <th className="pb-2 pr-4">Readiness Missing</th>
                                    <th className="pb-2 pr-4">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tenants.data.map((tenant) => (
                                    <tr key={tenant.id} className="border-t border-slate-100 align-top text-slate-700">
                                        <td className="py-2 pr-4">{tenant.name}</td>
                                        <td className="py-2 pr-4">{tenant.status}</td>
                                        <td className="py-2 pr-4">{tenant.subscription_metadata?.plan ?? 'n/a'}</td>
                                        <td className="py-2 pr-4">{(tenant.readiness?.missing ?? []).join(', ') || 'none'}</td>
                                        <td className="py-2 pr-4">
                                            <button
                                                type="button"
                                                onClick={() => openEdit(tenant)}
                                                className="rounded border border-slate-300 px-2 py-1 text-xs"
                                            >
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-5">
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Feature Gate Coverage</h3>
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-slate-500">
                                    <th className="pb-2 pr-4">Feature</th>
                                    <th className="pb-2 pr-4">Configured</th>
                                    <th className="pb-2 pr-4">Middleware Enforced</th>
                                    <th className="pb-2 pr-4">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                {featureCoverage.map((item) => (
                                    <tr key={item.feature_flag} className="border-t border-slate-100 text-slate-700">
                                        <td className="py-2 pr-4">{item.feature_flag}</td>
                                        <td className="py-2 pr-4">{item.config_exists ? 'Yes' : 'No'}</td>
                                        <td className="py-2 pr-4">{item.middleware_enforced ? 'Yes' : 'No'}</td>
                                        <td className="py-2 pr-4">{item.notes ?? ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
