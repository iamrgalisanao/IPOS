import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Form from '@/Pages/Accounting/Mappings/Form';
import { Head, Link, router } from '@inertiajs/react';

export default function Show({ mapping, options, defaults, canEdit, flash }) {
    function updateStatus(status) {
        router.patch(route('accounting.mappings.status', mapping.id), { status });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Accounting Mapping Detail</h2>}>
            <Head title="Accounting Mapping Detail" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {(flash?.status || flash?.error) && (
                        <div className={`rounded-lg border px-4 py-3 text-sm ${flash.status ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}>
                            {flash.status || flash.error}
                        </div>
                    )}

                    <div className="flex items-center justify-between">
                        <Link className="text-sm font-medium text-slate-700 underline" href={route('accounting.mappings.index')}>Back to mappings</Link>
                        <div className="flex gap-2">
                            <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 disabled:cursor-not-allowed disabled:opacity-50" disabled={!canEdit || mapping.status === 'active'} type="button" onClick={() => updateStatus('active')}>Activate</button>
                            <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 disabled:cursor-not-allowed disabled:opacity-50" disabled={!canEdit || mapping.status === 'inactive'} type="button" onClick={() => updateStatus('inactive')}>Deactivate</button>
                        </div>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-[0.95fr,1.05fr]">
                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Inspect mapping</h3>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <div><dt className="text-xs uppercase text-slate-500">Provider</dt><dd className="mt-1 text-sm text-slate-900">{mapping.provider}</dd></div>
                                <div><dt className="text-xs uppercase text-slate-500">Type</dt><dd className="mt-1 text-sm text-slate-900">{mapping.mapping_type}</dd></div>
                                <div><dt className="text-xs uppercase text-slate-500">Scope</dt><dd className="mt-1 text-sm text-slate-900">{mapping.branch_name || 'Tenant-wide'}</dd></div>
                                <div><dt className="text-xs uppercase text-slate-500">Status</dt><dd className="mt-1 text-sm text-slate-900">{mapping.status}</dd></div>
                                <div><dt className="text-xs uppercase text-slate-500">POS key</dt><dd className="mt-1 text-sm text-slate-900">{mapping.pos_key || 'N/A'}</dd></div>
                                <div><dt className="text-xs uppercase text-slate-500">POS entity</dt><dd className="mt-1 text-sm text-slate-900">{mapping.pos_entity_id || 'N/A'}</dd></div>
                                <div><dt className="text-xs uppercase text-slate-500">External ID</dt><dd className="mt-1 text-sm text-slate-900">{mapping.external_id}</dd></div>
                                <div><dt className="text-xs uppercase text-slate-500">External name</dt><dd className="mt-1 text-sm text-slate-900">{mapping.external_name || 'N/A'}</dd></div>
                            </dl>

                            <div className="mt-6">
                                <h4 className="mb-2 text-xs uppercase text-slate-500">Sanitized metadata</h4>
                                <pre className="overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100">{JSON.stringify(mapping.metadata, null, 2)}</pre>
                            </div>
                        </div>

                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Edit mapping</h3>
                            <Form
                                action={route('accounting.mappings.update', mapping.id)}
                                initialValues={defaults}
                                method="put"
                                options={options}
                                submitLabel="Save changes"
                                disabled={!canEdit}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}