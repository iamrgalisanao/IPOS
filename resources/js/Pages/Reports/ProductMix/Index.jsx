import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    Boxes,
    Calendar,
    Download,
    Filter,
    Package,
    Printer,
    RefreshCcw,
    Search,
} from 'lucide-react';

const money = (value) => new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
}).format(Number(value || 0));

const number = (value) => new Intl.NumberFormat('en-PH', {
    maximumFractionDigits: 2,
}).format(Number(value || 0));

const titleCase = (value) => String(value || 'Unspecified')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());

export default function Index({
    filters,
    summary,
    rows = [],
    filter_options: filterOptions = {},
    meta = {},
}) {
    const [form, setForm] = useState({
        start_date: filters.start_date || '',
        end_date: filters.end_date || '',
        branch_id: filters.branch_id || '',
        category_id: filters.category_id || '',
        product_search: filters.product_search || '',
        status: filters.status || 'paid',
    });

    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    const submit = (event) => {
        event.preventDefault();
        router.get(route('reports.product-mix.index'), form, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const reset = () => {
        const empty = {
            start_date: '',
            end_date: '',
            branch_id: '',
            category_id: '',
            product_search: '',
            status: 'paid',
        };

        setForm(empty);
        router.get(route('reports.product-mix.index'), empty, { replace: true });
    };

    const exportUrl = () => `${route('reports.product-mix.export')}?${new URLSearchParams(form).toString()}`;

    const cards = [
        ['Total Quantity', number(summary.total_quantity_sold), 'Quantity from scoped sale item snapshots.'],
        ['Gross Sales', money(summary.total_gross_sales), 'Subtotal before line-level discounts.'],
        ['Net Sales', money(summary.total_net_sales), 'Line totals from stored sale items.'],
        ['Unique Products', number(summary.unique_products_sold), 'Products represented by active filters.'],
        ['Top Seller', summary.top_selling_product || 'None', 'Highest quantity sold.'],
        ['Highest Revenue', summary.highest_revenue_product || 'None', 'Highest net sales.'],
    ];

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <div className="inline-flex items-center gap-2 rounded-full bg-indigo-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-indigo-700">
                            <Boxes size={12} />
                            Sales Reporting
                        </div>
                        <h2 className="text-2xl font-bold leading-tight text-slate-900">Product Mix Report</h2>
                        <p className="text-sm text-slate-500">{meta.semantics}</p>
                    </div>
                    <div className="flex flex-wrap gap-2 print:hidden">
                        <Link
                            href={route('reports.sales-summary.index')}
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50"
                        >
                            <BarChart3 size={16} />
                            Sales Summary
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
            <Head title="Product Mix Report" />

            <div className="py-8 print:py-0">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8 print:max-w-none print:px-0">
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:hidden">
                        <form onSubmit={submit} className="space-y-5">
                            <div className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-700">
                                <Filter size={16} />
                                Product Mix Filters
                            </div>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Start Date</span>
                                    <input type="date" value={form.start_date} onChange={(event) => update('start_date', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm" />
                                </label>
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">End Date</span>
                                    <input type="date" value={form.end_date} onChange={(event) => update('end_date', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm" />
                                </label>
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Branch</span>
                                    <select value={form.branch_id} onChange={(event) => update('branch_id', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                        <option value="">All Visible</option>
                                        {(filterOptions.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                    </select>
                                </label>
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Category</span>
                                    <select value={form.category_id} onChange={(event) => update('category_id', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                        <option value="">All Categories</option>
                                        {(filterOptions.categories || []).map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                                    </select>
                                </label>
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Product</span>
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                                        <input type="text" placeholder="Name, SKU, barcode" value={form.product_search} onChange={(event) => update('product_search', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-10 pr-3 text-sm" />
                                    </div>
                                </label>
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Status</span>
                                    <select value={form.status} onChange={(event) => update('status', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                        <option value="">All Statuses</option>
                                        {(filterOptions.statuses || []).map((status) => <option key={status} value={status}>{titleCase(status)}</option>)}
                                    </select>
                                </label>
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
                        <p className="text-xs uppercase tracking-widest text-slate-500">IPOS Product Mix Report</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-950">Product Mix Report</h1>
                        <p className="mt-1 text-sm text-slate-600">Read-only report generated from existing sale item records.</p>
                    </section>

                    <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {cards.map(([label, value, description]) => (
                            <article key={label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm print:break-inside-avoid print:shadow-none">
                                <p className="text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">{label}</p>
                                <p className="mt-3 truncate text-2xl font-bold text-slate-950" title={String(value)}>{value}</p>
                                <p className="mt-2 text-xs leading-5 text-slate-500">{description}</p>
                            </article>
                        ))}
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:shadow-none">
                        <div className="mb-4">
                            <h3 className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-800">
                                <Package size={16} />
                                Product Performance
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">Grouped by immutable product snapshots from sale items, with current category used where available.</p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[980px] text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-[11px] font-black uppercase tracking-widest text-slate-500">
                                        <th className="py-3 pr-4">Product</th>
                                        <th className="py-3 pr-4">Category</th>
                                        <th className="py-3 text-right">Qty</th>
                                        <th className="py-3 text-right">Gross</th>
                                        <th className="py-3 text-right">Discounts</th>
                                        <th className="py-3 text-right">Net</th>
                                        <th className="py-3 text-right">Void/Refund Qty</th>
                                        <th className="py-3 text-right">Avg Price</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {rows.length > 0 ? rows.map((row) => (
                                        <tr key={row.product_id}>
                                            <td className="py-3 pr-4">
                                                <div className="font-bold text-slate-900">{row.product_name}</div>
                                                <div className="text-xs text-slate-500">{row.sku || 'No SKU'}</div>
                                            </td>
                                            <td className="py-3 pr-4 text-slate-600">{row.category_name}</td>
                                            <td className="py-3 text-right font-semibold text-slate-800">{number(row.quantity_sold)}</td>
                                            <td className="py-3 text-right text-slate-700">{money(row.gross_sales)}</td>
                                            <td className="py-3 text-right text-slate-700">{money(row.discounts)}</td>
                                            <td className="py-3 text-right font-bold text-slate-900">{money(row.net_sales)}</td>
                                            <td className="py-3 text-right text-slate-700">{number(row.refund_void_quantity)}</td>
                                            <td className="py-3 text-right text-slate-700">{money(row.average_selling_price)}</td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan="8" className="py-10 text-center text-sm font-semibold text-slate-500">No product mix rows under active filters.</td>
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
