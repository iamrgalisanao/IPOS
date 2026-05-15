import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { 
    Search, 
    Filter, 
    Eye, 
    ArrowRight, 
    Calendar, 
    Clock, 
    User as UserIcon, 
    CreditCard, 
    AlertCircle,
    CheckCircle2,
    XCircle,
    History,
    Download
} from 'lucide-react';

const StatusBadge = ({ status }) => {
    const statusConfig = {
        paid: { color: 'bg-emerald-100 text-emerald-800 border-emerald-200', icon: CheckCircle2 },
        voided: { color: 'bg-rose-100 text-rose-800 border-rose-200', icon: XCircle },
        refunded: { color: 'bg-amber-100 text-amber-800 border-amber-200', icon: History },
        draft: { color: 'bg-slate-100 text-slate-800 border-slate-200', icon: AlertCircle },
    };

    const config = statusConfig[status] || statusConfig.draft;
    const Icon = config.icon;

    return (
        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border ${config.color}`}>
            <Icon size={12} />
            {status.charAt(0).toUpperCase() + status.slice(1)}
        </span>
    );
};

export default function Index({ auth, sales, filters, meta }) {
    const [search, setSearch] = useState(filters.search || '');
    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');
    const [status, setStatus] = useState(filters.status || '');

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('sales.history.index'), {
            search,
            start_date: startDate,
            end_date: endDate,
            status,
        }, { preserveState: true, replace: true });
    };

    const handleReset = () => {
        setSearch('');
        setStartDate('');
        setEndDate('');
        setStatus('');
        router.get(route('sales.history.index'), {}, { replace: true });
    };

    const handleExport = () => {
        const queryParams = new URLSearchParams({
            search,
            start_date: startDate,
            end_date: endDate,
            status,
            ...filters // Preserve any other filters like cashier_id or branch_id if passed in props
        }).toString();
        
        window.location.href = route('sales.history.export') + '?' + queryParams;
    };

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
        }).format(parseFloat(val || 0));
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return 'N/A';
        return new Date(dateStr).toLocaleString('en-PH', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1">
                        <div className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-600">
                            <History size={12} />
                            Sales Audit
                        </div>
                        <h2 className="text-2xl font-bold leading-tight text-slate-900">Transaction History</h2>
                    </div>
                    {meta.can_export && (
                        <button
                            onClick={handleExport}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-bold rounded-xl hover:bg-emerald-700 transition-all shadow-sm shadow-emerald-200/50"
                        >
                            <Download size={16} />
                            Export CSV
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Sales History" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">
                    {/* Filters Section */}
                    <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                        <form onSubmit={handleSearch} className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Search</label>
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                                        <input
                                            type="text"
                                            placeholder="Sale # or UUID"
                                            className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 transition-all"
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Start Date</label>
                                    <div className="relative">
                                        <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                                        <input
                                            type="date"
                                            className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 transition-all"
                                            value={startDate}
                                            onChange={(e) => setStartDate(e.target.value)}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-slate-500 uppercase tracking-wider">End Date</label>
                                    <div className="relative">
                                        <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                                        <input
                                            type="date"
                                            className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 transition-all"
                                            value={endDate}
                                            onChange={(e) => setEndDate(e.target.value)}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</label>
                                    <select
                                        className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 transition-all appearance-none"
                                        value={status}
                                        onChange={(e) => setStatus(e.target.value)}
                                    >
                                        <option value="">All Statuses</option>
                                        <option value="paid">Paid</option>
                                        <option value="voided">Voided</option>
                                        <option value="refunded">Refunded</option>
                                    </select>
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3 pt-2">
                                <button
                                    type="button"
                                    onClick={handleReset}
                                    className="px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 rounded-xl transition-colors"
                                >
                                    Reset Filters
                                </button>
                                <button
                                    type="submit"
                                    className="inline-flex items-center gap-2 px-6 py-2 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition-all shadow-sm"
                                >
                                    <Filter size={16} />
                                    Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* Table Section */}
                    <div className="bg-white rounded-[28px] shadow-sm border border-slate-200 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50 border-b border-slate-100">
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest">Transaction Details</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest">Status</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest">Branch</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest text-right">Total Amount</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {sales.data.length > 0 ? sales.data.map((sale) => (
                                        <tr key={sale.id} className="hover:bg-slate-50/80 transition-colors group">
                                            <td className="px-6 py-5">
                                                <div className="flex flex-col">
                                                    <span className="text-sm font-bold text-slate-900 group-hover:text-blue-600 transition-colors">
                                                        {sale.sale_number}
                                                    </span>
                                                    <div className="flex items-center gap-2 mt-1 text-xs text-slate-500">
                                                        <Clock size={12} />
                                                        {formatDate(sale.confirmed_at || sale.created_at)}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-5">
                                                <StatusBadge status={sale.status} />
                                            </td>
                                            <td className="px-6 py-5">
                                                <div className="flex items-center gap-2 text-sm text-slate-600 font-medium">
                                                    <div className="w-2 h-2 rounded-full bg-blue-400"></div>
                                                    {sale.branch_name || 'Main Branch'}
                                                </div>
                                            </td>
                                            <td className="px-6 py-5 text-right">
                                                <span className="text-base font-bold text-slate-900">
                                                    {formatCurrency(sale.total)}
                                                </span>
                                            </td>
                                            <td className="px-6 py-5 text-right">
                                                {meta.can_view_details && (
                                                    <Link
                                                        href={route('sales.history.show', sale.id)}
                                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-slate-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-all"
                                                    >
                                                        Details
                                                        <ArrowRight size={14} />
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan="5" className="px-6 py-12 text-center">
                                                <div className="flex flex-col items-center gap-3">
                                                    <div className="p-4 rounded-full bg-slate-50">
                                                        <Search size={32} className="text-slate-300" />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <p className="text-sm font-semibold text-slate-900">No transactions found</p>
                                                        <p className="text-xs text-slate-500">Try adjusting your filters or search terms.</p>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination Footer */}
                        <div className="px-6 py-4 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between">
                            <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">
                                Showing {sales.from || 0} to {sales.to || 0} of {sales.total} transactions
                            </p>
                            <div className="flex items-center gap-2">
                                {sales.links.map((link, idx) => (
                                    <Link
                                        key={idx}
                                        href={link.url}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                        className={`px-3 py-1.5 text-xs font-bold rounded-lg transition-all ${
                                            link.active 
                                            ? 'bg-slate-900 text-white shadow-md' 
                                            : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 disabled:opacity-50'
                                        } ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                    />
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
