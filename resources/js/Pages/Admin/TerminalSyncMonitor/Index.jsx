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
    CheckCircle2,
    Sliders,
    Filter,
    ShieldAlert,
    PauseCircle,
    Send,
    Eye
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
    const [imports, setImports] = useState([]);
    const [importsLoading, setImportsLoading] = useState(false);
    const [importSearch, setImportSearch] = useState('');
    const [importStatusFilter, setImportStatusFilter] = useState('conflict,hold,override_approved,server_verified,rejected');
    const [selectedImport, setSelectedImport] = useState(null);
    const [importDetails, setImportDetails] = useState(null);
    const [reviewNotes, setReviewNotes] = useState('');
    const [reviewBusy, setReviewBusy] = useState(false);
    const [reviewNotice, setReviewNotice] = useState(null);

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

    const fetchImports = async () => {
        setImportsLoading(true);
        try {
            const response = await axios.get(route('admin.offline-sync.imports.index'), {
                params: {
                    branch_id: selectedBranchId || undefined,
                    q: importSearch || undefined,
                    status: importStatusFilter || undefined,
                    per_page: 25,
                },
            });
            setImports(response.data.data || []);
        } catch (error) {
            console.error('Failed to fetch offline imports:', error);
            setReviewNotice({
                tone: 'error',
                message: error.response?.data?.message || 'Unable to load offline import review records.',
            });
        } finally {
            setImportsLoading(false);
        }
    };

    // Initial load and auto-refresh setup
    useEffect(() => {
        fetchData();
    }, [selectedBranchId]);

    useEffect(() => {
        fetchImports();
    }, [selectedBranchId, importStatusFilter]);

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

    const openImportDetail = async (record) => {
        setSelectedImport(record);
        setImportDetails(null);
        setReviewNotes(record.review_notes || '');
        setReviewNotice(null);

        try {
            const response = await axios.get(route('admin.offline-sync.imports.show', record.id));
            setImportDetails(response.data);
        } catch (error) {
            console.error('Failed to fetch offline import detail:', error);
            setReviewNotice({
                tone: 'error',
                message: error.response?.data?.message || 'Unable to load offline import detail.',
            });
        }
    };

    const closeImportDetail = () => {
        setSelectedImport(null);
        setImportDetails(null);
        setReviewNotes('');
    };

    const submitReviewAction = async (status) => {
        if (!selectedImport || reviewBusy) return;

        setReviewBusy(true);
        setReviewNotice(null);
        try {
            const response = await axios.patch(route('admin.offline-sync.imports.review', selectedImport.id), {
                status,
                review_notes: reviewNotes || null,
            });
            setReviewNotice({ tone: 'success', message: response.data.message || 'Review status updated.' });
            const updated = response.data.import || { ...selectedImport, status, review_notes: reviewNotes };
            setSelectedImport(updated);
            await openImportDetail(updated);
            await fetchImports();
            await fetchData();
        } catch (error) {
            setReviewNotice({
                tone: 'error',
                message: error.response?.data?.message || 'Review action failed.',
            });
        } finally {
            setReviewBusy(false);
        }
    };

    const postImport = async () => {
        if (!selectedImport || reviewBusy) return;

        setReviewBusy(true);
        setReviewNotice(null);
        try {
            const response = await axios.post(route('admin.offline-sync.imports.post', selectedImport.id));
            setReviewNotice({ tone: 'success', message: response.data.message || 'Offline import posted.' });
            const updated = response.data.import || { ...selectedImport, status: 'posted' };
            setSelectedImport(updated);
            await openImportDetail(updated);
            await fetchImports();
            await fetchData();
        } catch (error) {
            setReviewNotice({
                tone: 'error',
                message: error.response?.data?.message || 'Import is not eligible for posting.',
            });
        } finally {
            setReviewBusy(false);
        }
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

    const getImportStatusBadge = (status) => {
        const classes = {
            posted: 'bg-emerald-50 text-emerald-700 border-emerald-100',
            server_verified: 'bg-emerald-50 text-emerald-700 border-emerald-100',
            override_approved: 'bg-indigo-50 text-indigo-700 border-indigo-100',
            hold: 'bg-amber-50 text-amber-700 border-amber-100',
            conflict: 'bg-rose-50 text-rose-700 border-rose-100',
            rejected: 'bg-rose-50 text-rose-700 border-rose-100',
            duplicate: 'bg-slate-100 text-slate-600 border-slate-200',
            pending: 'bg-amber-50 text-amber-700 border-amber-100',
        };

        return (
            <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-wider ${classes[status] || 'bg-slate-100 text-slate-600 border-slate-200'}`}>
                {String(status || 'unknown').replaceAll('_', ' ')}
            </span>
        );
    };

    const formatMoney = (value) => {
        const amount = Number(value || 0);
        return Number.isFinite(amount) ? `₱${amount.toFixed(2)}` : '₱0.00';
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

                    {/* Offline Import Review Console */}
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div className="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                            <div>
                                <h3 className="font-extrabold text-sm text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                    <ShieldAlert size={16} className="text-rose-500" />
                                    Offline Import Review Console
                                </h3>
                                <p className="mt-1 text-xs font-medium text-slate-500">
                                    Find conflict, held, verified, or rejected imports by sequence, terminal, or batch reference before posting.
                                </p>
                            </div>
                            <div className="flex flex-col sm:flex-row gap-2">
                                <select
                                    value={importStatusFilter}
                                    onChange={(event) => setImportStatusFilter(event.target.value)}
                                    className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500/20"
                                >
                                    <option value="conflict,hold,override_approved,server_verified,rejected">Needs review / post</option>
                                    <option value="conflict">Conflict only</option>
                                    <option value="hold">Hold only</option>
                                    <option value="override_approved">Override approved</option>
                                    <option value="server_verified">Server verified</option>
                                    <option value="posted">Posted</option>
                                    <option value="">All statuses</option>
                                </select>
                                <div className="relative">
                                    <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                    <input
                                        value={importSearch}
                                        onChange={(event) => setImportSearch(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') fetchImports();
                                        }}
                                        placeholder="Sequence, batch, terminal..."
                                        className="w-full min-w-64 rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-3 text-xs font-semibold text-slate-700 shadow-sm focus:border-indigo-500 focus:ring-indigo-500/20"
                                    />
                                </div>
                                <button
                                    onClick={fetchImports}
                                    disabled={importsLoading}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                                >
                                    <RefreshCw size={13} className={importsLoading ? 'animate-spin' : ''} />
                                    Search
                                </button>
                            </div>
                        </div>

                        {reviewNotice && !selectedImport && (
                            <div className={`border-b px-6 py-3 text-sm font-semibold ${
                                reviewNotice.tone === 'success'
                                    ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                    : 'border-rose-100 bg-rose-50 text-rose-700'
                            }`}>
                                {reviewNotice.message}
                            </div>
                        )}

                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/30 text-slate-400">
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Sequence</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Terminal / Branch</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Batch</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Status</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-right">Client / Server</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest">Submitted</th>
                                        <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {imports.length > 0 ? imports.map((record) => (
                                        <tr key={record.id} className="text-xs transition-colors hover:bg-slate-50/50">
                                            <td className="px-6 py-4">
                                                <div className="font-mono text-sm font-black text-indigo-700">{record.offline_sequence_number}</div>
                                                <div className="mt-1 max-w-48 truncate font-mono text-[10px] text-slate-400">
                                                    {record.local_transaction_reference || record.payload_hash}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="font-extrabold text-slate-800">
                                                    {record.terminal?.terminal_identifier || record.terminal?.profile_code || 'Unknown terminal'}
                                                </div>
                                                <div className="mt-0.5 text-[11px] font-semibold text-slate-500">
                                                    {record.branch?.name || 'Unknown branch'}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <span className="rounded-lg bg-slate-100 px-2 py-1 font-mono text-[11px] font-bold text-slate-700">
                                                    {record.batch?.batch_reference || 'No batch'}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4">{getImportStatusBadge(record.status)}</td>
                                            <td className="px-6 py-4 text-right">
                                                <div className="font-black text-slate-800">{formatMoney(record.client_total)}</div>
                                                <div className="text-[11px] font-semibold text-slate-400">Server {formatMoney(record.server_total)}</div>
                                            </td>
                                            <td className="px-6 py-4 font-semibold text-slate-500">
                                                {formatDateTime(record.submitted_at)}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <button
                                                    onClick={() => openImportDetail(record)}
                                                    className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700 transition hover:bg-indigo-100"
                                                >
                                                    <Eye size={13} />
                                                    Review
                                                </button>
                                            </td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan="7" className="px-6 py-12 text-center text-sm font-semibold text-slate-400">
                                                {importsLoading ? 'Loading offline import review records...' : 'No offline imports match the current review filter.'}
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

            {/* Offline Import Review Modal */}
            <Modal show={Boolean(selectedImport)} onClose={closeImportDetail} maxWidth="5xl">
                {selectedImport && (
                    <div className="p-8">
                        <div className="flex items-start justify-between gap-4 border-b border-slate-100 pb-5">
                            <div>
                                <h3 className="flex items-center gap-2 text-xl font-black text-slate-800">
                                    <ShieldAlert className="text-rose-500" size={22} />
                                    Offline import review
                                </h3>
                                <p className="mt-1 font-mono text-xs font-black uppercase tracking-wider text-slate-400">
                                    {selectedImport.offline_sequence_number} • {selectedImport.batch?.batch_reference || 'No batch reference'}
                                </p>
                            </div>
                            <div className="flex items-center gap-3">
                                {getImportStatusBadge(selectedImport.status)}
                                <button
                                    onClick={closeImportDetail}
                                    className="rounded-xl p-2 text-slate-400 transition hover:bg-slate-50 hover:text-slate-600"
                                >
                                    <X size={20} />
                                </button>
                            </div>
                        </div>

                        {reviewNotice && (
                            <div className={`mt-5 rounded-2xl border px-4 py-3 text-sm font-semibold ${
                                reviewNotice.tone === 'success'
                                    ? 'border-emerald-100 bg-emerald-50 text-emerald-700'
                                    : 'border-rose-100 bg-rose-50 text-rose-700'
                            }`}>
                                {reviewNotice.message}
                            </div>
                        )}

                        <div className="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[1fr_1.3fr]">
                            <div className="space-y-4">
                                <div className="rounded-2xl border border-slate-100 bg-slate-50 p-5">
                                    <h4 className="text-[11px] font-black uppercase tracking-widest text-slate-400">Identifiers</h4>
                                    <div className="mt-4 space-y-3 text-sm">
                                        {[
                                            ['Terminal', selectedImport.terminal?.terminal_identifier || selectedImport.terminal?.profile_code || 'Unknown'],
                                            ['Branch', selectedImport.branch?.name || 'Unknown'],
                                            ['Local reference', selectedImport.local_transaction_reference || 'Unavailable'],
                                            ['Payment method', selectedImport.payment_method || 'Unavailable'],
                                            ['Submitted', formatDateTime(selectedImport.submitted_at)],
                                            ['Reviewed by', selectedImport.reviewed_by?.name || 'Not reviewed'],
                                        ].map(([label, value]) => (
                                            <div key={label} className="flex justify-between gap-4 border-b border-slate-200/60 pb-2 last:border-0 last:pb-0">
                                                <span className="font-bold text-slate-400">{label}</span>
                                                <span className="text-right font-extrabold text-slate-700">{value}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-slate-100 bg-white p-5">
                                    <h4 className="text-[11px] font-black uppercase tracking-widest text-slate-400">Totals comparison</h4>
                                    <div className="mt-4 grid grid-cols-2 gap-3">
                                        <div className="rounded-xl bg-slate-50 p-4">
                                            <div className="text-[10px] font-black uppercase tracking-wider text-slate-400">Client total</div>
                                            <div className="mt-1 text-2xl font-black text-slate-800">{formatMoney(selectedImport.client_total)}</div>
                                        </div>
                                        <div className="rounded-xl bg-indigo-50 p-4">
                                            <div className="text-[10px] font-black uppercase tracking-wider text-indigo-400">Server total</div>
                                            <div className="mt-1 text-2xl font-black text-indigo-700">{formatMoney(selectedImport.server_total)}</div>
                                        </div>
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-slate-100 bg-white p-5">
                                    <h4 className="text-[11px] font-black uppercase tracking-widest text-slate-400">Review notes</h4>
                                    <textarea
                                        value={reviewNotes}
                                        onChange={(event) => setReviewNotes(event.target.value)}
                                        rows={5}
                                        className="mt-3 w-full rounded-xl border border-slate-200 text-sm font-semibold text-slate-700 focus:border-indigo-500 focus:ring-indigo-500/20"
                                        placeholder="Record why this import is held, override-approved, or returned to conflict..."
                                    />
                                </div>
                            </div>

                            <div className="space-y-4">
                                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <div className="rounded-2xl border border-rose-100 bg-rose-50 p-5">
                                        <h4 className="text-[11px] font-black uppercase tracking-widest text-rose-500">Conflict / rejection</h4>
                                        <p className="mt-3 whitespace-pre-wrap text-sm font-semibold text-rose-800">
                                            {selectedImport.conflict_notes || selectedImport.rejection_reason || 'No conflict notes were recorded.'}
                                        </p>
                                    </div>
                                    <div className="rounded-2xl border border-slate-100 bg-slate-50 p-5">
                                        <h4 className="text-[11px] font-black uppercase tracking-widest text-slate-400">Posting result</h4>
                                        {selectedImport.reconciled_sale ? (
                                            <div className="mt-3 text-sm font-semibold text-slate-700">
                                                <div className="font-black text-emerald-700">Posted sale created</div>
                                                <div className="mt-1 font-mono text-xs text-slate-500">{selectedImport.reconciled_sale.invoice_number || selectedImport.reconciled_sale.receipt_number || selectedImport.reconciled_sale.id}</div>
                                            </div>
                                        ) : (
                                            <p className="mt-3 text-sm font-semibold text-slate-500">No official sale has been posted for this import.</p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <div className="rounded-2xl border border-slate-100 bg-slate-950 p-5 text-slate-100">
                                        <h4 className="text-[11px] font-black uppercase tracking-widest text-slate-400">Client payload</h4>
                                        <pre className="mt-3 max-h-80 overflow-auto rounded-xl bg-black/30 p-3 text-[11px] leading-relaxed text-slate-200">
                                            {JSON.stringify(importDetails?.raw_payload || {}, null, 2)}
                                        </pre>
                                    </div>
                                    <div className="rounded-2xl border border-slate-100 bg-slate-950 p-5 text-slate-100">
                                        <h4 className="text-[11px] font-black uppercase tracking-widest text-slate-400">Server recalculation</h4>
                                        <pre className="mt-3 max-h-80 overflow-auto rounded-xl bg-black/30 p-3 text-[11px] leading-relaxed text-slate-200">
                                            {JSON.stringify(importDetails?.server_recalculation || {}, null, 2)}
                                        </pre>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="mt-8 flex flex-col gap-3 border-t border-slate-100 pt-5 lg:flex-row lg:items-center lg:justify-between">
                            <p className="text-xs font-semibold text-slate-500">
                                Override approval does not trust client totals as official truth. Posting still runs server-side reconciliation.
                            </p>
                            <div className="flex flex-wrap justify-end gap-2">
                                <button
                                    onClick={() => submitReviewAction('hold')}
                                    disabled={reviewBusy || !['conflict'].includes(selectedImport.status)}
                                    className="inline-flex items-center gap-2 rounded-xl bg-amber-50 px-4 py-2 text-xs font-black uppercase tracking-wider text-amber-700 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <PauseCircle size={14} />
                                    Hold
                                </button>
                                <button
                                    onClick={() => submitReviewAction('conflict')}
                                    disabled={reviewBusy || selectedImport.status !== 'hold'}
                                    className="inline-flex items-center gap-2 rounded-xl bg-rose-50 px-4 py-2 text-xs font-black uppercase tracking-wider text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <ShieldAlert size={14} />
                                    Return Conflict
                                </button>
                                <button
                                    onClick={() => submitReviewAction('override_approved')}
                                    disabled={reviewBusy || !['conflict', 'hold'].includes(selectedImport.status)}
                                    className="inline-flex items-center gap-2 rounded-xl bg-indigo-50 px-4 py-2 text-xs font-black uppercase tracking-wider text-indigo-700 transition hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <CheckCircle2 size={14} />
                                    Override Approve
                                </button>
                                <button
                                    onClick={postImport}
                                    disabled={reviewBusy || !['server_verified', 'override_approved'].includes(selectedImport.status)}
                                    className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {reviewBusy ? <RefreshCw size={14} className="animate-spin" /> : <Send size={14} />}
                                    Post Import
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </Modal>

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
