import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Boxes,
    Download,
    Filter,
    Package,
    Printer,
    RefreshCcw,
    Search,
} from 'lucide-react';

const number = (value) => new Intl.NumberFormat('en-PH', {
    maximumFractionDigits: 2,
}).format(Number(value || 0));

const titleCase = (value) => String(value || 'Unspecified')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());

const badgeClass = (value) => ({
    negative: 'bg-rose-100 text-rose-700',
    low: 'bg-amber-100 text-amber-700',
    normal: 'bg-emerald-100 text-emerald-700',
    expired: 'bg-rose-100 text-rose-700',
    soon: 'bg-amber-100 text-amber-700',
    clear: 'bg-emerald-100 text-emerald-700',
    not_tracked: 'bg-slate-100 text-slate-600',
    unsold: 'bg-rose-100 text-rose-700',
    slow_moving: 'bg-amber-100 text-amber-700',
    active: 'bg-emerald-100 text-emerald-700',
}[value] || 'bg-slate-100 text-slate-600');

export default function Index({
    filters,
    summary,
    rows = [],
    branches = [],
    categories = [],
    meta = {},
}) {
    const [form, setForm] = useState({
        branch_id: filters.branch_id || '',
        category_id: filters.category_id || '',
        search: filters.search || '',
        low_stock_only: filters.low_stock_only || false,
        expiry_risk_only: filters.expiry_risk_only || false,
    });

    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    const submit = (event) => {
        event.preventDefault();
        router.get(route('inventory.reports.visibility.index'), form, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const reset = () => {
        const empty = {
            branch_id: '',
            category_id: '',
            search: '',
            low_stock_only: false,
            expiry_risk_only: false,
        };

        setForm(empty);
        router.get(route('inventory.reports.visibility.index'), empty, { replace: true });
    };

    const exportUrl = () => `${route('inventory.reports.visibility.export')}?${new URLSearchParams({
        ...form,
        low_stock_only: form.low_stock_only ? '1' : '0',
        expiry_risk_only: form.expiry_risk_only ? '1' : '0',
    }).toString()}`;

    const cards = [
        ['Tracked SKUs', number(summary.total_skus_tracked), 'Branch inventory rows under active filters.'],
        ['Below Reorder', number(summary.skus_below_reorder), 'SKUs at or below configured reorder level.'],
        ['Expiry Risk', number(summary.skus_with_expiry_risk), `Expiry within ${meta.expiry_risk_days || 30} days or already expired.`],
        ['Slow / Unsold', number(summary.slow_moving_or_unsold_skus), `No sale within ${meta.slow_moving_days || 30} days, or no sale found.`],
    ];

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <div className="inline-flex items-center gap-2 rounded-full bg-indigo-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-indigo-700">
                            <Boxes size={12} />
                            Inventory Reporting
                        </div>
                        <h2 className="text-2xl font-bold leading-tight text-slate-900">Inventory Visibility Report</h2>
                        <p className="text-sm text-slate-500">{meta.semantics}</p>
                    </div>
                    <div className="flex flex-wrap gap-2 print:hidden">
                        <Link
                            href={route('inventory.dashboard.index')}
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50"
                        >
                            <Package size={16} />
                            Stock Visibility
                        </Link>
                        <button
                            type="button"
                            onClick={() => window.print()}
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50"
                        >
                            <Printer size={16} />
                            Print
                        </button>
                        {meta.can_export && (
                            <a
                                href={exportUrl()}
                                className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow-sm shadow-emerald-200/60 transition hover:bg-emerald-700"
                            >
                                <Download size={16} />
                                Export CSV
                            </a>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Inventory Visibility Report" />

            <div className="py-8 print:py-0">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8 print:max-w-none print:px-0">
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:hidden">
                        <form onSubmit={submit} className="space-y-5">
                            <div className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-700">
                                <Filter size={16} />
                                Inventory Filters
                            </div>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Branch</span>
                                    <select value={form.branch_id} onChange={(event) => update('branch_id', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                        <option value="">All Visible</option>
                                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                    </select>
                                </label>
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Category</span>
                                    <select value={form.category_id} onChange={(event) => update('category_id', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                        <option value="">All Categories</option>
                                        {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                                    </select>
                                </label>
                                <label className="space-y-1.5 xl:col-span-2">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Product</span>
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                                        <input type="text" placeholder="Name, SKU, barcode" value={form.search} onChange={(event) => update('search', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-10 pr-3 text-sm" />
                                    </div>
                                </label>
                                <div className="flex flex-col justify-end gap-2">
                                    <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                                        <input type="checkbox" checked={form.low_stock_only} onChange={(event) => update('low_stock_only', event.target.checked)} className="rounded border-slate-300 text-indigo-600" />
                                        Low stock only
                                    </label>
                                    <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                                        <input type="checkbox" checked={form.expiry_risk_only} onChange={(event) => update('expiry_risk_only', event.target.checked)} className="rounded border-slate-300 text-indigo-600" />
                                        Expiry risk only
                                    </label>
                                </div>
                            </div>
                            <div className="flex flex-wrap justify-end gap-3">
                                <button type="button" onClick={reset} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600">
                                    <RefreshCcw size={15} />
                                    Reset
                                </button>
                                <button type="submit" className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-2 text-sm font-bold text-white">
                                    <Filter size={15} />
                                    Apply
                                </button>
                            </div>
                        </form>
                    </section>

                    <section className="hidden print:block">
                        <p className="text-xs uppercase tracking-widest text-slate-500">IPOS Inventory Visibility Report</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-950">Inventory Visibility Report</h1>
                        <p className="mt-1 text-sm text-slate-600">Read-only report generated from existing inventory records.</p>
                    </section>

                    <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {cards.map(([label, value, description]) => (
                            <article key={label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm print:break-inside-avoid print:shadow-none">
                                <p className="text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">{label}</p>
                                <p className="mt-3 text-2xl font-bold text-slate-950">{value}</p>
                                <p className="mt-2 text-xs leading-5 text-slate-500">{description}</p>
                            </article>
                        ))}
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:shadow-none">
                        <div className="mb-4">
                            <h3 className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-800">
                                <AlertTriangle size={16} />
                                Stock Visibility
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">Inventory state, expiry risk, and movement indicators from existing records only.</p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1200px] text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-[11px] font-black uppercase tracking-widest text-slate-500">
                                        <th className="py-3 pr-4">Product</th>
                                        <th className="py-3 pr-4">Branch</th>
                                        <th className="py-3 pr-4">Category</th>
                                        <th className="py-3 text-right">Current</th>
                                        <th className="py-3 text-right">Reorder</th>
                                        <th className="py-3 pr-4">Stock</th>
                                        <th className="py-3 pr-4">Expiry</th>
                                        <th className="py-3 pr-4">Last Movement</th>
                                        <th className="py-3 pr-4">Last Sale</th>
                                        <th className="py-3 pr-4">Movement</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {rows.length > 0 ? rows.map((row) => (
                                        <tr key={`${row.branch_id}:${row.product_id}`}>
                                            <td className="py-3 pr-4">
                                                <div className="font-bold text-slate-900">{row.product_name}</div>
                                                <div className="text-xs text-slate-500">{row.sku || 'No SKU'} {row.barcode ? `| ${row.barcode}` : ''}</div>
                                            </td>
                                            <td className="py-3 pr-4 text-slate-600">{row.branch_name}</td>
                                            <td className="py-3 pr-4 text-slate-600">{row.category_name}</td>
                                            <td className="py-3 text-right font-semibold text-slate-800">{number(row.current_stock)} {row.unit_of_measure || ''}</td>
                                            <td className="py-3 text-right text-slate-700">{number(row.reorder_level)}</td>
                                            <td className="py-3 pr-4"><StatusBadge value={row.stock_state} /></td>
                                            <td className="py-3 pr-4">
                                                <StatusBadge value={row.expiry_status} />
                                                {row.next_expiry_date && <div className="mt-1 text-xs text-slate-500">{row.next_expiry_date}</div>}
                                            </td>
                                            <td className="py-3 pr-4 text-slate-600">{row.last_movement_at || 'None'}</td>
                                            <td className="py-3 pr-4 text-slate-600">{row.last_sale_at || 'None'}</td>
                                            <td className="py-3 pr-4"><StatusBadge value={row.movement_status} /></td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan="10" className="py-10 text-center text-sm font-semibold text-slate-500">No inventory visibility rows under active filters.</td>
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

function StatusBadge({ value }) {
    return (
        <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-black uppercase tracking-wider ${badgeClass(value)}`}>
            {titleCase(value)}
        </span>
    );
}
