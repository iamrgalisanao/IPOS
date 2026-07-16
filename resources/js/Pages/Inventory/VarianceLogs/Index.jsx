import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { 
    AlertTriangle, 
    Search,
    Download,
    Printer,
    Calendar,
    GitBranch,
    RefreshCw
} from 'lucide-react';

export default function Index({ auth, logs, branches, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [branchId, setBranchId] = useState(filters.branch_id || '');
    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');
    const [status, setStatus] = useState(filters.status || '');
    const [category, setCategory] = useState(filters.category || '');
    const [policy, setPolicy] = useState(filters.policy || '');

    const handleFilterChange = (key, value) => {
        const queryParams = {
            search: searchQuery,
            branch_id: branchId,
            start_date: startDate,
            end_date: endDate,
            status,
            category,
            policy,
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
        setStatus('');
        setCategory('');
        setPolicy('');
        router.get(route('inventory.reports.variance-logs.index'));
    };

    const handleExport = () => {
        const queryParams = new URLSearchParams({
            search: searchQuery,
            branch_id: branchId,
            start_date: startDate,
            end_date: endDate,
            status,
            category,
            policy
        }).toString();
        
        window.location.href = `${route('inventory.reports.variance-logs.export')}?${queryParams}`;
    };

    const handlePrint = () => {
        window.print();
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Negative Stock Exceptions</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Operational queue for policy-permitted stock exceptions and resolution evidence.</p>
                    </div>
                    <div className="flex items-center gap-2 print:hidden">
                        <button
                            onClick={handlePrint}
                            className="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 text-slate-700 rounded-xl font-bold text-sm hover:bg-slate-50 transition-all"
                        >
                            <Printer size={16} />
                            Print View
                        </button>
                        <button
                            onClick={handleExport}
                            className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
                        >
                            <Download size={18} />
                            Export CSV
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Negative Stock Exceptions" />

            <div className="py-8 print:py-0">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="hidden print:block mb-4 border-b border-slate-300 pb-3">
                        <h1 className="text-lg font-black text-slate-900">Negative Stock Exceptions</h1>
                        <p className="text-xs text-slate-500 mt-1">
                            Generated {new Date().toLocaleString()} • This report reflects current filter scope.
                        </p>
                    </div>

                    {/* Filter Bar */}
                    <div className="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm mb-8 space-y-4 print:hidden">
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

                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <select
                                className="block w-full py-2.5 bg-slate-50 border-none rounded-2xl text-sm font-medium text-slate-600 focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all"
                                value={status}
                                onChange={(e) => {
                                    setStatus(e.target.value);
                                    handleFilterChange('status', e.target.value);
                                }}
                            >
                                <option value="">All Statuses</option>
                                <option value="open">Open</option>
                                <option value="acknowledged">Acknowledged</option>
                                <option value="action_planned">Action Planned</option>
                                <option value="linked_to_correction">Linked</option>
                                <option value="resolved">Resolved</option>
                                <option value="voided">Voided</option>
                                <option value="dismissed">Dismissed</option>
                            </select>

                            <select
                                className="block w-full py-2.5 bg-slate-50 border-none rounded-2xl text-sm font-medium text-slate-600 focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all"
                                value={category}
                                onChange={(e) => {
                                    setCategory(e.target.value);
                                    handleFilterChange('category', e.target.value);
                                }}
                            >
                                <option value="">All Categories</option>
                                <option value="negative_stock">Negative Stock</option>
                                <option value="physical_count">Physical Count</option>
                                <option value="system_reconciliation">System Reconciliation</option>
                                <option value="configuration">Configuration</option>
                            </select>

                            <select
                                className="block w-full py-2.5 bg-slate-50 border-none rounded-2xl text-sm font-medium text-slate-600 focus:ring-2 focus:ring-indigo-500/20 focus:bg-white transition-all"
                                value={policy}
                                onChange={(e) => {
                                    setPolicy(e.target.value);
                                    handleFilterChange('policy', e.target.value);
                                }}
                            >
                                <option value="">All Policies</option>
                                <option value="allow_negative_with_warning">Soft Negative</option>
                                <option value="strict_block">Strict Block</option>
                            </select>
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
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden print:rounded-none print:border-slate-300 print:shadow-none">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr className="bg-slate-50/50">
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Date/Time</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Branch</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Status</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Sale #</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Parent Product</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Ingredient / Product Short</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-right">Required</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-right">Available Before</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-right">Shortage</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-right">Exposure</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-right">Resulting</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-center">Movement</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-center">Links</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400 text-center font-bold">Policy</th>
                                        <th className="px-6 py-4 font-black uppercase tracking-widest text-slate-400">Reason</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {logs.data.length > 0 ? (
                                        logs.data.map((log) => (
                                            <tr key={log.id} className="hover:bg-slate-50/50 transition-colors group print:break-inside-avoid">
                                                <td className="px-6 py-4 whitespace-nowrap text-slate-500 font-medium">
                                                    {new Date(log.created_at).toLocaleString()}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="flex items-center gap-2">
                                                        <GitBranch size={14} className="text-slate-400" />
                                                        <span className="font-bold text-slate-700">{log.branch?.name}</span>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600 border border-slate-200">
                                                        {(log.current_status || 'open').replaceAll('_', ' ')}
                                                    </span>
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
                                                    {parseFloat(log.incremental_shortage_quantity || log.shortage_quantity).toFixed(2)} {log.unit}
                                                </td>
                                                <td className="px-6 py-4 text-right font-bold text-rose-700">
                                                    {parseFloat(log.resulting_negative_quantity || Math.abs(Math.min(log.resulting_quantity, 0))).toFixed(2)} {log.unit}
                                                </td>
                                                <td className="px-6 py-4 text-right font-bold text-slate-800">
                                                    {parseFloat(log.resulting_quantity).toFixed(2)} {log.unit}
                                                </td>
                                                <td className="px-6 py-4 text-center font-bold text-slate-600">
                                                    {log.movement_sequence || log.movement?.movement_sequence || '-'}
                                                </td>
                                                <td className="px-6 py-4 text-center font-bold text-slate-600">
                                                    {log.correction_links_count ?? log.correction_links?.length ?? 0}
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
                                            <td colSpan="15" className="px-6 py-20 text-center">
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

                    <div className="hidden print:flex mt-3 justify-between text-[10px] text-slate-500">
                        <span>Rows: {logs.data.length}</span>
                        <span>IPOS Inventory Reports</span>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
