import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Download, Filter, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

const label = (value) => String(value || '')
    .replace(/_/g, ' ')
    .replace(/-/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());

const display = (value) => {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

export default function InventoryReportIndex({
    auth,
    title,
    reportKey,
    filters = {},
    branches = [],
    categories = [],
    rows = [],
    summary = {},
    meta = {},
}) {
    const [form, setForm] = useState({
        branch_id: filters.branch_id || '',
        product_id: filters.product_id || '',
        category_id: filters.category_id || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        search: filters.search || '',
        stock_state: filters.stock_state || '',
        status: filters.status || '',
        type: filters.type || 'all',
        show_reconciled: filters.show_reconciled ? '1' : '0',
    });

    const routeName = `inventory.reports.${reportKey}.index`;
    const exportRouteName = `inventory.reports.${reportKey}.export`;
    const columns = rows.length ? Object.keys(rows[0]).slice(0, 14) : [];
    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    const submit = (event) => {
        event.preventDefault();
        router.get(route(routeName), form, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const exportUrl = () => `${route(exportRouteName)}?${new URLSearchParams(form).toString()}`;

    return (
        <AuthenticatedLayout user={auth.user} header={
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div className="inline-flex items-center gap-2 rounded-full bg-indigo-50 px-3 py-1 text-[11px] font-black uppercase tracking-[0.18em] text-indigo-700">
                        <ShieldCheck size={13} />
                        Inventory Reports
                    </div>
                    <h2 className="mt-2 text-2xl font-black text-slate-900">{title}</h2>
                    <p className="mt-1 text-sm font-medium text-slate-500">
                        {label(meta.report_type)} · {label(meta.consistency_level)} · {label(meta.date_basis)}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Link href={route('inventory.hub.index')} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700">
                        Hub
                    </Link>
                    {meta.can_export ? (
                        <a href={exportUrl()} className="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-black text-white">
                            <Download size={14} />
                            Export CSV
                        </a>
                    ) : null}
                </div>
            </div>
        }>
            <Head title={title} />

            <div className="py-8">
                <div className="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
                    <section className="rounded-lg border border-slate-200 bg-white p-4">
                        <form onSubmit={submit} className="grid gap-3 md:grid-cols-6">
                            <select value={form.branch_id} onChange={(event) => update('branch_id', event.target.value)} className="rounded-md border-slate-200 text-sm">
                                <option value="">Visible branches</option>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </select>
                            <input value={form.product_id} onChange={(event) => update('product_id', event.target.value)} placeholder="Product UUID" className="rounded-md border-slate-200 text-sm" />
                            <select value={form.category_id} onChange={(event) => update('category_id', event.target.value)} className="rounded-md border-slate-200 text-sm">
                                <option value="">All categories</option>
                                {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                            </select>
                            <input type="date" value={form.date_from} onChange={(event) => update('date_from', event.target.value)} className="rounded-md border-slate-200 text-sm" />
                            <input type="date" value={form.date_to} onChange={(event) => update('date_to', event.target.value)} className="rounded-md border-slate-200 text-sm" />
                            <button type="submit" className="inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-black text-white">
                                <Filter size={14} />
                                Apply
                            </button>
                        </form>
                    </section>

                    <section className="grid gap-3 md:grid-cols-4">
                        {Object.entries(summary || {}).map(([key, value]) => (
                            <article key={key} className="rounded-lg border border-slate-200 bg-white p-4">
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">{label(key)}</p>
                                <p className="mt-2 text-xl font-black text-slate-900">{display(value)}</p>
                            </article>
                        ))}
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white">
                        <div className="border-b border-slate-100 px-4 py-3">
                            <p className="text-xs font-black uppercase tracking-widest text-slate-600">Evidence Rows</p>
                            <p className="mt-1 text-xs text-slate-500">
                                Generated {meta.generated_at || '-'} · Watermark {meta.data_as_of_movement_sequence ?? 0}
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-[11px] font-black uppercase tracking-wider text-slate-500">
                                        {columns.map((column) => <th key={column} className="px-4 py-3">{label(column)}</th>)}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {rows.length ? rows.map((row, index) => (
                                        <tr key={index}>
                                            {columns.map((column) => <td key={column} className="max-w-[260px] truncate px-4 py-3 text-slate-700">{display(row[column])}</td>)}
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan={Math.max(columns.length, 1)} className="px-4 py-10 text-center text-sm font-semibold text-slate-500">
                                                No report rows under the active filters.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
