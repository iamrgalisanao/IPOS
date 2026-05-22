import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { 
    Clock, 
    Building2, 
    User, 
    ArrowLeft, 
    Printer, 
    FileText, 
    TrendingUp, 
    DollarSign, 
    CreditCard, 
    AlertCircle, 
    HelpCircle, 
    RefreshCcw, 
    Layers,
    ArrowDownRight,
    ArrowUpRight,
    Download
} from 'lucide-react';

export default function Show({ auth, report }) {
    const permissions = Array.isArray(auth?.permissions) ? auth.permissions : [];
    const canExport = permissions.includes('reports.cashier-accountability.export') || 
                      permissions.includes('reports.shift-summary.export');

    const { 
        shift, 
        cashier, 
        branch, 
        timeline, 
        sales_summary, 
        payment_mix, 
        drawer_summary, 
        cash_variance, 
        drawer_timeline, 
        metadata 
    } = report || {};

    const formatCurrency = (val) => {
        let parsed = parseFloat(val || 0);
        if (Object.is(parsed, -0) || parsed === 0) {
            parsed = 0;
        }
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP'
        }).format(parsed);
    };

    const formatDiscount = (val) => {
        const num = parseFloat(val || 0);
        return num > 0 ? `-${formatCurrency(val)}` : formatCurrency(val);
    };

    const formatCashIn = (val) => {
        const num = parseFloat(val || 0);
        return num > 0 ? `+${formatCurrency(val)}` : formatCurrency(val);
    };

    const formatCashOut = (val) => {
        const num = parseFloat(val || 0);
        return num > 0 ? `-${formatCurrency(val)}` : formatCurrency(val);
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return 'N/A';
        return new Date(dateStr).toLocaleString('en-PH', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    };

    const handlePrint = () => {
        window.print();
    };

    const isDeclared = cash_variance?.declared_cash !== null;
    const varianceVal = isDeclared ? parseFloat(cash_variance?.variance || 0) : null;

    let varianceColorClass = '';
    if (!isDeclared) {
        varianceColorClass = 'text-amber-800 bg-amber-50 border-amber-200';
    } else if (varianceVal === 0) {
        varianceColorClass = 'text-emerald-800 bg-emerald-50 border-emerald-200';
    } else if (varianceVal < 0) {
        varianceColorClass = 'text-rose-800 bg-rose-50 border-rose-200';
    } else {
        varianceColorClass = 'text-blue-800 bg-blue-50 border-blue-200';
    }

    const getStatusStyle = (status) => {
        switch (status) {
            case 'open':
                return 'bg-emerald-50 text-emerald-700 border-emerald-200';
            case 'closing_submitted':
                return 'bg-amber-50 text-amber-700 border-amber-200';
            case 'approved':
                return 'bg-blue-50 text-blue-700 border-blue-200';
            case 'closed':
                return 'bg-slate-50 text-slate-700 border-slate-200';
            default:
                return 'bg-gray-50 text-gray-700 border-gray-200';
        }
    };

    const getStatusText = (status) => {
        switch (status) {
            case 'open': return 'Open';
            case 'closing_submitted': return 'Pending Review';
            case 'approved': return 'Approved';
            case 'closed': return 'Closed';
            default: return status;
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 no-print py-1">
                    <div className="flex items-start gap-4">
                        <Link 
                            href={route('reports.cashier-accountability.index')}
                            className="p-2 hover:bg-slate-50 rounded-xl text-slate-500 hover:text-slate-900 border border-gray-200 shadow-sm transition-all mt-1 bg-white flex items-center justify-center active:scale-[0.97]"
                            title="Back to Reports"
                        >
                            <ArrowLeft size={18} />
                        </Link>
                        <div className="space-y-1">
                            <div className="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 border border-indigo-100 px-3 py-0.5 text-[10px] font-bold uppercase tracking-wider text-indigo-700">
                                <FileText size={10} />
                                Audit Report Details
                            </div>
                            <h2 className="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl leading-none">
                                Shift Accountability Audit
                            </h2>
                            <div className="font-mono text-[11px] text-slate-400 select-all flex items-center gap-1">
                                Reference ID: <span className="text-slate-600 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded-md font-bold">{shift.id}</span>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-3 shrink-0 self-end sm:self-center">
                        {canExport && (
                            <a
                                href={route('reports.cashier-accountability.export', shift.id)}
                                className="inline-flex items-center gap-2 bg-white text-slate-700 border border-gray-200 rounded-xl py-2.5 px-4 text-sm font-semibold hover:bg-slate-50 transition-all shadow-sm active:scale-[0.98]"
                            >
                                <Download size={16} className="text-slate-400" />
                                Export CSV
                            </a>
                        )}
                        <button
                            onClick={handlePrint}
                            className="inline-flex items-center gap-2 bg-slate-900 text-white rounded-xl py-2.5 px-4 text-sm font-semibold hover:bg-slate-800 transition-all shadow-sm border border-slate-950 active:scale-[0.98]"
                        >
                            <Printer size={16} />
                            Print Report
                        </button>
                    </div>
                </div>
            }
        >
            <Head title={`Shift Accountability Report - ${shift.id.substring(0, 8)}`} />

            {/* Print Header - Only visible when printing */}
            <div className="hidden print:block p-6 border-b border-gray-200 space-y-4">
                <div className="flex justify-between items-start">
                    <div>
                        <h1 className="text-2xl font-black tracking-tight text-slate-900 uppercase italic">IPOS</h1>
                        <p className="text-xs text-slate-500 uppercase tracking-widest font-semibold">Cashier Accountability Report</p>
                    </div>
                    <div className="text-right">
                        <span className={`px-3 py-1 text-[10px] font-bold rounded-full border uppercase tracking-wider ${getStatusStyle(shift.status)}`}>
                            {getStatusText(shift.status)}
                        </span>
                        <p className="text-[10px] text-slate-400 mt-2">Report Reference: {shift.id}</p>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 text-sm bg-slate-50 rounded-xl p-4 border border-slate-100">
                    <div>
                        <p className="text-xs text-slate-400 uppercase tracking-wider font-bold">Location & Cashier</p>
                        <p className="font-semibold text-slate-800 mt-1">{branch.name}</p>
                        <p className="text-slate-600 text-xs">Cashier: {cashier.name}</p>
                    </div>
                    <div>
                        <p className="text-xs text-slate-400 uppercase tracking-wider font-bold">Shift Timings</p>
                        <p className="text-xs text-slate-700 mt-1">Opened: {formatDate(timeline.opened_at)}</p>
                        <p className="text-xs text-slate-700">Closed: {formatDate(timeline.closed_at || timeline.closing_submitted_at)}</p>
                    </div>
                </div>
            </div>

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">

                    {/* Operational Overview Banner (Card style) */}
                    <div className="bg-white rounded-[24px] border border-gray-150 shadow-sm overflow-hidden grid grid-cols-1 md:grid-cols-4 divide-y md:divide-y-0 md:divide-x divide-gray-100">
                        <div className="p-6 space-y-2">
                            <div className="flex items-center gap-2 text-slate-400">
                                <User size={16} />
                                <span className="text-xs font-bold uppercase tracking-wider">Cashier</span>
                            </div>
                            <p className="text-lg font-bold text-slate-800">{cashier.name}</p>
                            <p className="text-xs text-slate-400">ID: {cashier.id.substring(0, 8)}...</p>
                        </div>
                        <div className="p-6 space-y-2">
                            <div className="flex items-center gap-2 text-slate-400">
                                <Building2 size={16} />
                                <span className="text-xs font-bold uppercase tracking-wider">Branch</span>
                            </div>
                            <p className="text-lg font-bold text-slate-800">{branch.name}</p>
                            <p className="text-xs text-slate-400">ID: {branch.id.substring(0, 8)}...</p>
                        </div>
                        <div className="p-6 space-y-2">
                            <div className="flex items-center gap-2 text-slate-400">
                                <Clock size={16} />
                                <span className="text-xs font-bold uppercase tracking-wider">Status & Timing</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className={`px-2.5 py-0.5 text-[10px] font-bold rounded-full border uppercase tracking-wider ${getStatusStyle(shift.status)}`}>
                                    {getStatusText(shift.status)}
                                </span>
                            </div>
                            <p className="text-xs text-slate-500 mt-1 leading-relaxed">
                                Opened: {formatDate(timeline.opened_at)}
                                {timeline.closed_at && <><br />Closed: {formatDate(timeline.closed_at)}</>}
                            </p>
                        </div>
                        <div className="p-6 space-y-2 bg-slate-50/50">
                            <div className="flex items-center gap-2 text-slate-400">
                                <Layers size={16} />
                                <span className="text-xs font-bold uppercase tracking-wider">Reconciliation Scope</span>
                            </div>
                            <p className="text-xs text-slate-600 leading-relaxed">
                                Temporal boundaries locked at opening tick: <span className="font-semibold">{formatDate(timeline.opened_at)}</span> up to close boundary: <span className="font-semibold">{formatDate(timeline.closed_at || timeline.closing_submitted_at)}</span>.
                            </p>
                        </div>
                    </div>

                    {/* Stated Variance Callout Card */}
                    <div className={`rounded-[24px] border shadow-sm p-6 overflow-hidden ${varianceColorClass}`}>
                        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                            <div className="space-y-2 flex-1">
                                <div className="flex items-center gap-2">
                                    <AlertCircle size={18} />
                                    <h3 className="text-sm font-bold uppercase tracking-wider">
                                        {!isDeclared ? 'Awaiting Cash Declaration' : 'Cash Variance Reconciliation'}
                                    </h3>
                                </div>
                                <p className="text-xs opacity-90 max-w-2xl leading-relaxed">
                                    {!isDeclared 
                                        ? 'The cashier has closed their shift, but the physical cash count has not been declared yet. Awaiting declaration to complete variance reconciliation.'
                                        : 'Source of truth mathematical audit. Expected cash represents opening cash drawer plus net cash transactions within the shift. Counted cash is declared during shift settlement closing.'
                                    }
                                </p>
                                <div className="space-y-1 mt-2">
                                    <div className="text-[11px] font-bold font-mono bg-white/40 border border-white/20 inline-block px-3 py-1.5 rounded-lg">
                                        Formula: Expected = Opening Cash ({formatCurrency(drawer_summary.opening_cash)}) + Cash In ({formatCurrency(drawer_summary.cash_in)}) - Cash Out ({formatCurrency(drawer_summary.cash_out)}) + Cash Sales ({formatCurrency(payment_mix.cash_sales)}) - Cash Refunds ({formatCurrency(report.reversal_summary?.cash_refunds || 0)})
                                    </div>
                                    {shift.status !== 'open' && (
                                        <div className="text-[10px] italic opacity-85 block">
                                            * Note: For finalized shifts, Expected Cash represents the immutable audit snapshot locked at the time of settlement closing.
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="grid grid-cols-3 gap-6 md:text-right shrink-0">
                                <div>
                                    <p className="text-[10px] uppercase font-bold tracking-wider opacity-75">Expected Cash</p>
                                    <p className="text-lg font-bold mt-1 text-slate-800">{formatCurrency(cash_variance.expected_cash)}</p>
                                </div>
                                <div>
                                    <p className="text-[10px] uppercase font-bold tracking-wider opacity-75">Counted Cash</p>
                                    <p className="text-lg font-bold mt-1 text-slate-800">
                                        {cash_variance.declared_cash !== null ? formatCurrency(cash_variance.declared_cash) : 'Unsubmitted'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-[10px] uppercase font-bold tracking-wider opacity-75">Variance</p>
                                    <p className="text-xl font-black mt-1">
                                        {isDeclared ? formatCurrency(cash_variance.variance) : 'Awaiting Count'}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Sales Summary & Payment Mix */}
                        <div className="bg-white rounded-[24px] border border-gray-150 shadow-sm p-6 space-y-6">
                            <div>
                                <h3 className="text-base font-bold text-slate-800 flex items-center gap-2">
                                    <TrendingUp size={18} className="text-slate-400" />
                                    Sales Summary (linked shift sales)
                                </h3>
                                <p className="text-xs text-slate-400 mt-1">Summary of gross trade volume, discounts, and reversal offsets.</p>
                            </div>

                            <div className="space-y-3">
                                <div className="flex justify-between items-center text-sm py-2 border-b border-slate-50">
                                    <span className="text-slate-500 font-medium">Gross Trade Sales</span>
                                    <span className="font-bold text-slate-800">{formatCurrency(sales_summary.gross_sales)}</span>
                                </div>
                                <div className="flex justify-between items-center text-sm py-2 border-b border-slate-50">
                                    <span className="text-slate-500 font-medium">Statutory & Manual Discounts</span>
                                    <span className="font-bold text-rose-500">{formatDiscount(sales_summary.discounts)}</span>
                                </div>
                                <div className="flex justify-between items-center text-sm py-2 border-b border-slate-50">
                                    <span className="text-slate-500 font-medium">Refund Reversals</span>
                                    <span className="font-bold text-rose-500">{formatDiscount(sales_summary.refunds)}</span>
                                </div>
                                <div className="flex justify-between items-center text-sm py-2 border-b border-slate-50">
                                    <span className="text-slate-500 font-medium">Void Reversals</span>
                                    <span className="font-bold text-rose-500">{formatDiscount(sales_summary.voids)}</span>
                                </div>
                                <div className="flex justify-between items-center text-sm py-2.5 bg-slate-50 rounded-xl px-4 font-bold border border-slate-100 mt-2">
                                    <span className="text-slate-700">Net Operational Sales</span>
                                    <span className="text-slate-900 text-base">{formatCurrency(sales_summary.net_sales)}</span>
                                </div>
                            </div>

                            <div className="pt-4 border-t border-slate-100 space-y-4">
                                <h4 className="text-xs font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1.5">
                                    <CreditCard size={14} />
                                    Payment Method Mix
                                </h4>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="bg-slate-50 rounded-2xl p-4 border border-slate-100 space-y-1">
                                        <p className="text-[10px] uppercase font-bold tracking-wider text-slate-400">Cash Payment Sales</p>
                                        <p className="text-lg font-black text-slate-800">{formatCurrency(payment_mix.cash_sales)}</p>
                                    </div>
                                    <div className="bg-slate-50 rounded-2xl p-4 border border-slate-100 space-y-1">
                                        <p className="text-[10px] uppercase font-bold tracking-wider text-slate-400">Non-Cash Payment Sales</p>
                                        <p className="text-lg font-black text-slate-800">{formatCurrency(payment_mix.non_cash_sales)}</p>
                                    </div>
                                </div>

                                <div className="space-y-2 mt-2">
                                    {payment_mix.by_method && payment_mix.by_method.map((method) => (
                                        <div key={method.code} className="flex justify-between items-center text-xs py-1.5 px-3 border border-slate-100 rounded-xl bg-white hover:bg-slate-50 transition-colors">
                                            <span className="text-slate-600 font-semibold uppercase">{method.name}</span>
                                            <span className="text-slate-800 font-bold">{formatCurrency(method.total)} ({method.count} transaction{method.count !== 1 ? 's' : ''})</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* Drawer Events & Summary */}
                        <div className="bg-white rounded-[24px] border border-gray-150 shadow-sm p-6 space-y-6">
                            <div>
                                <h3 className="text-base font-bold text-slate-800 flex items-center gap-2">
                                    <DollarSign size={18} className="text-slate-400" />
                                    Drawer Actions Summary
                                </h3>
                                <p className="text-xs text-slate-400 mt-1">Cash flow events including tops ups, petty cash, and drops.</p>
                            </div>

                            <div className="grid grid-cols-3 gap-4">
                                <div className="border border-slate-100 rounded-2xl p-4 text-center">
                                    <p className="text-[9px] uppercase font-bold tracking-wider text-slate-400">Opening Balance</p>
                                    <p className="text-base font-black text-slate-800 mt-1">{formatCurrency(drawer_summary.opening_cash)}</p>
                                </div>
                                <div className="border border-slate-100 rounded-2xl p-4 text-center">
                                    <p className="text-[9px] uppercase font-bold tracking-wider text-slate-400">Total Cash In</p>
                                    <p className="text-base font-black text-emerald-600 mt-1">{formatCashIn(drawer_summary.cash_in)}</p>
                                </div>
                                <div className="border border-slate-100 rounded-2xl p-4 text-center">
                                    <p className="text-[9px] uppercase font-bold tracking-wider text-slate-400">Total Cash Out</p>
                                    <p className="text-base font-black text-rose-600 mt-1">{formatCashOut(drawer_summary.cash_out)}</p>
                                </div>
                            </div>

                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-xs font-bold text-slate-400 uppercase tracking-widest">Drawer Timeline ({drawer_summary.drawer_event_count} events)</h4>
                                </div>

                                <div className="space-y-3 max-h-[250px] overflow-y-auto pr-1">
                                    {drawer_timeline && drawer_timeline.length > 0 ? (
                                        drawer_timeline.map((evt) => {
                                            const isCashIn = ['cash_in', 'cash_top_up'].includes(evt.event_type);
                                            return (
                                                <div key={evt.id} className="flex justify-between items-start text-xs p-3 border border-slate-100 rounded-xl bg-slate-50/50">
                                                    <div className="space-y-1">
                                                        <div className="flex items-center gap-1.5">
                                                            {isCashIn ? (
                                                                <ArrowUpRight size={14} className="text-emerald-500" />
                                                            ) : (
                                                                <ArrowDownRight size={14} className="text-rose-500" />
                                                            )}
                                                            <span className="font-bold text-slate-700 capitalize">
                                                                {evt.event_type.replace('_', ' ')}
                                                            </span>
                                                        </div>
                                                        <p className="text-slate-500 text-[10px]">{formatDate(evt.occurred_at)}</p>
                                                        {evt.reason_notes && (
                                                            <p className="text-slate-400 italic text-[10px] mt-1">"{evt.reason_notes}"</p>
                                                        )}
                                                    </div>
                                                    <span className={`font-bold ${isCashIn ? 'text-emerald-600' : 'text-rose-600'}`}>
                                                        {isCashIn ? '+' : '-'}{formatCurrency(evt.amount)}
                                                    </span>
                                                </div>
                                            );
                                        })
                                    ) : (
                                        <p className="text-xs text-slate-400 italic text-center py-6">No cash drawer events recorded during this shift.</p>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Metadata Integrity Footer */}
                    <div className="bg-slate-900 text-white rounded-[24px] p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div className="space-y-1">
                            <p className="text-xs font-bold uppercase tracking-widest text-slate-400">System Integrity Metadata</p>
                            <p className="text-xs text-slate-300">
                                This report is dynamically generated from read-only shift history parameters. It conforms with corporate operational standards.
                            </p>
                        </div>
                        <div className="text-xs md:text-right text-slate-400 font-mono">
                            <p>Generated: {formatDate(metadata.generated_at)}</p>
                            <p>Audited By: {metadata.generated_by}</p>
                        </div>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
