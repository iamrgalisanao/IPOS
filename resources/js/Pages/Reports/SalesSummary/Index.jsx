import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    Calendar,
    CreditCard,
    Download,
    Filter,
    Printer,
    Receipt,
    RefreshCcw,
    User,
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
    auth,
    filters,
    kpis,
    payment_breakdown: paymentBreakdown = [],
    status_breakdown: statusBreakdown = [],
    recent_transactions: recentTransactions = [],
    filter_options: filterOptions = {},
    meta = {},
}) {
    const [form, setForm] = useState({
        start_date: filters.start_date || '',
        end_date: filters.end_date || '',
        branch_id: filters.branch_id || '',
        status: filters.status || '',
        payment_method_id: filters.payment_method_id || '',
        cashier_id: filters.cashier_id || '',
    });

    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    const submit = (event) => {
        event.preventDefault();
        router.get(route('reports.sales-summary.index'), form, {
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
            status: '',
            payment_method_id: '',
            cashier_id: '',
        };

        setForm(empty);
        router.get(route('reports.sales-summary.index'), {}, { replace: true });
    };

    const exportUrl = () => `${route('reports.sales-summary.export')}?${new URLSearchParams(form).toString()}`;

    const cards = [
        ['Gross Sales', money(kpis.gross_sales), 'Stored gross amount, with subtotal fallback.'],
        ['Net Sales', money(kpis.net_sales), 'Sum of existing sale totals under active filters.'],
        ['Transactions', number(kpis.transaction_count), 'All visible transactions in this report slice.'],
        ['Paid', number(kpis.paid_count), 'Transactions currently marked paid.'],
        ['Pending / Created', number(kpis.pending_count), 'Open or not-yet-final transactions.'],
        ['Voids / Refunds', number(kpis.void_refund_count), 'Exception transactions and reversals.'],
        ['Average Transaction', money(kpis.average_transaction_value), 'Net sales divided by transaction count.'],
        ['Discounts', money(kpis.discount_total), 'Stored discount totals only.'],
    ];

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <div className="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-blue-700">
                            <BarChart3 size={12} />
                            Sales Reporting
                        </div>
                        <h2 className="text-2xl font-bold leading-tight text-slate-900">Sales Summary Report</h2>
                        <p className="text-sm text-slate-500">{meta.semantics}</p>
                    </div>
                    <div className="flex flex-wrap gap-2 print:hidden">
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
            <Head title="Sales Summary Report" />

            <div className="py-8 print:py-0">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8 print:max-w-none print:px-0">
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:hidden">
                        <form onSubmit={submit} className="space-y-5">
                            <div className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-700">
                                <Filter size={16} />
                                Report Filters
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
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Status</span>
                                    <select value={form.status} onChange={(event) => update('status', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                        <option value="">All Statuses</option>
                                        {(filterOptions.statuses || []).map((status) => <option key={status} value={status}>{titleCase(status)}</option>)}
                                    </select>
                                </label>
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Payment</span>
                                    <select value={form.payment_method_id} onChange={(event) => update('payment_method_id', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                        <option value="">All Payments</option>
                                        {(filterOptions.payment_methods || []).map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                                    </select>
                                </label>
                                <label className="space-y-1.5">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Cashier</span>
                                    <select value={form.cashier_id} onChange={(event) => update('cashier_id', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                        <option value="">All Cashiers</option>
                                        {(filterOptions.cashiers || []).map((cashier) => <option key={cashier.id} value={cashier.id}>{cashier.name}</option>)}
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
                        <p className="text-xs uppercase tracking-widest text-slate-500">IPOS Sales Summary Report</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-950">Sales Summary Report</h1>
                        <p className="mt-1 text-sm text-slate-600">Read-only report generated from existing sales records.</p>
                    </section>

                    <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {cards.map(([label, value, description]) => (
                            <article key={label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm print:break-inside-avoid print:shadow-none">
                                <p className="text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">{label}</p>
                                <p className="mt-3 text-2xl font-bold text-slate-950">{value}</p>
                                <p className="mt-2 text-xs leading-5 text-slate-500">{description}</p>
                            </article>
                        ))}
                    </section>

                    <section className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <ReportTable
                            title="Payment Breakdown"
                            icon={CreditCard}
                            columns={['Payment Method', 'Count', 'Amount']}
                            empty="No payment rows under active filters."
                            rows={paymentBreakdown.map((row) => [row.payment_method_name, number(row.payment_count), money(row.total_amount)])}
                        />
                        <ReportTable
                            title="Status Breakdown"
                            icon={Receipt}
                            columns={['Status', 'Count', 'Amount']}
                            empty="No status rows under active filters."
                            rows={statusBreakdown.map((row) => [titleCase(row.status), number(row.transaction_count), money(row.total_amount)])}
                        />
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:shadow-none">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h3 className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-800">
                                    <Calendar size={16} />
                                    Recent Transactions
                                </h3>
                                <p className="mt-1 text-sm text-slate-500">Latest visible transactions for context. Full audit detail remains in Sales History.</p>
                            </div>
                            <Link href={route('sales.history.index')} className="text-xs font-bold uppercase tracking-widest text-blue-700 print:hidden">
                                Audit Log
                            </Link>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-100 text-[11px] font-black uppercase tracking-widest text-slate-500">
                                        <th className="py-3 pr-4">Sale</th>
                                        <th className="py-3 pr-4">Status</th>
                                        <th className="py-3 pr-4">Branch</th>
                                        <th className="py-3 pr-4">Cashier</th>
                                        <th className="py-3 pr-4">Timestamp</th>
                                        <th className="py-3 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {recentTransactions.length > 0 ? recentTransactions.map((sale) => (
                                        <tr key={sale.id}>
                                            <td className="py-3 pr-4 font-bold text-slate-900">{sale.sale_number}</td>
                                            <td className="py-3 pr-4 text-slate-600">{titleCase(sale.status)}</td>
                                            <td className="py-3 pr-4 text-slate-600">{sale.branch_name || 'Unassigned'}</td>
                                            <td className="py-3 pr-4 text-slate-600">{sale.cashier_name || 'Unassigned'}</td>
                                            <td className="py-3 pr-4 text-slate-600">{sale.timestamp || 'N/A'}</td>
                                            <td className="py-3 text-right font-bold text-slate-900">{money(sale.total)}</td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan="6" className="py-8 text-center text-sm font-semibold text-slate-500">No transactions under active filters.</td>
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

function ReportTable({ title, icon: Icon, columns, rows, empty }) {
    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:break-inside-avoid print:shadow-none">
            <h3 className="mb-4 flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-800">
                <Icon size={16} />
                {title}
            </h3>
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-slate-100 text-[11px] font-black uppercase tracking-widest text-slate-500">
                            {columns.map((column, index) => (
                                <th key={column} className={`py-3 ${index === columns.length - 1 ? 'text-right' : 'pr-4'}`}>{column}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {rows.length > 0 ? rows.map((row, rowIndex) => (
                            <tr key={rowIndex}>
                                {row.map((cell, cellIndex) => (
                                    <td key={`${rowIndex}-${cellIndex}`} className={`py-3 ${cellIndex === row.length - 1 ? 'text-right font-bold text-slate-900' : 'pr-4 text-slate-600'}`}>{cell}</td>
                                ))}
                            </tr>
                        )) : (
                            <tr>
                                <td colSpan={columns.length} className="py-8 text-center text-sm font-semibold text-slate-500">{empty}</td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
