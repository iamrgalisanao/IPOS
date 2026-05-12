import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ filters, records, branches, eventTypes, syncStatuses, sourceTypes, flash }) {
    const [form, setForm] = useState({
        event_type: filters.event_type || '',
        sync_status: filters.sync_status || '',
        source_type: filters.source_type || '',
        branch_id: filters.branch_id || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    });

    function submit(event) {
        event.preventDefault();
        router.get(route('accounting.outbox.index'), form, { preserveState: true, replace: true });
    }

    function reset() {
        const next = {
            event_type: '',
            sync_status: '',
            source_type: '',
            branch_id: '',
            date_from: '',
            date_to: '',
        };

        setForm(next);
        router.get(route('accounting.outbox.index'), next, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Accounting Sync Dashboard</h2>}
        >
            <Head title="Accounting Sync Dashboard" />

            <div className="py-10">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    {(flash?.status || flash?.error) && (
                        <div className={`rounded-lg border px-4 py-3 text-sm ${flash.status ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}>
                            {flash.status || flash.error}
                        </div>
                    )}

                    <div className="rounded-xl bg-white p-5 shadow-sm">
                        <form className="grid gap-4 md:grid-cols-3 xl:grid-cols-6" onSubmit={submit}>
                            <select className="rounded-lg border-gray-300 text-sm" value={form.event_type} onChange={(event) => setForm({ ...form, event_type: event.target.value })}>
                                <option value="">All events</option>
                                {eventTypes.map((value) => <option key={value} value={value}>{value}</option>)}
                            </select>
                            <select className="rounded-lg border-gray-300 text-sm" value={form.sync_status} onChange={(event) => setForm({ ...form, sync_status: event.target.value })}>
                                <option value="">All statuses</option>
                                {syncStatuses.map((value) => <option key={value} value={value}>{value}</option>)}
                            </select>
                            <select className="rounded-lg border-gray-300 text-sm" value={form.source_type} onChange={(event) => setForm({ ...form, source_type: event.target.value })}>
                                <option value="">All sources</option>
                                {sourceTypes.map((value) => <option key={value} value={value}>{value}</option>)}
                            </select>
                            <select className="rounded-lg border-gray-300 text-sm" value={form.branch_id} onChange={(event) => setForm({ ...form, branch_id: event.target.value })}>
                                <option value="">All branches</option>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </select>
                            <input className="rounded-lg border-gray-300 text-sm" type="date" value={form.date_from} onChange={(event) => setForm({ ...form, date_from: event.target.value })} />
                            <input className="rounded-lg border-gray-300 text-sm" type="date" value={form.date_to} onChange={(event) => setForm({ ...form, date_to: event.target.value })} />
                            <div className="flex gap-2 xl:col-span-6">
                                <button className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" type="submit">Apply filters</button>
                                <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700" type="button" onClick={reset}>Reset</button>
                            </div>
                        </form>
                    </div>

                    <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-left text-slate-600">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Event</th>
                                        <th className="px-4 py-3 font-medium">Status</th>
                                        <th className="px-4 py-3 font-medium">Source</th>
                                        <th className="px-4 py-3 font-medium">Attempts</th>
                                        <th className="px-4 py-3 font-medium">Last Error</th>
                                        <th className="px-4 py-3 font-medium">Created</th>
                                        <th className="px-4 py-3 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {records.data.map((record) => (
                                        <tr key={record.id} className="align-top">
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-slate-900">{record.event_type}</div>
                                                <div className="text-xs text-slate-500">{record.id}</div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${record.sync_status === 'synced' ? 'bg-emerald-100 text-emerald-800' : record.sync_status === 'failed' ? 'bg-rose-100 text-rose-800' : record.sync_status === 'processing' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'}`}>
                                                    {record.sync_status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-slate-700">
                                                <div>{record.source_type}</div>
                                                <div className="text-xs text-slate-500">{record.source_id}</div>
                                            </td>
                                            <td className="px-4 py-3 text-slate-700">{record.attempt_count}</td>
                                            <td className="max-w-xs px-4 py-3 text-xs text-slate-600">{record.sync_error || 'None'}</td>
                                            <td className="px-4 py-3 text-slate-600">{record.created_at ? new Date(record.created_at).toLocaleString() : 'N/A'}</td>
                                            <td className="px-4 py-3 text-right">
                                                <Link className="text-sm font-medium text-slate-900 underline" href={route('accounting.outbox.show', record.id)}>
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}