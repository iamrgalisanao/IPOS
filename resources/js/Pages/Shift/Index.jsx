import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Search, Filter, Calendar, User, Building2, ChevronRight, Clock } from 'lucide-react';

export default function ShiftIndex({ auth, shifts, filters }) {
    const handleFilter = (key, value) => {
        router.get(route('shifts.index'), {
            ...filters,
            [key]: value
        }, { preserveState: true });
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'open': return 'bg-emerald-100 text-emerald-700 border-emerald-200';
            case 'closing_submitted': return 'bg-amber-100 text-amber-700 border-amber-200';
            case 'approved': return 'bg-blue-100 text-blue-700 border-blue-200';
            case 'closed': return 'bg-gray-100 text-gray-700 border-gray-200';
            default: return 'bg-gray-100 text-gray-700 border-gray-200';
        }
    };

    const formatDate = (dateStr) => {
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
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-bold leading-tight text-gray-800">Shift Operations</h2>
                    <div className="flex items-center gap-2 text-sm text-gray-500">
                        <Clock size={16} />
                        <span>Recent Activity</span>
                    </div>
                </div>
            }
        >
            <Head title="Shifts" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">
                    
                    {/* Filters */}
                    <div className="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-lg border border-gray-100">
                            <Filter size={16} className="text-gray-400" />
                            <select 
                                value={filters.status || ''} 
                                onChange={(e) => handleFilter('status', e.target.value)}
                                className="bg-transparent border-none text-sm focus:ring-0 p-0 pr-8 text-gray-700"
                            >
                                <option value="">All Statuses</option>
                                <option value="open">Open</option>
                                <option value="closing_submitted">Pending Review</option>
                                <option value="approved">Approved</option>
                            </select>
                        </div>

                        <div className="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-lg border border-gray-100">
                            <Calendar size={16} className="text-gray-400" />
                            <input 
                                type="date" 
                                value={filters.date || ''} 
                                onChange={(e) => handleFilter('date', e.target.value)}
                                className="bg-transparent border-none text-sm focus:ring-0 p-0 text-gray-700"
                            />
                        </div>
                    </div>

                    {/* Shift Table */}
                    <div className="bg-white shadow-sm rounded-xl border border-gray-100 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead className="bg-gray-50/50 border-b border-gray-50">
                                    <tr>
                                        <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Timeframe</th>
                                        <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Cashier / Branch</th>
                                        <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Status</th>
                                        <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Variance</th>
                                        <th className="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {shifts.data.length > 0 ? shifts.data.map((shift) => (
                                        <tr key={shift.id} className="hover:bg-gray-50/50 transition-colors group cursor-pointer" onClick={() => router.get(route('shifts.show', shift.id))}>
                                            <td className="px-6 py-4">
                                                <div className="text-sm font-semibold text-gray-900">{formatDate(shift.opened_at)}</div>
                                                <div className="text-xs text-gray-400 mt-0.5">
                                                    {shift.closed_at ? `Closed ${formatDate(shift.closed_at)}` : (shift.closing_submitted_at ? `Submitted ${formatDate(shift.closing_submitted_at)}` : 'Still active')}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-2">
                                                    <User size={14} className="text-gray-400" />
                                                    <span className="text-sm text-gray-700">{shift.cashier?.name}</span>
                                                </div>
                                                <div className="flex items-center gap-2 mt-1">
                                                    <Building2 size={14} className="text-gray-400" />
                                                    <span className="text-xs text-gray-500">{shift.branch?.name}</span>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex justify-center">
                                                    <span className={`px-2.5 py-1 text-[10px] font-bold rounded-full border uppercase tracking-wider ${getStatusColor(shift.status)}`}>
                                                        {shift.status.replace('_', ' ')}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                {shift.status === 'open' ? (
                                                    <span className="text-xs text-gray-400 italic">Calculating...</span>
                                                ) : (
                                                    <span className={`text-sm font-bold ${parseFloat(shift.variance_amount) === 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                                                        {new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(shift.variance_amount)}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <ChevronRight size={18} className="text-gray-300 group-hover:text-blue-500 transition-colors inline" />
                                            </td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan="5" className="px-6 py-12 text-center text-gray-500 italic">No shifts found matching the current filters.</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        
                        {/* Pagination */}
                        {shifts.links.length > 3 && (
                            <div className="px-6 py-4 bg-gray-50/50 border-t border-gray-50 flex items-center justify-center gap-2">
                                {shifts.links.map((link, i) => (
                                    <button
                                        key={i}
                                        disabled={!link.url || link.active}
                                        onClick={() => router.get(link.url)}
                                        className={`px-3 py-1 text-xs font-medium rounded-md transition-colors ${link.active ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-50'}`}
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
