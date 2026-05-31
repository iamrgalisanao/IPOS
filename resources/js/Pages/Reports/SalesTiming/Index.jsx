import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    CalendarClock,
    Clock,
    Download,
    Filter,
    Printer,
    RefreshCcw,
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
    hourly_rows: hourlyRows = [],
    weekday_rows: weekdayRows = [],
    filter_options: filterOptions = {},
    meta = {},
}) {
    const hasAnySales = [...hourlyRows, ...weekdayRows].some((row) => Number(row.transaction_count) > 0);
    const [hideZero, setHideZero] = useState(hasAnySales);
    const filteredHourlyRows = hideZero ? hourlyRows.filter((r) => Number(r.transaction_count) > 0) : hourlyRows;
    const filteredWeekdayRows = hideZero ? weekdayRows.filter((r) => Number(r.transaction_count) > 0) : weekdayRows;
    const activeHourlyCount = hourlyRows.filter((row) => Number(row.transaction_count) > 0).length;
    const activeWeekdayCount = weekdayRows.filter((row) => Number(row.transaction_count) > 0).length;
    const isLowData = hasAnySales && (activeHourlyCount <= 1 || activeWeekdayCount <= 1);
    const [form, setForm] = useState({
        start_date: filters.start_date || '',
        end_date: filters.end_date || '',
        branch_id: filters.branch_id || '',
        status: filters.status || 'paid',
        cashier_id: filters.cashier_id || '',
    });

    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    const submit = (event) => {
        event.preventDefault();
        router.get(route('reports.sales-timing.index'), form, {
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
            status: 'paid',
            cashier_id: '',
        };

        setForm(empty);
        router.get(route('reports.sales-timing.index'), empty, { replace: true });
    };

    const exportUrl = () => `${route('reports.sales-timing.export')}?${new URLSearchParams(form).toString()}`;

    const cards = [
        {
            label: 'Net Sales',
            value: money(summary.total_net_sales),
            description: 'Based on stored paid transaction totals under active filters.',
            emphasis: 'primary',
        },
        {
            label: 'Transactions',
            value: number(summary.total_transactions),
            description: 'Scoped transaction count from existing records.',
            emphasis: 'secondary',
        },
        {
            label: 'Peak Hour',
            value: summary.peak_sales_hour || 'None',
            description: 'Highest net sales hour under active filters.',
        },
        {
            label: 'Peak Weekday',
            value: summary.peak_sales_weekday || 'None',
            description: 'Highest net sales weekday under active filters.',
        },
        {
            label: 'Lowest Active Hour',
            value: summary.lowest_sales_hour || 'None',
            description: 'Lowest net sales hour with at least one transaction.',
        },
    ];
    const peakHourRow = hourlyRows.find((row) => row.hour_label === summary.peak_sales_hour);
    const peakWeekdayRow = weekdayRows.find((row) => row.weekday_label === summary.peak_sales_weekday);
    const insightText = buildInsightText({
        hasAnySales,
        isLowData,
        peakHourRow,
        peakWeekdayRow,
        summary,
    });

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <div className="inline-flex items-center gap-2 rounded-full bg-indigo-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-indigo-700">
                            <CalendarClock size={12} />
                            Sales Reporting
                        </div>
                        <h2 className="text-2xl font-bold leading-tight text-slate-900">Sales by Hour / Weekday</h2>
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
            <Head title="Sales by Hour / Weekday" />

            <div className="py-8 print:py-0">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8 print:max-w-none print:px-0">
                    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:hidden">
                        <form onSubmit={submit} className="space-y-5">
                            <div className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-700">
                                <Filter size={16} />
                                Timing Filters
                            </div>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
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
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Cashier</span>
                                    <select value={form.cashier_id} onChange={(event) => update('cashier_id', event.target.value)} className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                        <option value="">All Cashiers</option>
                                        {(filterOptions.cashiers || []).map((cashier) => <option key={cashier.id} value={cashier.id}>{cashier.name}</option>)}
                                    </select>
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
                        <p className="text-xs uppercase tracking-widest text-slate-500">IPOS Sales Timing Report</p>
                        <h1 className="mt-1 text-2xl font-bold text-slate-950">Sales by Hour / Weekday</h1>
                        <p className="mt-1 text-sm text-slate-600">Read-only timing report generated from existing sales records.</p>
                    </section>

                    <section>
                        <div className="mb-3">
                            <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-500">Sales Timing Summary</p>
                            <p className="mt-1 text-sm text-slate-500">Based on paid transactions only unless a different status filter is selected.</p>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            {cards.map(({ label, value, description, emphasis }) => (
                                <article
                                    key={label}
                                    className={[
                                        'rounded-2xl border bg-white p-5 shadow-sm print:break-inside-avoid print:shadow-none',
                                        emphasis === 'primary' ? 'border-indigo-200 bg-indigo-50/60 lg:col-span-2' : 'border-slate-200',
                                        emphasis === 'secondary' ? 'border-slate-300' : '',
                                    ].filter(Boolean).join(' ')}
                                >
                                    <p className="text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">{label}</p>
                                    <p className={['mt-3 truncate font-bold text-slate-950', emphasis === 'primary' ? 'text-3xl' : 'text-2xl'].join(' ')} title={String(value)}>{value}</p>
                                    <p className="mt-2 text-xs leading-5 text-slate-500">{description}</p>
                                </article>
                            ))}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm print:break-inside-avoid print:shadow-none">
                        <div className="flex items-start gap-3">
                            <div className="rounded-xl bg-indigo-50 p-2 text-indigo-700">
                                <BarChart3 size={18} />
                            </div>
                            <div>
                                <p className="text-sm font-black uppercase tracking-[0.18em] text-slate-800">Timing Insight</p>
                                <p className="mt-2 text-sm leading-6 text-slate-600">{insightText}</p>
                                {isLowData && (
                                    <p className="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                                        Only one active period was found in at least one breakdown. More sales data will make timing patterns clearer.
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>

                    <section>
                        <div className="mb-3">
                            <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-500">Visual Timing Analysis</p>
                            <p className="mt-1 text-sm text-slate-500">Bars show net sales from the current report rows.</p>
                        </div>
                        <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                            <article className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:break-inside-avoid print:shadow-none">
                                <div className="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                    <h3 className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-800">
                                        <Clock size={16} />
                                        Hourly Sales
                                    </h3>
                                    <p className="text-xs font-semibold text-indigo-700">
                                        {peakHourRow ? `Peak: ${peakHourRow.hour_label} · ${money(peakHourRow.net_sales)}` : 'No active peak'}
                                    </p>
                                </div>
                                <SimpleBarChart
                                    rows={filteredHourlyRows}
                                    labelKey="hour_label"
                                    valueKey="net_sales"
                                    accentClass="bg-indigo-500"
                                    peakKey={summary.peak_sales_hour}
                                />
                            </article>
                            <article className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:break-inside-avoid print:shadow-none">
                                <div className="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                    <h3 className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-800">
                                        <CalendarClock size={16} />
                                        Weekday Sales
                                    </h3>
                                    <p className="text-xs font-semibold text-emerald-700">
                                        {peakWeekdayRow ? `Peak: ${peakWeekdayRow.weekday_label} · ${money(peakWeekdayRow.net_sales)}` : 'No active peak'}
                                    </p>
                                </div>
                                <SimpleBarChart
                                    rows={filteredWeekdayRows}
                                    labelKey="weekday_label"
                                    valueKey="net_sales"
                                    accentClass="bg-emerald-500"
                                    peakKey={summary.peak_sales_weekday}
                                    wideLabels
                                />
                            </article>
                        </div>
                    </section>

                    <section>
                        <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p className="text-xs font-black uppercase tracking-[0.18em] text-slate-500">Detailed Timing Breakdown</p>
                                <p className="mt-1 text-sm text-slate-500">
                                    Showing {filteredHourlyRows.length} of {hourlyRows.length} hour blocks and {filteredWeekdayRows.length} of {weekdayRows.length} weekdays.
                                </p>
                            </div>
                            <label className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm print:hidden">
                                <input
                                    type="checkbox"
                                    checked={hideZero}
                                    onChange={(event) => setHideZero(event.target.checked)}
                                    className="rounded border-slate-300 text-indigo-600"
                                />
                                Hide zero-sale rows
                            </label>
                        </div>
                        <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                            <TimingTable
                                icon={Clock}
                                title="Hourly Sales"
                                description="Twenty-four hour breakdown from each sale reporting timestamp."
                                labelHeader="Hour Block"
                                labelKey="hour_label"
                                rows={filteredHourlyRows}
                                highlightKey={summary.peak_sales_hour}
                                lowlightKey={summary.lowest_sales_hour}
                            />
                            <TimingTable
                                icon={CalendarClock}
                                title="Weekday Sales"
                                description="Weekday pattern using stored sale timestamps only."
                                labelHeader="Weekday"
                                labelKey="weekday_label"
                                rows={filteredWeekdayRows}
                                highlightKey={summary.peak_sales_weekday}
                                lowlightKey={summary.lowest_sales_weekday}
                            />
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function buildInsightText({ hasAnySales, isLowData, peakHourRow, peakWeekdayRow, summary }) {
    if (!hasAnySales) {
        return 'No sales found for the selected filters. Try changing the date range, branch, cashier, or status.';
    }

    const peakHourText = peakHourRow ? `${peakHourRow.hour_label} with ${money(peakHourRow.net_sales)} net sales` : 'no active hour';
    const peakWeekdayText = peakWeekdayRow ? `${peakWeekdayRow.weekday_label} with ${money(peakWeekdayRow.net_sales)} net sales` : 'no active weekday';
    const lowDataText = isLowData ? ' Only one active sales period was found in part of the report.' : '';

    return `Most sales happened on ${peakWeekdayText}; the strongest hour was ${peakHourText}. The report includes ${number(summary.total_transactions)} transactions and ${money(summary.total_net_sales)} net sales under the selected filters.${lowDataText}`;
}

function SimpleBarChart({ rows, labelKey, valueKey, accentClass, peakKey, wideLabels = false }) {
    const values = rows.map((row) => Math.max(Number(row[valueKey] || 0), 0));
    const maxValue = Math.max(...values, 0);

    if (rows.length === 0) {
        return (
            <div className="flex min-h-48 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 text-sm font-semibold text-slate-400">
                No sales recorded for this period.
            </div>
        );
    }

    return (
        <div className="relative flex h-60 items-end gap-1 overflow-x-auto rounded-xl border border-slate-100 bg-slate-50 px-3 pb-4 pt-8">
            <span className="absolute right-3 top-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                Max {money(maxValue)}
            </span>
            {rows.map((row) => {
                const value = Number(row[valueKey] || 0);
                const height = maxValue > 0 ? Math.max((value / maxValue) * 100, value > 0 ? 4 : 1) : 1;
                const isPeak = peakKey && row[labelKey] === peakKey;

                return (
                    <div key={row[labelKey]} className={['flex flex-1 flex-col items-center justify-end gap-2', wideLabels ? 'min-w-16' : 'min-w-8'].join(' ')}>
                        <div className="flex h-36 w-full items-end">
                            <div className="relative flex w-full items-end" style={{ height: `${height}%` }}>
                                {isPeak && value > 0 && (
                                    <span className="absolute -top-6 left-1/2 -translate-x-1/2 rounded-full bg-slate-900 px-2 py-0.5 text-[10px] font-bold text-white">
                                        Peak
                                    </span>
                                )}
                                <div
                                    className={[
                                        'h-full w-full rounded-t-md transition-all',
                                        value > 0 ? accentClass : 'bg-slate-200',
                                        isPeak ? 'ring-2 ring-slate-900 ring-offset-2' : '',
                                    ].filter(Boolean).join(' ')}
                                    title={`${row[labelKey]}: ${money(value)}`}
                                />
                            </div>
                        </div>
                        <span className={['text-center text-[10px] font-bold text-slate-500', wideLabels ? 'w-16' : 'max-w-12 truncate'].join(' ')} title={row[labelKey]}>
                            {row[labelKey]}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

function TimingTable({ icon: Icon, title, description, labelHeader, labelKey, rows, highlightKey, lowlightKey }) {
    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm print:break-inside-avoid print:shadow-none">
            <div className="mb-4">
                <h3 className="flex items-center gap-2 text-sm font-black uppercase tracking-[0.18em] text-slate-800">
                    <Icon size={16} />
                    {title}
                </h3>
                <p className="mt-1 text-sm text-slate-500">{description}</p>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[620px] text-left text-sm">
                    <thead>
                        <tr className="border-b border-slate-100 text-[11px] font-black uppercase tracking-widest text-slate-500">
                            <th className="py-3 pr-4">{labelHeader}</th>
                            <th className="py-3 text-right">Transactions</th>
                            <th className="py-3 text-right">Gross Sales</th>
                            <th className="py-3 text-right">Net Sales</th>
                            <th className="py-3 text-right">Average Ticket</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {rows.length > 0 ? rows.map((row) => {
                            const isPeak = highlightKey && row[labelKey] === highlightKey;
                            const isLow = lowlightKey && row[labelKey] === lowlightKey;
                            return (
                                <tr key={row[labelKey]}
                                    className={
                                        isPeak ? 'bg-emerald-50 font-bold' :
                                        isLow ? 'bg-yellow-50' :
                                        Number(row.transaction_count) === 0 ? 'text-slate-400' : ''
                                    }
                                >
                                    <td className="py-3 pr-4 font-bold text-slate-900">
                                        <span className="inline-flex items-center gap-2">
                                            {row[labelKey]}
                                            {isPeak && <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-700">Peak</span>}
                                            {isLow && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-amber-700">Low</span>}
                                        </span>
                                    </td>
                                    <td className="py-3 text-center font-semibold text-slate-800">{number(row.transaction_count)}</td>
                                    <td className={['py-3 text-right', Number(row.gross_sales) === 0 ? 'text-slate-400' : 'text-slate-700'].join(' ')}>{money(row.gross_sales)}</td>
                                    <td className={['py-3 text-right font-bold', Number(row.net_sales) === 0 ? 'text-slate-400' : 'text-slate-900'].join(' ')}>{money(row.net_sales)}</td>
                                    <td className={['py-3 text-right', Number(row.average_transaction_value) === 0 ? 'text-slate-400' : 'text-slate-700'].join(' ')}>{money(row.average_transaction_value)}</td>
                                </tr>
                            );
                        }) : (
                            <tr>
                                <td colSpan={5} className="py-8 text-center text-slate-400">No sales recorded for this period.</td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
