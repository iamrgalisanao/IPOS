import React, { useState, useEffect, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import {
    RefreshCw,
    Search,
    X,
    CheckCircle,
    AlertCircle,
    Clock,
    Activity,
    FileText,
    Database,
    ArrowRight,
    Sliders,
    Filter,
    Calendar,
    DollarSign,
    Layers
} from 'lucide-react';
import axios from 'axios';

export default function Index({ auth, branches, terminals, filters }) {
    const [selectedBranchId, setSelectedBranchId] = useState(filters.branch_id || '');
    const [selectedTerminalId, setSelectedTerminalId] = useState(filters.sales_machine_profile_id || '');
    const [selectedStatus, setSelectedStatus] = useState(filters.status || '');
    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');
    const [searchQuery, setSearchQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [adjustments, setAdjustments] = useState([]);
    const [selectedAdjustment, setSelectedAdjustment] = useState(null);
    const [isDetailModalOpen, setIsDetailModalOpen] = useState(false);

    // Fetch adjustments data
    const fetchData = async () => {
        setLoading(true);
        try {
            const response = await axios.get(route('admin.prior-period-adjustments.data'), {
                params: {
                    branch_id: selectedBranchId,
                    sales_machine_profile_id: selectedTerminalId,
                    status: selectedStatus,
                    start_date: startDate,
                    end_date: endDate
                }
            });
            setAdjustments(response.data.adjustments || []);
        } catch (error) {
            console.error('Failed to fetch prior period adjustments:', error);
        } finally {
            setLoading(false);
        }
    };

    // Trigger fetch on filter changes
    useEffect(() => {
        fetchData();
    }, [selectedBranchId, selectedTerminalId, selectedStatus, startDate, endDate]);

    // Handle local search
    const filteredAdjustments = useMemo(() => {
        return adjustments.filter(adj => {
            const terminalCode = adj.sales_machine_profile?.profile_code || '';
            const terminalName = adj.sales_machine_profile?.terminal_identifier || '';
            const saleNum = adj.sale?.sale_number || '';
            const sequenceNum = adj.offline_sales_import?.offline_sequence_number || '';
            const reason = adj.adjustment_reason || '';

            const matchesSearch =
                terminalCode.toLowerCase().includes(searchQuery.toLowerCase()) ||
                terminalName.toLowerCase().includes(searchQuery.toLowerCase()) ||
                saleNum.toLowerCase().includes(searchQuery.toLowerCase()) ||
                sequenceNum.toLowerCase().includes(searchQuery.toLowerCase()) ||
                reason.toLowerCase().includes(searchQuery.toLowerCase());
            return matchesSearch;
        });
    }, [adjustments, searchQuery]);

    // Aggregate statistics
    const stats = useMemo(() => {
        const totalCount = adjustments.length;
        const totalGross = adjustments.reduce((acc, adj) => acc + parseFloat(adj.gross_amount || 0), 0);
        const totalVat = adjustments.reduce((acc, adj) => acc + parseFloat(adj.vat_amount || 0), 0);
        const totalNet = adjustments.reduce((acc, adj) => acc + parseFloat(adj.net_amount || 0), 0);

        return {
            totalCount,
            totalGross: totalGross.toFixed(2),
            totalVat: totalVat.toFixed(2),
            totalNet: totalNet.toFixed(2)
        };
    }, [adjustments]);

    // Filter terminals based on selected branch
    const filteredTerminals = useMemo(() => {
        if (!selectedBranchId) return terminals;
        return terminals.filter(t => t.branch_id === selectedBranchId);
    }, [terminals, selectedBranchId]);

    const openDetailModal = (adj) => {
        setSelectedAdjustment(adj);
        setIsDetailModalOpen(true);
    };

    const closeDetailModal = () => {
        setIsDetailModalOpen(false);
        setSelectedAdjustment(null);
    };

    // Format timestamps nicely
    const formatDateTime = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleString();
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString();
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight flex items-center gap-2">
                            <Layers className="text-indigo-600" size={24} />
                            Prior Period Adjustments Ledger
                        </h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">
                            Observe and audit late-synced offline transactions posted into the current open reporting settlement periods.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <button
                            onClick={fetchData}
                            disabled={loading}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all shadow-md shadow-indigo-500/10 active:scale-95 disabled:opacity-50"
                        >
                            <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
                            Refresh Now
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Prior Period Adjustments" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                    
                    {/* Stats Metrics Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        
                        <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-slate-200 transition-all">
                            <div className="space-y-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Adjustment Entries</span>
                                <div className="text-3xl font-black text-slate-800">{stats.totalCount}</div>
                            </div>
                            <div className="p-4 bg-indigo-50 text-indigo-600 rounded-2xl group-hover:scale-110 transition-transform">
                                <Database size={24} />
                            </div>
                        </div>

                        <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-slate-200 transition-all">
                            <div className="space-y-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Adjusted Gross Sales</span>
                                <div className="text-3xl font-black text-slate-800">₱{stats.totalGross}</div>
                            </div>
                            <div className="p-4 bg-emerald-50 text-emerald-600 rounded-2xl group-hover:scale-110 transition-transform">
                                <DollarSign size={24} />
                            </div>
                        </div>

                        <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-slate-200 transition-all">
                            <div className="space-y-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Adjusted Net Sales</span>
                                <div className="text-3xl font-black text-slate-800">₱{stats.totalNet}</div>
                            </div>
                            <div className="p-4 bg-indigo-50 text-indigo-600 rounded-2xl group-hover:scale-110 transition-transform">
                                <DollarSign size={24} />
                            </div>
                        </div>

                        <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-slate-200 transition-all">
                            <div className="space-y-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Adjusted VAT Total</span>
                                <div className="text-3xl font-black text-indigo-600">₱{stats.totalVat}</div>
                            </div>
                            <div className="p-4 bg-purple-50 text-purple-600 rounded-2xl group-hover:scale-110 transition-transform">
                                <DollarSign size={24} />
                            </div>
                        </div>

                    </div>

                    {/* Filter and Search Bar */}
                    <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm space-y-4">
                        <div className="flex flex-col md:flex-row items-center justify-between gap-4">
                            <div className="relative w-full md:max-w-md">
                                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <Search size={16} />
                                </div>
                                <input
                                    type="text"
                                    placeholder="Search by Terminal, Sale #, sequence..."
                                    className="block w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-inner"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                />
                            </div>
                            <div className="flex items-center gap-2 text-xs font-bold text-slate-500 uppercase tracking-wider">
                                <Filter size={14} />
                                Filters Applied
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 pt-2 border-t border-slate-100">
                            {/* Branch Filter */}
                            <div>
                                <label className="block text-xs font-bold text-slate-500 mb-1">Branch</label>
                                <select
                                    className="block w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3 text-xs font-bold text-slate-700 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                    value={selectedBranchId}
                                    onChange={(e) => {
                                        setSelectedBranchId(e.target.value);
                                        setSelectedTerminalId('');
                                    }}
                                >
                                    <option value="">All Branches</option>
                                    {branches.map(b => (
                                        <option key={b.id} value={b.id}>{b.name}</option>
                                    ))}
                                </select>
                            </div>

                            {/* Terminal Filter */}
                            <div>
                                <label className="block text-xs font-bold text-slate-500 mb-1">Terminal</label>
                                <select
                                    className="block w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3 text-xs font-bold text-slate-700 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                    value={selectedTerminalId}
                                    onChange={(e) => setSelectedTerminalId(e.target.value)}
                                >
                                    <option value="">All Terminals</option>
                                    {filteredTerminals.map(t => (
                                        <option key={t.id} value={t.id}>{t.terminal_identifier || t.profile_code}</option>
                                    ))}
                                </select>
                            </div>

                            {/* Status Filter */}
                            <div>
                                <label className="block text-xs font-bold text-slate-500 mb-1">Status</label>
                                <select
                                    className="block w-full bg-slate-50 border border-slate-200 rounded-xl py-2 px-3 text-xs font-bold text-slate-700 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                    value={selectedStatus}
                                    onChange={(e) => setSelectedStatus(e.target.value)}
                                >
                                    <option value="">All Statuses</option>
                                    <option value="logged">Logged</option>
                                    <option value="posted">Posted</option>
                                </select>
                            </div>

                            {/* Start Date */}
                            <div>
                                <label className="block text-xs font-bold text-slate-500 mb-1">Start Date</label>
                                <input
                                    type="date"
                                    className="block w-full bg-slate-50 border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-bold text-slate-700 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                />
                            </div>

                            {/* End Date */}
                            <div>
                                <label className="block text-xs font-bold text-slate-500 mb-1">End Date</label>
                                <input
                                    type="date"
                                    className="block w-full bg-slate-50 border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-bold text-slate-700 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                />
                            </div>
                        </div>
                    </div>

                    {/* Main Adjustments Table */}
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div className="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                            <h3 className="font-extrabold text-sm text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                <Activity size={16} className="text-slate-400" />
                                Adjustment History
                            </h3>
                            <span className="px-2.5 py-1 bg-indigo-50 text-indigo-700 rounded-lg text-xs font-black">
                                {filteredAdjustments.length} records
                            </span>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/30 text-slate-400">
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Post Date</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Terminal / Branch</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Original Period</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Adjusted Period</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-right">Gross Amount</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-center">Status</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {filteredAdjustments.length > 0 ? (
                                        filteredAdjustments.map((adj) => (
                                            <tr key={adj.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-col text-slate-600 text-xs font-bold">
                                                        <span>{formatDateTime(adj.reconciled_at)}</span>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-col">
                                                        <span className="font-extrabold text-slate-800 text-sm group-hover:text-indigo-600 transition-colors">
                                                            {adj.sales_machine_profile?.terminal_identifier || adj.sales_machine_profile?.profile_code}
                                                        </span>
                                                        <span className="text-[10px] font-black text-slate-400 uppercase tracking-wider mt-0.5">
                                                            Import Code: {adj.offline_sales_import?.offline_sequence_number}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-col">
                                                        <span className="font-extrabold text-slate-700 text-xs">
                                                            Z-Read Seq: #{adj.original_register_z_read?.z_read_sequence || 'N/A'}
                                                        </span>
                                                        <span className="text-[10px] text-slate-400 font-medium mt-0.5">
                                                            Date: {formatDate(adj.original_business_date)}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    {adj.adjusted_into_settlement_period ? (
                                                        <div className="flex flex-col text-xs font-semibold text-slate-600">
                                                            <span>
                                                                {formatDate(adj.adjusted_into_settlement_period.period_start_at)}
                                                            </span>
                                                            <span className="text-[10px] text-slate-400 font-medium">
                                                                Status: {adj.adjusted_into_settlement_period.status}
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <span className="text-slate-400 text-xs font-bold">None (now())</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-right">
                                                    <span className="font-mono text-slate-800 font-bold text-sm">
                                                        ₱{parseFloat(adj.gross_amount).toFixed(2)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-center">
                                                    <span className={`inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold ${
                                                        adj.status === 'posted'
                                                            ? 'bg-emerald-50 text-emerald-600 border border-emerald-100'
                                                            : 'bg-amber-50 text-amber-600 border border-amber-100'
                                                    }`}>
                                                        {adj.status === 'posted' ? (
                                                            <>
                                                                <CheckCircle size={10} />
                                                                Posted
                                                            </>
                                                        ) : (
                                                            <>
                                                                <Clock size={10} />
                                                                Logged
                                                            </>
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-right">
                                                    <button
                                                        onClick={() => openDetailModal(adj)}
                                                        className="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-100 hover:bg-indigo-50 text-slate-600 hover:text-indigo-600 rounded-xl text-xs font-black transition-all"
                                                    >
                                                        Details
                                                        <ArrowRight size={12} />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="7" className="px-6 py-16 text-center text-slate-500 font-bold">
                                                No adjustment entries found.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>

            {/* Inspect Detail Modal */}
            <Modal show={isDetailModalOpen} onClose={closeDetailModal} maxWidth="2xl">
                {selectedAdjustment && (
                    <div className="p-8">
                        <div className="flex items-center justify-between mb-6 border-b border-slate-100 pb-4">
                            <div>
                                <h3 className="text-xl font-black text-slate-800 flex items-center gap-2">
                                    <FileText className="text-indigo-600" size={20} />
                                    Prior Period Adjustment Details
                                </h3>
                                <p className="text-xs text-slate-400 font-black uppercase tracking-wider mt-1">
                                    ID: {selectedAdjustment.id}
                                </p>
                            </div>
                            <button
                                onClick={closeDetailModal}
                                className="p-2 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-50 transition-all"
                            >
                                <X size={20} />
                            </button>
                        </div>

                        <div className="space-y-6">
                            
                            {/* Terminal & Branch info */}
                            <div className="grid grid-cols-2 gap-4">
                                <div className="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                    <div className="text-[10px] font-black uppercase tracking-widest text-slate-400 font-mono">Terminal Profile</div>
                                    <div className="font-extrabold text-slate-700 text-sm mt-1">
                                        {selectedAdjustment.sales_machine_profile?.terminal_identifier || selectedAdjustment.sales_machine_profile?.profile_code}
                                    </div>
                                    <div className="text-xs text-slate-400 mt-0.5">Code: {selectedAdjustment.sales_machine_profile?.profile_code}</div>
                                </div>
                                <div className="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                    <div className="text-[10px] font-black uppercase tracking-widest text-slate-400 font-mono">Reconciled At</div>
                                    <div className="font-extrabold text-slate-700 text-sm mt-1">{formatDateTime(selectedAdjustment.reconciled_at)}</div>
                                    <div className="text-xs text-slate-400 mt-0.5">Import reference: {selectedAdjustment.offline_sales_import?.offline_sequence_number}</div>
                                </div>
                            </div>

                            {/* Adjustment breakdown */}
                            <div className="bg-slate-50 p-5 rounded-2xl border border-slate-100 space-y-4">
                                <h4 className="text-xs font-black uppercase tracking-wider text-slate-400 border-b border-slate-200/60 pb-2">Reconciliation Period Shift</h4>
                                
                                <div className="grid grid-cols-2 gap-6">
                                    <div>
                                        <div className="text-[10px] font-black uppercase text-slate-400">Original Closed Period</div>
                                        <div className="text-xs font-bold text-slate-700 mt-1">Z-Read Sequence: #{selectedAdjustment.original_register_z_read?.z_read_sequence || 'N/A'}</div>
                                        <div className="text-xs text-slate-600 mt-0.5">Business Date: {formatDate(selectedAdjustment.original_business_date)}</div>
                                        <div className="text-xs text-slate-500 mt-0.5">Transaction Time: {formatDateTime(selectedAdjustment.original_transaction_at)}</div>
                                    </div>
                                    <div>
                                        <div className="text-[10px] font-black uppercase text-indigo-500">Adjusted Reporting Period</div>
                                        {selectedAdjustment.adjusted_into_settlement_period ? (
                                            <>
                                                <div className="text-xs font-bold text-slate-700 mt-1">
                                                    Start: {formatDateTime(selectedAdjustment.adjusted_into_settlement_period.period_start_at)}
                                                </div>
                                                <div className="text-xs text-slate-600 mt-0.5">
                                                    End: {formatDateTime(selectedAdjustment.adjusted_into_settlement_period.period_end_at)}
                                                </div>
                                                <div className="text-[10px] inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 bg-indigo-50 text-indigo-600 font-bold rounded">
                                                    Settlement status: {selectedAdjustment.adjusted_into_settlement_period.status}
                                                </div>
                                            </>
                                        ) : (
                                            <div className="text-xs text-slate-600 font-bold mt-1">None (reporting_basis_at shifted to now())</div>
                                        )}
                                    </div>
                                </div>

                                <div className="pt-2 border-t border-slate-200 flex items-center justify-between text-xs font-bold">
                                    <span className="text-slate-500">New Reporting Basis Timestamp</span>
                                    <span className="text-indigo-600 font-extrabold">{formatDateTime(selectedAdjustment.reporting_basis_at)}</span>
                                </div>
                            </div>

                            {/* Financial breakdown */}
                            <div>
                                <h4 className="text-xs font-black uppercase tracking-wider text-slate-400 mb-3">Adjusted Financial Amounts</h4>
                                <div className="grid grid-cols-3 gap-4 text-center">
                                    <div className="p-3 bg-slate-100/50 rounded-xl border border-slate-200/50">
                                        <div className="text-xs text-slate-400 font-black uppercase">Gross Amount</div>
                                        <div className="text-lg font-black text-slate-800 mt-1">₱{parseFloat(selectedAdjustment.gross_amount).toFixed(2)}</div>
                                    </div>
                                    <div className="p-3 bg-slate-100/50 rounded-xl border border-slate-200/50">
                                        <div className="text-xs text-slate-400 font-black uppercase">Net Amount</div>
                                        <div className="text-lg font-black text-slate-800 mt-1">₱{parseFloat(selectedAdjustment.net_amount).toFixed(2)}</div>
                                    </div>
                                    <div className="p-3 bg-purple-50 rounded-xl border border-purple-100">
                                        <div className="text-xs text-purple-500 font-black uppercase">VAT Amount</div>
                                        <div className="text-lg font-black text-purple-700 mt-1">₱{parseFloat(selectedAdjustment.vat_amount).toFixed(2)}</div>
                                    </div>
                                </div>
                            </div>

                            {/* Reason & Meta */}
                            <div className="p-4 bg-slate-50 rounded-xl border border-slate-100 space-y-2">
                                <div className="text-[10px] font-black uppercase tracking-widest text-slate-400">Adjustment Action Details</div>
                                <div className="text-xs text-slate-700 font-bold mt-1">Reason: <span className="font-normal">{selectedAdjustment.adjustment_reason || 'N/A'}</span></div>
                                <div className="text-xs text-slate-700 font-bold">Associated Sale: <span className="font-mono text-indigo-600">{selectedAdjustment.sale?.sale_number || 'N/A'}</span></div>
                                <div className="text-xs text-slate-700 font-bold">Status: <span className="uppercase text-emerald-600 font-black">{selectedAdjustment.status}</span></div>
                            </div>

                        </div>

                        <div className="mt-8 flex justify-end">
                            <SecondaryButton onClick={closeDetailModal} className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs border-none bg-slate-100 hover:bg-slate-200">
                                Close Details
                            </SecondaryButton>
                        </div>
                    </div>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
