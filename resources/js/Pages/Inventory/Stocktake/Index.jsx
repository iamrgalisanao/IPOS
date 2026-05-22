import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { 
    Plus, 
    ClipboardList, 
    Clock, 
    CheckCircle2, 
    AlertCircle, 
    XCircle,
    ArrowRight,
    Search,
    ChevronRight,
    User as UserIcon,
    Calendar,
    Printer
} from 'lucide-react';

const StatusBadge = ({ status }) => {
    const statusConfig = {
        draft: { color: 'bg-slate-100 text-slate-800 border-slate-200', icon: AlertCircle, label: 'Draft' },
        counting: { color: 'bg-blue-100 text-blue-800 border-blue-200', icon: Clock, label: 'Counting' },
        review: { color: 'bg-amber-100 text-amber-800 border-amber-200', icon: ClipboardList, label: 'In Review' },
        posted: { color: 'bg-emerald-100 text-emerald-800 border-emerald-200', icon: CheckCircle2, label: 'Posted' },
        cancelled: { color: 'bg-rose-100 text-rose-800 border-rose-200', icon: XCircle, label: 'Cancelled' },
        rejected: { color: 'bg-rose-100 text-rose-800 border-rose-200', icon: XCircle, label: 'Rejected' },
    };

    const config = statusConfig[status] || statusConfig.draft;
    const Icon = config.icon;

    return (
        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border ${config.color}`}>
            <Icon size={12} />
            {config.label}
        </span>
    );
};

export default function Index({ auth, sessions }) {
    const formatDate = (dateStr) => {
        if (!dateStr) return '—';
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
                            <ClipboardList size={12} />
                            Inventory Operations
                        </div>
                        <h2 className="text-2xl font-bold leading-tight text-slate-900">Stocktake Sessions</h2>
                    </div>
                    {auth.permissions.includes('inventory.stocktake.create') && (
                        <Link
                            href={route('inventory.stocktakes.create')}
                            className="inline-flex items-center gap-2 px-4 py-2.5 bg-indigo-600 text-white text-sm font-bold rounded-xl hover:bg-indigo-700 transition-all shadow-sm shadow-indigo-200/50"
                        >
                            <Plus size={18} />
                            New Stocktake
                        </Link>
                    )}
                </div>
            }
        >
            <Head title="Inventory Stocktake" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="bg-white rounded-[28px] shadow-sm border border-slate-200 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50 border-b border-slate-100">
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest">Stocktake Details</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest">Status</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest">Actor</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest">Timestamps</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-widest"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {sessions.data.length > 0 ? sessions.data.map((session) => (
                                        <tr key={session.id} className="hover:bg-slate-50/80 transition-colors group">
                                            <td className="px-6 py-5">
                                                <div className="flex flex-col">
                                                    <span className="text-sm font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">
                                                        {session.stocktake_number}
                                                    </span>
                                                    {session.notes && (
                                                        <span className="text-xs text-slate-500 mt-1 line-clamp-1 italic">
                                                            "{session.notes}"
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-6 py-5">
                                                <StatusBadge status={session.status} />
                                            </td>
                                            <td className="px-6 py-5 text-sm text-slate-600 font-medium">
                                                <div className="flex items-center gap-2">
                                                    <div className="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-[10px] font-bold text-slate-500">
                                                        {session.started_by_user?.name?.charAt(0)}
                                                    </div>
                                                    {session.started_by_user?.name}
                                                </div>
                                            </td>
                                            <td className="px-6 py-5">
                                                <div className="flex flex-col gap-1.5">
                                                    <div className="flex items-center gap-2 text-[11px] text-slate-500 font-medium">
                                                        <span className="w-12 text-slate-400">Started:</span>
                                                        <Calendar size={10} />
                                                        {formatDate(session.started_at || session.created_at)}
                                                    </div>
                                                    {session.posted_at && (
                                                        <div className="flex items-center gap-2 text-[11px] text-emerald-600 font-medium">
                                                            <span className="w-12 text-slate-400">Posted:</span>
                                                            <CheckCircle2 size={10} />
                                                            {formatDate(session.posted_at)}
                                                        </div>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-6 py-5 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Link
                                                        href={route('inventory.stocktakes.summary', session.id)}
                                                        className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all"
                                                        title="Print Summary"
                                                    >
                                                        <Printer size={18} />
                                                    </Link>
                                                    <Link
                                                        href={route('inventory.stocktakes.show', session.id)}
                                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-slate-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all"
                                                    >
                                                        {session.status === 'draft' ? 'Configure' : (session.status === 'counting' ? 'Continue' : 'View')}
                                                        <ArrowRight size={14} />
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan="5" className="px-6 py-12 text-center">
                                                <div className="flex flex-col items-center gap-3">
                                                    <div className="p-4 rounded-full bg-slate-50">
                                                        <ClipboardList size={32} className="text-slate-300" />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <p className="text-sm font-semibold text-slate-900">No stocktakes found</p>
                                                        <p className="text-xs text-slate-500">Initialize your first branch-scoped count to get started.</p>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {sessions.total > sessions.per_page && (
                            <div className="px-6 py-4 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between">
                                <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">
                                    Showing {sessions.from} to {sessions.to} of {sessions.total} sessions
                                </p>
                                <div className="flex items-center gap-2">
                                    {sessions.links.map((link, idx) => (
                                        <button
                                            key={idx}
                                            disabled={!link.url || link.active}
                                            onClick={() => link.url && router.get(link.url)}
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
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
