import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Search, Filter, Calendar, User, Building2, ChevronRight, Clock, FileText, X } from 'lucide-react';

export default function Index({ shifts, filters, branches, cashiers }) {
    const [form, setForm] = useState({
        status: filters.status || '',
        date: filters.date || '',
        branch_id: filters.branch_id || '',
        cashier_id: filters.cashier_id || '',
    });

    const handleFilterSubmit = (e) => {
        e.preventDefault();
        router.get(route('reports.cashier-accountability.index'), form, { preserveState: true });
    };

    const handleReset = () => {
        const resetForm = { status: '', date: '', branch_id: '', cashier_id: '' };
        setForm(resetForm);
        router.get(route('reports.cashier-accountability.index'), resetForm, { preserveState: true });
    };

    const getStatusBadge = (status) => {
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

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP'
        }).format(parseFloat(val || 0));
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div className="space-y-1">
                        <div className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-600">
                            <Clock size={12} />
                            Audit & Reporting
                        </div>
                        <h2 className="text-2xl font-bold leading-tight text-gray-900 sm:text-3xl">
                            Cashier Accountability Reports
                        </h2>
                        <p className="text-sm text-slate-500">
                            Branch-scoped operational accountability and shift reconciliation audit trails.
                        </p>
                    </div>
                </div>
            }
        >
            <Head title="Cashier Accountability Reports" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">
                    
                    {/* Filters Panel */}
                    <div className="bg-white rounded-[24px] border border-gray-150 shadow-sm p-6">
                        <div className="flex items-center gap-2 mb-4 border-b border-gray-50 pb-3">
                            <Filter size={16} className="text-slate-400" />
                            <h3 className="text-xs font-bold text-slate-700 uppercase tracking-widest">Filter Shifts</h3>
                        </div>

                        <form onSubmit={handleFilterSubmit} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                            <div className="space-y-1">
                                <label className="text-xs font-bold text-slate-500 uppercase tracking-wider">Branch</label>
                                <select
                                    value={form.branch_id}
                                    onChange={(e) => setForm({ ...form, branch_id: e.target.value })}
                                    className="w-full rounded-xl border-gray-200 text-sm bg-gray-50/50 focus:bg-white focus:ring-slate-500 focus:border-slate-500"
                                >
                                    <option value="">All Branches</option>
                                    {branches && branches.map((b) => (
                                        <option key={b.id} value={b.id}>{b.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-1">
                                <label className="text-xs font-bold text-slate-500 uppercase tracking-wider">Cashier</label>
                                <select
                                    value={form.cashier_id}
                                    onChange={(e) => setForm({ ...form, cashier_id: e.target.value })}
                                    className="w-full rounded-xl border-gray-200 text-sm bg-gray-50/50 focus:bg-white focus:ring-slate-500 focus:border-slate-500"
                                >
                                    <option value="">All Cashiers</option>
                                    {cashiers && cashiers.map((c) => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-1">
                                <label className="text-xs font-bold text-slate-500 uppercase tracking-wider">Status</label>
                                <select
                                    value={form.status}
                                    onChange={(e) => setForm({ ...form, status: e.target.value })}
                                    className="w-full rounded-xl border-gray-200 text-sm bg-gray-50/50 focus:bg-white focus:ring-slate-500 focus:border-slate-500"
                                >
                                    <option value="">All Statuses</option>
                                    <option value="open">Open</option>
                                    <option value="closing_submitted">Pending Review</option>
                                    <option value="approved">Approved</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>

                            <div className="space-y-1">
                                <label className="text-xs font-bold text-slate-500 uppercase tracking-wider">Date Opened</label>
                                <input
                                    type="date"
                                    value={form.date}
                                    onChange={(e) => setForm({ ...form, date: e.target.value })}
                                    className="w-full rounded-xl border-gray-200 text-sm bg-gray-50/50 focus:bg-white focus:ring-slate-500 focus:border-slate-500"
                                />
                            </div>

                            <div className="flex items-end gap-2">
                                <button
                                    type="submit"
                                    className="flex-1 bg-slate-900 text-white rounded-xl py-2 px-4 text-sm font-semibold hover:bg-slate-800 transition-colors shadow-sm"
                                >
                                    Apply
                                </button>
                                <button
                                    type="button"
                                    onClick={handleReset}
                                    className="border border-gray-200 hover:bg-gray-50 text-slate-700 rounded-xl py-2 px-4 text-sm font-semibold transition-colors"
                                >
                                    Reset
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* Shifts Listing */}
                    <div className="bg-white shadow-sm rounded-[24px] border border-gray-150 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead className="bg-slate-50/75 border-b border-slate-100">
                                    <tr>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Shift Reference / Branch</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Cashier</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-center">Status</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Expected Cash</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Counted Cash</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Variance</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {shifts.data.length > 0 ? (
                                        shifts.data.map((shift) => {
                                            const variance = parseFloat(shift.variance_amount || 0);
                                            const hasVariance = variance !== 0 && shift.status !== 'open';

                                            return (
                                                <tr
                                                    key={shift.id}
                                                    onClick={() => router.get(route('reports.cashier-accountability.show', shift.id))}
                                                    className="hover:bg-slate-50/50 transition-colors group cursor-pointer"
                                                >
                                                    <td className="px-6 py-4">
                                                        <div className="text-sm font-semibold text-slate-900">
                                                            Shift #{shift.id.substring(0, 8)}...
                                                        </div>
                                                        <div className="flex items-center gap-1.5 text-xs text-slate-400 mt-1">
                                                            <Building2 size={12} />
                                                            <span>{shift.branch?.name || 'N/A'}</span>
                                                        </div>
                                                        <div className="text-[10px] text-slate-400 mt-0.5">
                                                            Opened: {formatDate(shift.opened_at)}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="flex items-center gap-2">
                                                            <div className="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center text-xs font-bold text-slate-600 uppercase">
                                                                {shift.cashier?.name?.charAt(0) || 'U'}
                                                            </div>
                                                            <div className="text-sm font-medium text-slate-700">
                                                                {shift.cashier?.name || 'N/A'}
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <div className="flex justify-center">
                                                            <span className={`px-3 py-1 text-[10px] font-bold rounded-full border uppercase tracking-wider ${getStatusBadge(shift.status)}`}>
                                                                {getStatusText(shift.status)}
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 text-right text-sm font-medium text-slate-600">
                                                        {formatCurrency(shift.expected_cash_amount)}
                                                    </td>
                                                    <td className="px-6 py-4 text-right text-sm font-medium text-slate-600">
                                                        {shift.status === 'open' ? (
                                                            <span className="text-xs text-slate-400 italic">Open Shift</span>
                                                        ) : (
                                                            formatCurrency(shift.counted_cash_amount)
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 text-right">
                                                        {shift.status === 'open' ? (
                                                            <span className="text-xs text-slate-400 italic">Calculating</span>
                                                        ) : (
                                                            <span className={`text-sm font-bold ${variance === 0 ? 'text-emerald-600' : (variance < 0 ? 'text-rose-600' : 'text-blue-600')}`}>
                                                                {formatCurrency(shift.variance_amount)}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-4 text-right">
                                                        <ChevronRight size={18} className="text-slate-300 group-hover:text-slate-900 transition-colors inline" />
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    ) : (
                                        <tr>
                                            <td colSpan="7" className="px-6 py-12 text-center text-slate-400 italic">
                                                <FileText size={32} className="mx-auto mb-2 text-slate-300" />
                                                No shifts found matching the current filters.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination Links */}
                        {shifts.links && shifts.links.length > 3 && (
                            <div className="px-6 py-4 bg-slate-50/50 border-t border-slate-100 flex items-center justify-center gap-1.5">
                                {shifts.links.map((link, i) => (
                                    <button
                                        key={i}
                                        disabled={!link.url || link.active}
                                        onClick={() => router.get(link.url)}
                                        className={`px-3.5 py-1.5 text-xs font-semibold rounded-lg transition-colors ${
                                            link.active
                                                ? 'bg-slate-900 text-white shadow-sm'
                                                : 'bg-white border border-gray-200 text-slate-600 hover:bg-slate-50 disabled:opacity-50'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
