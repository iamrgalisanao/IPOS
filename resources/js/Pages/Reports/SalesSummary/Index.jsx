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
    promotion_breakdown: promotionBreakdown = [],
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
        {
            label: 'Net Sales',
            value: money(kpis.net_sales),
            description: `${number(kpis.transaction_count)} visible transactions under active filters.`,
            emphasis: 'primary',
        },
        {
            label: 'Transactions',
            value: number(kpis.transaction_count),
            description: 'All visible transactions in this report slice.',
            emphasis: 'secondary',
        },
        {
            label: 'Average Transaction',
            value: money(kpis.average_transaction_value),
            description: 'Net sales divided by visible transaction count.',
            emphasis: 'secondary',
        },
        {
            label: 'Paid',
            value: number(kpis.paid_count),
            description: `${percentage(kpis.paid_count, kpis.transaction_count)} of visible transactions.`,
            tone: 'success',
        },
        {
            label: 'Gross Sales',
            value: money(kpis.gross_sales),
            description: 'Stored gross amount, with subtotal fallback.',
        },
        {
            label: 'Pending / Created',
            value: number(kpis.pending_count),
            description: Number(kpis.pending_count || 0) > 0 ? 'Open or not-yet-final transactions need review.' : 'No open visible transactions.',
            tone: Number(kpis.pending_count || 0) > 0 ? 'warning' : 'neutral',
        },
        {
            label: 'Voids / Refunds',
            value: number(kpis.void_refund_count),
            description: Number(kpis.void_refund_count || 0) > 0 ? 'Exception transactions and reversals are present.' : 'No visible exception transactions.',
            tone: Number(kpis.void_refund_count || 0) > 0 ? 'danger' : 'neutral',
        },
        {
            label: 'Discounts',
            value: money(kpis.discount_total),
            description: 'Stored discount totals only.',
        },
        {
            label: 'Statutory Discounts',
            value: money(kpis.statutory_discount_total),
            description: 'Government-mandated discount portion.',
        },
        {
            label: 'Commercial Promotions',
            value: money(kpis.commercial_discount_total),
            description: 'Applied promotion snapshot totals.',
        },
    ];
    const selectedBranch = (filterOptions.branches || []).find((branch) => branch.id === form.branch_id)?.name || 'All Visible';
    const selectedPayment = (filterOptions.payment_methods || []).find((method) => method.id === form.payment_method_id)?.name || 'All Payments';
    const selectedCashier = (filterOptions.cashiers || []).find((cashier) => cashier.id === form.cashier_id)?.name || 'All Cashiers';
    const activeFilters = [
        ['Date Range', dateRangeLabel(form.start_date, form.end_date)],
        ['Branch', selectedBranch],
        ['Status', form.status ? titleCase(form.status) : 'All Statuses'],
        ['Payment', selectedPayment],
        ['Cashier', selectedCashier],
    ];
    const insightText = buildInsightText({ kpis, paymentBreakdown, statusBreakdown });

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
                            <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                                <div className="flex flex-wrap gap-2">
                                    {activeFilters.map(([label, value]) => (
                                        <span key={label} className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                                            <span className="text-slate-400">{label}:</span> {value}
                                        </span>
                                    ))}
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
                            </div>
                        </form>
                    </section>

                    <section className="hidden print:block">
                        <p className="text-xs uppercase tracking-widest text-slate-500">IPOS Sales Summary Report</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-950">Sales Summary Report</h1>
                        <p className="mt-1 text-sm text-slate-600">Read-only report generated from existing sales records.</p>
                    </section>

                    <section>
                        <div className="mb-3">
                            <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-500">Sales Overview</p>
                            <p className="mt-1 text-sm text-slate-500">Read-only totals from existing sales records under the active filters.</p>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {cards.map(({ label, value, description, emphasis, tone }) => (
                                <article
                                    key={label}
                                    className={[
                                        'rounded-2xl border bg-white p-5 shadow-sm print:break-inside-avoid print:shadow-none',
                                        emphasis === 'primary' ? 'border-blue-200 bg-blue-50/60 lg:col-span-2' : 'border-slate-200',
                                        emphasis === 'secondary' ? 'border-slate-300' : '',
                                        tone === 'warning' ? 'border-amber-200 bg-amber-50/50' : '',
                                        tone === 'danger' ? 'border-rose-200 bg-rose-50/50' : '',
                                        tone === 'success' ? 'border-emerald-200 bg-emerald-50/40' : '',
                                    ].filter(Boolean).join(' ')}
                                >
                                    <p className="text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">{label}</p>
                                    <p className={['mt-3 font-bold text-slate-950', emphasis === 'primary' ? 'text-3xl' : 'text-2xl'].join(' ')}>{value}</p>
                                    <p className="mt-2 text-xs leading-5 text-slate-500">{description}</p>
                                </article>
                            ))}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm print:break-inside-avoid print:shadow-none">
                        <div className="flex items-start gap-3">
                            <div className="rounded-xl bg-blue-50 p-2 text-blue-700">
                                <BarChart3 size={18} />
                            </div>
                            <div>
                                <p className="text-sm font-black uppercase tracking-[0.18em] text-slate-800">Summary Insight</p>
                                <p className="mt-2 text-sm leading-6 text-slate-600">{insightText}</p>
                                {Number(kpis.transaction_count || 0) === 0 && (
                                    <p className="mt-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500">
                                        No sales found for the selected filters. Try adjusting the date range, branch, payment method, or cashier.
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>

                    <section>
                        <div className="mb-3">
                            <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-500">Breakdown Analysis</p>
                            <p className="mt-1 text-sm text-slate-500">Relative share of visible transaction count and amount.</p>
                        </div>
                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <ReportTable
                            title="Payment Breakdown"
                            icon={CreditCard}
                            columns={['Payment Method', 'Count', 'Amount']}
                            empty="No payment rows under active filters."
                            rows={paymentBreakdown.map((row) => ({
                                label: row.payment_method_name,
                                count: Number(row.payment_count || 0),
                                amount: Number(row.total_amount || 0),
                            }))}
                        />
                        <ReportTable
                            title="Status Breakdown"
                            icon={Receipt}
                            columns={['Status', 'Count', 'Amount']}
                            empty="No status rows under active filters."
                            rows={statusBreakdown.map((row) => ({
                                label: titleCase(row.status),
                                count: Number(row.transaction_count || 0),
                                amount: Number(row.total_amount || 0),
                            }))}
                        />
                        <ReportTable
                            title="Promotion Breakdown"
                            icon={BarChart3}
                            columns={['Promotion', 'Sales', 'Discount']}
                            empty="No commercial promotions under active filters."
                            rows={promotionBreakdown.map((row) => ({
                                label: row.promotion_name,
                                count: Number(row.transaction_count || 0),
                                amount: Number(row.discount_total || 0),
                            }))}
                        />
                        </div>
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:shadow-none">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h3 className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-800">
                                    <Calendar size={16} />
                                    Recent Transactions
                                </h3>
                                <p className="mt-1 text-sm text-slate-500">Latest visible transactions for context. Full audit detail remains in the Transaction Audit Log.</p>
                            </div>
                            <Link href={route('sales.history.index')} className="text-xs font-bold uppercase tracking-widest text-blue-700 print:hidden">
                                View Full Audit Log
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
                                        <tr key={sale.id} className="transition hover:bg-slate-50">
                                            <td className="py-3 pr-4 font-bold text-slate-900">{sale.sale_number}</td>
                                            <td className="py-3 pr-4"><StatusBadge status={sale.status} /></td>
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

function percentage(value, total) {
    const denominator = Number(total || 0);

    if (denominator <= 0) {
        return '0%';
    }

    return `${number((Number(value || 0) / denominator) * 100)}%`;
}

function dateRangeLabel(start, end) {
    if (start && end) {
        return `${start} to ${end}`;
    }

    if (start) {
        return `From ${start}`;
    }

    if (end) {
        return `Until ${end}`;
    }

    return 'All Dates';
}

function buildInsightText({ kpis, paymentBreakdown, statusBreakdown }) {
    const transactionCount = Number(kpis.transaction_count || 0);

    if (transactionCount === 0) {
        return 'No visible transactions were found under the selected filters.';
    }

    const topPayment = [...paymentBreakdown].sort((a, b) => Number(b.total_amount || 0) - Number(a.total_amount || 0))[0];
    const paidCount = Number(kpis.paid_count || 0);
    const pendingCount = Number(kpis.pending_count || 0);
    const exceptionCount = Number(kpis.void_refund_count || 0);
    const statusNote = pendingCount > 0
        ? `${number(pendingCount)} remain pending or created.`
        : 'No pending or created transactions are visible.';
    const exceptionNote = exceptionCount > 0
        ? `${number(exceptionCount)} void/refund records are visible.`
        : 'No void/refund records are visible.';
    const paymentNote = topPayment
        ? `${topPayment.payment_method_name || 'Unspecified payment'} is the largest visible payment method at ${money(topPayment.total_amount)}.`
        : 'No payment breakdown is available for the current filters.';

    return `${number(transactionCount)} visible transactions were found with ${money(kpis.net_sales)} in net sales. ${number(paidCount)} are marked paid. ${statusNote} ${exceptionNote} ${paymentNote}`;
}

function statusClasses(status) {
    const normalized = String(status || '').toLowerCase();

    if (normalized === 'paid' || normalized === 'completed') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    if (['voided', 'refunded', 'cancelled'].includes(normalized)) {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }

    if (['created', 'pending', 'draft'].includes(normalized)) {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    return 'border-slate-200 bg-slate-50 text-slate-600';
}

function StatusBadge({ status }) {
    return (
        <span className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-black uppercase tracking-wider ${statusClasses(status)}`}>
            {titleCase(status)}
        </span>
    );
}

function ReportTable({ title, icon: Icon, columns, rows, empty }) {
    const totalCount = rows.reduce((sum, row) => sum + Number(row.count || 0), 0);
    const totalAmount = rows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const maxAmount = Math.max(...rows.map((row) => Number(row.amount || 0)), 0);

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
                                <td className="py-3 pr-4 text-slate-700">
                                    <div className="space-y-1.5">
                                        <span className="font-bold text-slate-800">{row.label || 'Unspecified'}</span>
                                        <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                className="h-full rounded-full bg-blue-500"
                                                style={{ width: `${maxAmount > 0 ? Math.max((Number(row.amount || 0) / maxAmount) * 100, 3) : 0}%` }}
                                            />
                                        </div>
                                    </div>
                                </td>
                                <td className="py-3 pr-4 text-center font-semibold text-slate-700">
                                    {number(row.count)}
                                    <span className="ml-1 text-xs font-medium text-slate-400">({percentage(row.count, totalCount)})</span>
                                </td>
                                <td className="py-3 text-right font-bold text-slate-900">
                                    {money(row.amount)}
                                    <span className="ml-1 text-xs font-medium text-slate-400">({percentage(row.amount, totalAmount)})</span>
                                </td>
                            </tr>
                        )) : (
                            <tr>
                                <td colSpan={columns.length} className="py-8 text-center text-sm font-semibold text-slate-500">{empty}</td>
                            </tr>
                        )}
                    </tbody>
                    {rows.length > 0 && (
                        <tfoot>
                            <tr className="border-t border-slate-100 text-sm font-bold text-slate-900">
                                <td className="py-3 pr-4">Total</td>
                                <td className="py-3 pr-4 text-center">{number(totalCount)}</td>
                                <td className="py-3 text-right">{money(totalAmount)}</td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </section>
    );
}
