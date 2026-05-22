import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { 
    AlertTriangle, 
    Search,
    Download,
    Calendar,
    GitBranch,
    RefreshCw
} from 'lucide-react';

export default function Index({ auth, logs, branches, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [branchId, setBranchId] = useState(filters.branch_id || '');
    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');

    const handleFilterChange = (key, value) => {
        const queryParams = {
            search: searchQuery,
            branch_id: branchId,
            start_date: startDate,
            end_date: endDate,
            [key]: value
        };
        
        router.get(route('inventory.reports.variance-logs.index'), queryParams, {
            preserveState: true,
            replace: true
        });
    };

    const handleReset = () => {
        setSearchQuery('');
        setBranchId('');
        setStartDate('');
        setEndDate('');
        router.get(route('inventory.reports.variance-logs.index'));
    };

    const handleExport = () => {
        const queryParams = new URLSearchParams({
            search: searchQuery,
            branch_id: branchId,
            start_date: startDate,
            end_date: endDate
        }).toString();
        
        window.location.href = `${route('inventory.reports.variance-logs.export')}?${queryParams}`;
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Inventory Variance Logs</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Audit log records of POS recipe inventory shortfalls under Soft-Negative policies.</p>
                    </div>
                    <button
                        onClick={handleExport}
                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
                    >
                        <Download size={18} />
                        Export CSV
                    </button>
                </div>
            }
        >
            <Head title="Inventory Variance Logs" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Filter Bar */}
                    <div className="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm mb-8 space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div className="relative">
                                <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                    <Search size={16} />
                                </div>
                                <input
                                    type="text"
                                    placeholder="Search sku, name, sale #..."
                                    className="block w-full pl-10 pr-4 py-2.5 bg-slate-50 border-none rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all"
                                    value={searchQuery}
                                    onChange={(e) => {
                                        setSearchQuery(e.target.value);
                                        handleFilterChange('search', e.target.value);
                                    }}
                                />
                            </div>

                            <div>
                                <select
                                    className="block w-full py-2.5 bg-slate-50 border-none rounded-2xl text-sm font-medium text-slate-600 focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all"
                                    value={branchId}
                                    onChange={(e) => {
                                        setBranchId(e.target.value);
                                        handleFilterChange('branch_id', e.target.value);
                                    }}
                                >
                                    <option value="">All Branches</option>
                                    {branches.map(b => (
                                        <option key={b.id} value={b.id}>{b.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="relative">
                                <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                    <Calendar size={16} />
                                </div>
                                <input
                                    type="date"
                                    className="block w-full pl-10 pr-4 py-2.5 bg-slate-50 border-none rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all"
                                    value={startDate}
                                    onChange={(e) => {
                                        setStartDate(e.target.value);
                                        handleFilterChange('start_date', e.target.value);
                                    }}
                                    placeholder="Start Date"
                                />
                            </div>

                            <div className="relative">
                                <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                    <Calendar size={16} />
                                </div>
                                <input
                                    type="date"
                                    className="block w-full pl-10 pr-4 py-2.5 bg-slate-50 border-none rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all"
                                    value={endDate}
                                    onChange={(e) => {
                                        setEndDate(e.target.value);
                                        handleFilterChange('end_date', e.target.value);
                                    }}
                                    placeholder="End Date"
                                />
                            </div>
                        </div>

                        <div className="flex justify-end gap-2">
                            <button
                                onClick={handleReset}
                                className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-700 bg-slate-100 rounded-xl transition-all"
                            >
                                <RefreshCw size={14} />
                                Reset Filters
                            </button>
                        </div>
                    </div>

                    {/* Table */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr className="bg-slate-50/50">
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Date/Time</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Branch</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Sale #</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Parent Product</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Ingredient / Product Short</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-right">Required</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-right">Available Before</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-right">Shortage</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-right">Resulting</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-center font-bold">Policy</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Reason</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {logs.data.length > 0 ? (
                                        logs.data.map((log) => (
                                            <tr key={log.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-6 py-4 whitespace-nowrap text-slate-500 font-medium">
                                                    {new Date(log.created_at).toLocaleString()}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="flex items-center gap-2">
                                                        <GitBranch size={14} className="text-slate-400" />
                                                        <span className="font-bold text-slate-700">{log.branch?.name}</span>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-slate-600 font-bold">
                                                    {log.sale?.sale_number}
                                                </td>
                                                <td className="px-6 py-4">
                                                    {log.product ? (
                                                        <div>
                                                            <div className="font-bold text-slate-700">{log.product.name}</div>
                                                            <div className="text-[10px] text-slate-400 uppercase font-medium">SKU: {log.product.sku}</div>
                                                        </div>
                                                    ) : (
                                                        <span className="italic text-slate-400">-</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="font-bold text-slate-700">{log.ingredient?.name}</div>
                                                    <div className="text-[10px] text-slate-400 uppercase font-medium">SKU: {log.ingredient?.sku}</div>
                                                </td>
                                                <td className="px-6 py-4 text-right font-semibold text-slate-600">
                                                    {parseFloat(log.required_quantity).toFixed(2)} {log.unit}
                                                </td>
                                                <td className="px-6 py-4 text-right font-semibold text-slate-600">
                                                    {parseFloat(log.available_quantity_before).toFixed(2)} {log.unit}
                                                </td>
                                                <td className="px-6 py-4 text-right font-bold text-rose-600">
                                                    {parseFloat(log.shortage_quantity).toFixed(2)} {log.unit}
                                                </td>
                                                <td className="px-6 py-4 text-right font-bold text-slate-800">
                                                    {parseFloat(log.resulting_quantity).toFixed(2)} {log.unit}
                                                </td>
                                                <td className="px-6 py-4 text-center">
                                                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider ${
                                                        log.policy === 'allow_negative_with_warning'
                                                            ? 'bg-amber-50 text-amber-700 border border-amber-100'
                                                            : 'bg-indigo-50 text-indigo-700 border border-indigo-100'
                                                    }`}>
                                                        {log.policy === 'allow_negative_with_warning' ? 'Soft Negative' : 'Strict Block'}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-slate-500 font-medium">
                                                    {log.reason || <span className="italic text-slate-300">No reason logged</span>}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="11" className="px-6 py-20 text-center">
                                                <div className="flex flex-col items-center justify-center">
                                                    <div className="p-4 bg-slate-50 rounded-full mb-3 text-slate-300">
                                                        <AlertTriangle size={36} />
                                                    </div>
                                                    <h3 className="text-sm font-bold text-slate-700">No variance records</h3>
                                                    <p className="text-slate-400 text-xs mt-1">
                                                        No stock shortfalls or warning events have been logged.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
