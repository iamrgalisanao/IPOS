import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Form from '@/Pages/Accounting/Mappings/Form';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ filters, mappings, options, defaults, flash }) {
    const [form, setForm] = useState({
        provider: filters.provider || '',
        mapping_type: filters.mapping_type || '',
        status: filters.status || '',
        branch_id: filters.branch_id || '',
    });

    function submitFilters(event) {
        event.preventDefault();
        router.get(route('accounting.mappings.index'), form, { preserveState: true, replace: true });
    }

    function clearFilters() {
        const next = { provider: '', mapping_type: '', status: '', branch_id: '' };
        setForm(next);
        router.get(route('accounting.mappings.index'), next, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Accounting Mappings</h2>}>
            <Head title="Accounting Mappings" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {(flash?.status || flash?.error) && (
                        <div className={`rounded-lg border px-4 py-3 text-sm ${flash.status ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}>
                            {flash.status || flash.error}
                        </div>
                    )}

                    <div className="grid gap-6 lg:grid-cols-[1.1fr,0.9fr]">
                        <div className="space-y-6">
                            <div className="rounded-xl bg-white p-5 shadow-sm">
                                <form className="grid gap-4 md:grid-cols-4" onSubmit={submitFilters}>
                                    <select className="rounded-lg border-gray-300 text-sm" value={form.provider} onChange={(event) => setForm({ ...form, provider: event.target.value })}>
                                        <option value="">All providers</option>
                                        {options.providers.map((provider) => <option key={provider} value={provider}>{provider}</option>)}
                                    </select>
                                    <select className="rounded-lg border-gray-300 text-sm" value={form.mapping_type} onChange={(event) => setForm({ ...form, mapping_type: event.target.value })}>
                                        <option value="">All mapping types</option>
                                        {options.mappingTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                                    </select>
                                    <select className="rounded-lg border-gray-300 text-sm" value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>
                                        <option value="">All statuses</option>
                                        {options.statuses.map((status) => <option key={status} value={status}>{status}</option>)}
                                    </select>
                                    <select className="rounded-lg border-gray-300 text-sm" value={form.branch_id} onChange={(event) => setForm({ ...form, branch_id: event.target.value })}>
                                        <option value="">All scopes</option>
                                        {options.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                    </select>
                                    <div className="md:col-span-4 flex gap-2">
                                        <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="submit">Apply filters</button>
                                        <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700" type="button" onClick={clearFilters}>Reset</button>
                                    </div>
                                </form>
                            </div>

                            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                                        <thead className="bg-slate-50 text-left text-slate-600">
                                            <tr>
                                                <th className="px-4 py-3 font-medium">Type</th>
                                                <th className="px-4 py-3 font-medium">Scope</th>
                                                <th className="px-4 py-3 font-medium">External</th>
                                                <th className="px-4 py-3 font-medium">Status</th>
                                                <th className="px-4 py-3 font-medium"></th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {mappings.data.map((mapping) => (
                                                <tr key={mapping.id}>
                                                    <td className="px-4 py-3">
                                                        <div className="font-medium text-slate-900">{mapping.mapping_type}</div>
                                                        <div className="text-xs text-slate-500">{mapping.provider}</div>
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-700">
                                                        <div>{mapping.branch_name || 'Tenant-wide'}</div>
                                                        <div className="text-xs text-slate-500">{mapping.pos_key || mapping.pos_entity_id || 'No POS reference'}</div>
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-700">
                                                        <div>{mapping.external_id}</div>
                                                        <div className="text-xs text-slate-500">{mapping.external_name || 'No external name'}</div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${mapping.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'}`}>
                                                            {mapping.status}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <Link className="text-sm font-medium text-slate-900 underline" href={route('accounting.mappings.show', mapping.id)}>Inspect</Link>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Create mapping</h3>
                            <Form
                                action={route('accounting.mappings.store')}
                                initialValues={defaults}
                                options={options}
                                submitLabel="Create mapping"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}