import React, { useState, useEffect, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import {
    Monitor,
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
    Wifi,
    WifiOff,
    CheckCircle2,
    Sliders,
    Filter
} from 'lucide-react';
import axios from 'axios';

export default function Index({ auth, branches, filters }) {
    const [selectedBranchId, setSelectedBranchId] = useState(filters.branch_id || '');
    const [searchQuery, setSearchQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [terminals, setTerminals] = useState([]);
    const [recentSyncs, setRecentSyncs] = useState([]);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [selectedTerminal, setSelectedTerminal] = useState(null);
    const [isDetailModalOpen, setIsDetailModalOpen] = useState(false);

    // Fetch dashboard data
    const fetchData = async () => {
        setLoading(true);
        try {
            const response = await axios.get(route('admin.terminal-sync-monitor.data'), {
                params: { branch_id: selectedBranchId }
            });
            setTerminals(response.data.terminals || []);
            setRecentSyncs(response.data.recent_syncs || []);
        } catch (error) {
            console.error('Failed to fetch monitor data:', error);
        } finally {
            setLoading(false);
        }
    };

    // Initial load and auto-refresh setup
    useEffect(() => {
        fetchData();
    }, [selectedBranchId]);

    useEffect(() => {
        if (!autoRefresh) return;
        const interval = setInterval(() => {
            fetchData();
        }, 10000); // 10s auto-refresh
        return () => clearInterval(interval);
    }, [autoRefresh, selectedBranchId]);

    // Handle search & filtering
    const filteredTerminals = useMemo(() => {
        return terminals.filter(t => {
            const matchesSearch = 
                t.terminal_identifier.toLowerCase().includes(searchQuery.toLowerCase()) ||
                t.profile_code.toLowerCase().includes(searchQuery.toLowerCase()) ||
                t.branch.name.toLowerCase().includes(searchQuery.toLowerCase());
            return matchesSearch;
        });
    }, [terminals, searchQuery]);

    // Aggregate statistics
    const stats = useMemo(() => {
        const total = terminals.length;
        const synced = terminals.filter(t => t.status === 'synced').length;
        const failed = terminals.filter(t => t.status === 'failed').length;
        const pending = terminals.filter(t => t.status === 'pending').length;
        
        let totalPendingImports = 0;
        let totalFailedImports = 0;
        terminals.forEach(t => {
            totalPendingImports += t.pending_count || 0;
            totalFailedImports += t.failed_count || 0;
        });

        return { total, synced, failed, pending, totalPendingImports, totalFailedImports };
    }, [terminals]);

    // Open detail inspect modal
    const openDetailModal = (terminal) => {
        setSelectedTerminal(terminal);
        setIsDetailModalOpen(true);
    };

    const closeDetailModal = () => {
        setIsDetailModalOpen(false);
        setSelectedTerminal(null);
    };

    // Format timestamps nicely
    const formatDateTime = (dateString) => {
        if (!dateString) return 'Never';
        const date = new Date(dateString);
        return date.toLocaleString();
    };

    const getStatusBadge = (status) => {
        switch (status) {
            case 'synced':
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-600 border border-emerald-100 shadow-sm shadow-emerald-500/5">
                        <CheckCircle size={12} className="text-emerald-500" />
                        Synced
                    </span>
                );
            case 'pending':
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-600 border border-amber-100 animate-pulse shadow-sm shadow-amber-500/5">
                        <Clock size={12} className="text-amber-500" />
                        Pending Sync
                    </span>
                );
            case 'failed':
            default:
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-600 border border-rose-100 shadow-sm shadow-rose-500/5">
                        <AlertCircle size={12} className="text-rose-500" />
                        Error/Conflict
                    </span>
                );
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight flex items-center gap-2">
                            <Monitor className="text-indigo-600" size={24} />
                            POS Terminal Sync Diagnostics
                        </h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">
                            Real-time observability, checksum validations, and sync health monitoring for all active registers.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <label className="flex items-center gap-2 cursor-pointer bg-white px-4 py-2 border border-slate-200 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50 transition-all select-none shadow-sm">
                            <input 
                                type="checkbox" 
                                checked={autoRefresh} 
                                onChange={(e) => setAutoRefresh(e.target.checked)}
                                className="rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"
                            />
                            Auto Refresh (10s)
                        </label>
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
            <Head title="Terminal Sync Monitor" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                    
                    {/* Stats Metrics Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        
                        <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-slate-200 transition-all">
                            <div className="space-y-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Total Terminals</span>
                                <div className="text-3xl font-black text-slate-800">{stats.total}</div>
                            </div>
                            <div className="p-4 bg-indigo-50 text-indigo-600 rounded-2xl group-hover:scale-110 transition-transform">
                                <Monitor size={24} />
                            </div>
                        </div>

                        <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-slate-200 transition-all">
                            <div className="space-y-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Synced (No Conflicts)</span>
                                <div className="text-3xl font-black text-emerald-600">{stats.synced}</div>
                            </div>
                            <div className="p-4 bg-emerald-50 text-emerald-600 rounded-2xl group-hover:scale-110 transition-transform">
                                <CheckCircle2 size={24} />
                            </div>
                        </div>

                        <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-slate-200 transition-all">
                            <div className="space-y-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Pending Sync Items</span>
                                <div className="text-3xl font-black text-amber-600">{stats.totalPendingImports}</div>
                            </div>
                            <div className="p-4 bg-amber-50 text-amber-600 rounded-2xl group-hover:scale-110 transition-transform">
                                <Clock size={24} />
                            </div>
                        </div>

                        <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between relative overflow-hidden group hover:border-slate-200 transition-all">
                            <div className="space-y-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">Failed / Conflict Sales</span>
                                <div className="text-3xl font-black text-rose-600">{stats.totalFailedImports}</div>
                            </div>
                            <div className="p-4 bg-rose-50 text-rose-600 rounded-2xl group-hover:scale-110 transition-transform">
                                <AlertCircle size={24} />
                            </div>
                        </div>

                    </div>

                    {/* Filter and Search Bar */}
                    <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm flex flex-col md:flex-row items-center justify-between gap-4">
                        <div className="relative w-full md:max-w-md">
                            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                <Search size={16} />
                            </div>
                            <input
                                type="text"
                                placeholder="Search by Terminal ID, Profile, or Branch..."
                                className="block w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-inner"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                        </div>
                        <div className="flex items-center gap-3 w-full md:w-auto">
                            <div className="flex items-center gap-2 text-xs font-bold text-slate-400 uppercase tracking-wider">
                                <Filter size={14} />
                                Filter Branch:
                            </div>
                            <select
                                className="block w-full md:w-48 bg-slate-50 border border-slate-200 rounded-xl py-2 px-3 text-xs font-extrabold text-slate-700 focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                value={selectedBranchId}
                                onChange={(e) => setSelectedBranchId(e.target.value)}
                            >
                                <option value="">All Branches</option>
                                {branches.map(b => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {/* Main Terminals Table */}
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div className="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                            <div>
                                <h3 className="font-extrabold text-sm text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                    <Activity size={16} className="text-slate-400" />
                                    Active Terminals Status
                                </h3>
                            </div>
                            <span className="px-2.5 py-1 bg-slate-100 text-slate-500 rounded-lg text-xs font-black">
                                {filteredTerminals.length} found
                            </span>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/30 text-slate-400">
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Terminal</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Branch</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Status</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Last Synced</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-center">Pending Items</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-center">Failed Items</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-center">Posted Items</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {filteredTerminals.length > 0 ? (
                                        filteredTerminals.map((terminal) => (
                                            <tr key={terminal.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-col">
                                                        <span className="font-extrabold text-slate-800 text-sm group-hover:text-indigo-600 transition-colors">
                                                            {terminal.terminal_identifier}
                                                        </span>
                                                        <span className="text-[10px] font-black text-slate-400 uppercase tracking-wider mt-0.5">
                                                            Code: {terminal.profile_code}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-col">
                                                        <span className="font-extrabold text-slate-700 text-sm">{terminal.branch.name}</span>
                                                        <span className="text-xs text-slate-400 font-medium">{terminal.branch.branch_code}</span>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    {getStatusBadge(terminal.status)}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="flex items-center gap-2 text-slate-600 text-xs font-bold">
                                                        <Clock size={12} className="text-slate-400" />
                                                        {formatDateTime(terminal.last_sync_at)}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 text-center">
                                                    {terminal.pending_count > 0 ? (
                                                        <span className="inline-flex items-center justify-center px-2.5 py-1 rounded-full text-xs font-black bg-amber-100 text-amber-700">
                                                            {terminal.pending_count}
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400 font-bold text-xs">-</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-center">
                                                    {terminal.failed_count > 0 ? (
                                                        <span className="inline-flex items-center justify-center px-2.5 py-1 rounded-full text-xs font-black bg-rose-100 text-rose-700">
                                                            {terminal.failed_count}
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400 font-bold text-xs">-</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-center">
                                                    {terminal.posted_count > 0 ? (
                                                        <span className="inline-flex items-center justify-center px-2.5 py-1 rounded-full text-xs font-black bg-emerald-100 text-emerald-700">
                                                            {terminal.posted_count}
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400 font-bold text-xs">-</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-right">
                                                    <button
                                                        onClick={() => openDetailModal(terminal)}
                                                        className="inline-flex items-center gap-1 px-3 py-1.5 bg-slate-100 hover:bg-indigo-50 text-slate-600 hover:text-indigo-600 rounded-xl text-xs font-black transition-all"
                                                    >
                                                        Inspect Logs
                                                        <ArrowRight size={12} />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="8" className="px-6 py-16 text-center text-slate-500">
                                                No active terminals configured or matching filters.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Recent Sync Batches Table */}
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div className="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                            <h3 className="font-extrabold text-sm text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                <Database size={16} className="text-slate-400" />
                                Recent Terminal Sync Actions (Audit Logs)
                            </h3>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/30 text-slate-400">
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Batch Reference</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Terminal</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Branch</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Status</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-center">Imported</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-center">Accepted</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-center">Failed</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Completed At</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {recentSyncs.length > 0 ? (
                                        recentSyncs.map((batch) => (
                                            <tr key={batch.id} className="hover:bg-slate-50/30 transition-colors text-slate-600 text-xs font-bold">
                                                <td className="px-6 py-4">
                                                    <span className="font-mono bg-slate-100 px-2 py-1 rounded text-slate-700 text-xs">
                                                        {batch.batch_reference || batch.id.slice(0, 8)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-slate-800 font-extrabold">
                                                    {batch.terminal_identifier}
                                                </td>
                                                <td className="px-6 py-4 text-slate-700 font-medium">
                                                    {batch.branch_name}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase border ${
                                                        batch.status === 'completed'
                                                            ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                                            : batch.status === 'processing' || batch.status === 'received'
                                                            ? 'bg-amber-50 text-amber-600 border-amber-100'
                                                            : 'bg-rose-50 text-rose-600 border-rose-100'
                                                    }`}>
                                                        {batch.status}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-center text-slate-800 font-black">{batch.submitted_import_count}</td>
                                                <td className="px-6 py-4 text-center text-emerald-600 font-black">{batch.processed_count}</td>
                                                <td className="px-6 py-4 text-center text-rose-600 font-black">{batch.failed_count}</td>
                                                <td className="px-6 py-4 font-normal text-slate-500">
                                                    {formatDateTime(batch.sync_completed_at)}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="8" className="px-6 py-10 text-center text-slate-400">
                                                No sync batches recorded yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>

            {/* Inspect Terminal Sync Details Modal */}
            <Modal show={isDetailModalOpen} onClose={closeDetailModal} maxWidth="2xl">
                {selectedTerminal && (
                    <div className="p-8">
                        <div className="flex items-center justify-between mb-6 border-b border-slate-100 pb-4">
                            <div>
                                <h3 className="text-xl font-black text-slate-800 flex items-center gap-2">
                                    <Monitor className="text-indigo-600" size={20} />
                                    Terminal diagnostics
                                </h3>
                                <p className="text-xs text-slate-400 font-black uppercase tracking-wider mt-1">
                                    {selectedTerminal.terminal_identifier} • Code: {selectedTerminal.profile_code}
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
                            
                            {/* General details grid */}
                            <div className="grid grid-cols-2 gap-4">
                                <div className="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                    <div className="text-[10px] font-black uppercase tracking-widest text-slate-400">Branch Context</div>
                                    <div className="font-extrabold text-slate-700 text-sm mt-1">{selectedTerminal.branch.name}</div>
                                    <div className="text-xs text-slate-400 mt-0.5">Code: {selectedTerminal.branch.branch_code}</div>
                                </div>
                                <div className="p-4 bg-slate-50 rounded-2xl border border-slate-100">
                                    <div className="text-[10px] font-black uppercase tracking-widest text-slate-400">Last Sync Time</div>
                                    <div className="font-extrabold text-slate-700 text-sm mt-1">{formatDateTime(selectedTerminal.last_sync_at)}</div>
                                    <div className="text-xs text-slate-400 mt-0.5">Connection state: Synced</div>
                                </div>
                            </div>

                            {/* Import breakdown status */}
                            <div>
                                <h4 className="text-xs font-black uppercase tracking-wider text-slate-400 mb-3">Sync imports summary</h4>
                                <div className="grid grid-cols-4 gap-4 text-center">
                                    <div className="p-3 bg-slate-100/50 rounded-xl">
                                        <div className="text-xl font-black text-slate-800">{selectedTerminal.posted_count}</div>
                                        <div className="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">Posted</div>
                                    </div>
                                    <div className="p-3 bg-amber-50 rounded-xl border border-amber-100">
                                        <div className="text-xl font-black text-amber-700">{selectedTerminal.pending_count}</div>
                                        <div className="text-[9px] font-black text-amber-500 uppercase tracking-wider mt-1">Pending</div>
                                    </div>
                                    <div className="p-3 bg-rose-50 rounded-xl border border-rose-100">
                                        <div className="text-xl font-black text-rose-700">{selectedTerminal.failed_count}</div>
                                        <div className="text-[9px] font-black text-rose-500 uppercase tracking-wider mt-1">Conflicts</div>
                                    </div>
                                    <div className="p-3 bg-slate-100/50 rounded-xl">
                                        <div className="text-xl font-black text-slate-500">{selectedTerminal.duplicate_count}</div>
                                        <div className="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">Duplicates</div>
                                    </div>
                                </div>
                            </div>

                            {/* Last batch diagnostics */}
                            <div>
                                <h4 className="text-xs font-black uppercase tracking-wider text-slate-400 mb-3">Latest Batch sync metadata</h4>
                                {selectedTerminal.last_batch ? (
                                    <div className="bg-slate-50 p-5 rounded-2xl border border-slate-100 space-y-3">
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="text-slate-400 font-extrabold">Batch ID</span>
                                            <span className="font-mono bg-slate-200 px-2 py-0.5 rounded text-slate-800">{selectedTerminal.last_batch.batch_reference || selectedTerminal.last_batch.id}</span>
                                        </div>
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="text-slate-400 font-extrabold">Processing status</span>
                                            <span className="font-black text-slate-800 uppercase">{selectedTerminal.last_batch.status}</span>
                                        </div>
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="text-slate-400 font-extrabold">Batch Sync Initiated</span>
                                            <span className="text-slate-700 font-bold">{formatDateTime(selectedTerminal.last_batch.sync_started_at)}</span>
                                        </div>
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="text-slate-400 font-extrabold">Batch Sync Completed</span>
                                            <span className="text-slate-700 font-bold">{formatDateTime(selectedTerminal.last_batch.sync_completed_at)}</span>
                                        </div>
                                        <div className="pt-2 border-t border-slate-200/60 flex items-center justify-between text-xs font-bold">
                                            <span className="text-slate-500">Processed stats</span>
                                            <span className="text-slate-700">
                                                {selectedTerminal.last_batch.processed_count} accepted / {selectedTerminal.last_batch.failed_count} failed
                                            </span>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="bg-slate-50 p-4 rounded-xl border border-slate-100 text-center text-xs font-bold text-slate-400">
                                        No recent sync batches recorded for this terminal.
                                    </div>
                                )}
                            </div>

                        </div>

                        <div className="mt-8 flex justify-end">
                            <SecondaryButton onClick={closeDetailModal} className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs border-none bg-slate-100 hover:bg-slate-200">
                                Close Window
                            </SecondaryButton>
                        </div>
                    </div>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
