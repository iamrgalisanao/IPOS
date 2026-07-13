import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    Monitor,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    Clock,
    RefreshCw,
    Edit,
    Activity,
    Key,
    Power,
    MapPin,
    Layers,
    Cpu,
    ArrowRight
} from 'lucide-react';

export default function Index({ auth, profiles, branches, filters, flash = {} }) {
    const [selectedBranch, setSelectedBranch] = useState(filters.branch_id || '');
    const [revokingProfile, setRevokingProfile] = useState(null);
    const [generatingId, setGeneratingId] = useState(null);
    const [revokingId, setRevokingId] = useState(null);

    const handleBranchChange = (e) => {
        const branchId = e.target.value;
        setSelectedBranch(branchId);
        router.get(route('admin.sales-machine-profiles.index'), { branch_id: branchId }, {
            preserveState: true,
            preserveScroll: true
        });
    };

    const generateCode = (profileId) => {
        if (generatingId) return;
        setGeneratingId(profileId);
        router.post(route('admin.sales-machine-profiles.activation-code', profileId), {}, {
            preserveScroll: true,
            onFinish: () => setGeneratingId(null),
        });
    };

    const confirmRevoke = (profile) => {
        setRevokingProfile(profile);
    };

    const executeRevoke = () => {
        if (!revokingProfile || revokingId) return;
        setRevokingId(revokingProfile.id);
        router.post(route('admin.sales-machine-profiles.revoke-activation', revokingProfile.id), {}, {
            preserveScroll: true,
            onFinish: () => {
                setRevokingId(null);
                setRevokingProfile(null);
            },
        });
    };

    const getStatusBadge = (status) => {
        switch (status) {
            case 'active':
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <CheckCircle2 size={12} className="text-emerald-500" />
                        Active
                    </span>
                );
            case 'pending_activation':
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-amber-50 text-amber-700 border border-amber-200 animate-pulse">
                        <Clock size={12} className="text-amber-500" />
                        Pending Activation
                    </span>
                );
            case 'suspended':
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-rose-50 text-rose-700 border border-rose-200">
                        <AlertTriangle size={12} className="text-rose-500" />
                        Suspended
                    </span>
                );
            case 'revoked':
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-slate-100 text-slate-700 border border-slate-200">
                        <XCircle size={12} className="text-slate-500" />
                        Revoked
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-slate-50 text-slate-600 border border-slate-200">
                        {status}
                    </span>
                );
        }
    };

    const getConnectionIndicator = (heartbeat) => {
        if (!heartbeat) {
            return (
                <div className="flex items-center gap-1.5 text-xs text-slate-400 font-semibold">
                    <div className="h-2 w-2 rounded-full bg-slate-300" />
                    Never Connected
                </div>
            );
        }

        const heartbeatAt = new Date(heartbeat.reported_at || heartbeat.updated_at).getTime();
        const isRecent = Number.isFinite(heartbeatAt) && Date.now() - heartbeatAt <= 2 * 60 * 1000;
        const isOnline = heartbeat.connection_state === 'online' && isRecent;
        return (
            <div className={`flex items-center gap-1.5 text-xs font-semibold ${isOnline ? 'text-emerald-600' : 'text-slate-500'}`}>
                <div className={`h-2 w-2 rounded-full ${isOnline ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'}`} />
                {isOnline ? 'Online' : 'Offline'}
            </div>
        );
    };

    const formatTimestamp = (ts) => {
        if (!ts) return 'N/A';
        return new Date(ts).toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    };

    const formatActivationCode = (code) => {
        if (!code) return '';
        if (code.length === 8) {
            return `${code.slice(0, 4)}-${code.slice(4)}`;
        }
        return code;
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">POS Register Fleet</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Monitor active terminal statuses, manage hardware activation keys, and configure offline sales limits.</p>
                    </div>
                </div>
            }
        >
            <Head title="POS Terminals" />

            <div className="py-8">
                <div className="max-w-[96rem] mx-auto px-4 sm:px-6 lg:px-8">

                    {/* Raw Activation Code success card display once */}
                    {flash.activation_code_raw && (
                        <div className="mb-8 p-6 rounded-[2rem] bg-emerald-950 border border-emerald-800/40 text-emerald-100 shadow-xl relative overflow-hidden flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                            <div className="flex gap-4">
                                <div className="p-3 bg-emerald-900 text-emerald-400 rounded-2xl shrink-0">
                                    <Key size={24} />
                                </div>
                                <div>
                                    <h3 className="text-lg font-black text-emerald-400 uppercase tracking-widest">Activation Code Generated</h3>
                                    <p className="text-sm text-emerald-200/90 font-medium mt-1">
                                        Use this code to activate the terminal. This code is shown <strong className="text-white font-extrabold underline">only once</strong> and expires in 24 hours.
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-4 bg-emerald-900/45 px-6 py-4 rounded-2xl border border-emerald-800/20 w-full md:w-auto justify-center md:justify-start">
                                <div className="font-mono text-3xl font-black tracking-widest text-white">
                                    {formatActivationCode(flash.activation_code_raw)}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Filter and Actions Bar */}
                    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
                        <div className="flex items-center gap-3 bg-white px-4 py-2.5 rounded-2xl border border-slate-100 shadow-sm w-full md:w-80">
                            <MapPin className="text-slate-400 shrink-0" size={18} />
                            <select
                                value={selectedBranch}
                                onChange={handleBranchChange}
                                className="w-full bg-transparent border-none text-slate-700 text-sm font-bold focus:ring-0 p-0 focus:outline-none cursor-pointer"
                            >
                                <option value="">All Branches</option>
                                {branches.map((b) => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                        </div>
                        <div className="text-xs text-slate-400 font-bold uppercase tracking-widest">
                            Showing {profiles.data.length} registered terminals
                        </div>
                    </div>

                    {/* Table View */}
                    <div className="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="border-b border-slate-100 bg-slate-50/50">
                                        <th className="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Profile Code / Details</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Branch</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Assigned Layout</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status / Connection</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Device ID</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Active App Info</th>
                                        <th className="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Last Activity</th>
                                        <th className="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {profiles.data.length === 0 ? (
                                        <tr>
                                            <td colSpan="8" className="px-8 py-16 text-center text-slate-400 font-medium italic">
                                                No terminal profiles registered for this scope.
                                            </td>
                                        </tr>
                                    ) : (
                                        profiles.data.map((profile) => {
                                            const hb = profile.latest_heartbeat;
                                            return (
                                                <tr key={profile.id} className="hover:bg-slate-50/30 transition-colors group">
                                                    <td className="px-8 py-6">
                                                        <div className="flex items-center gap-3">
                                                            <div className="p-3 bg-indigo-50 text-indigo-600 rounded-2xl group-hover:scale-105 transition-transform">
                                                                <Monitor size={20} />
                                                            </div>
                                                            <div>
                                                                <div className="font-extrabold text-slate-800 text-sm">{profile.profile_code}</div>
                                                                <div className="text-slate-400 text-xs mt-0.5 font-mono">{profile.terminal_identifier}</div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-6 text-sm text-slate-600 font-semibold">
                                                        {profile.branch?.name}
                                                    </td>
                                                    <td className="px-6 py-6">
                                                        <div className="flex flex-col gap-0.5">
                                                            <div className="flex items-center gap-1.5 text-sm text-slate-700 font-bold">
                                                                <Layers size={14} className="text-slate-400" />
                                                                {profile.effective_layout_name}
                                                            </div>
                                                            <span className={`text-[10px] font-black uppercase tracking-wider ${
                                                                profile.effective_layout_source === 'Override'
                                                                    ? 'text-indigo-600'
                                                                    : 'text-slate-400'
                                                            }`}>
                                                                {profile.effective_layout_source}
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-6">
                                                        <div className="flex flex-col gap-1.5">
                                                            <div>{getStatusBadge(profile.activation_status)}</div>
                                                            <div>{getConnectionIndicator(hb)}</div>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-6">
                                                        <div className="text-xs font-mono text-slate-500 select-all max-w-[120px] truncate" title={profile.activated_device_id || 'N/A'}>
                                                            {profile.activated_device_id || '—'}
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-6">
                                                        {hb ? (
                                                            <div className="flex flex-col gap-0.5 text-xs text-slate-500 font-medium">
                                                                <span className="font-bold text-slate-700">v{hb.app_version || '1.0.0'}</span>
                                                                <span>Queue: <strong className={hb.queue_count > 0 ? 'text-amber-600' : 'text-slate-500'}>{hb.queue_count || 0} pending</strong></span>
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs text-slate-400">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-6 py-6 text-xs text-slate-500 font-medium">
                                                        {hb ? (
                                                            <div className="flex flex-col gap-0.5">
                                                                <span>Heartbeat: {formatTimestamp(hb.reported_at || hb.updated_at)}</span>
                                                                {hb.last_successful_sync_at && (
                                                                    <span className="text-[10px] text-emerald-600 font-bold uppercase tracking-tighter">
                                                                        Sync: {formatTimestamp(hb.last_successful_sync_at)}
                                                                    </span>
                                                                )}
                                                                {profile.last_activated_ip && (
                                                                    <span className="text-[10px] text-slate-400 font-mono">
                                                                        IP: {profile.last_activated_ip}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs text-slate-400">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-8 py-6 text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <Link
                                                                href={route('admin.sales-machine-profiles.edit', profile.id)}
                                                                className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-slate-50 border border-slate-100 text-slate-600 hover:bg-indigo-50 hover:text-indigo-600 hover:border-indigo-100 transition-colors"
                                                                title="Edit settings"
                                                            >
                                                                <Edit size={16} />
                                                            </Link>

                                                            {profile.activation_status === 'active' ? (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => confirmRevoke(profile)}
                                                                    className="inline-flex h-9 px-3 items-center gap-1.5 rounded-xl border border-rose-100 bg-rose-50 text-rose-600 hover:bg-rose-100 transition-colors text-xs font-black uppercase tracking-wider"
                                                                    title="Revoke Device Activation"
                                                                >
                                                                    <Power size={14} />
                                                                    Revoke
                                                                </button>
                                                            ) : (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => generateCode(profile.id)}
                                                                    disabled={generatingId !== null}
                                                                    className="inline-flex h-9 px-3 items-center gap-1.5 rounded-xl border border-indigo-100 bg-indigo-50 text-indigo-600 hover:bg-indigo-100 transition-colors text-xs font-black uppercase tracking-wider"
                                                                    title="Generate Activation Code"
                                                                >
                                                                    <Key size={14} />
                                                                    {generatingId === profile.id ? 'Generating…' : 'Activate'}
                                                                </button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {/* Revoke Confirmation Modal Dialog */}
            {revokingProfile && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-sm animate-fade-in">
                    <div className="bg-white rounded-[2.5rem] border border-slate-100 shadow-2xl p-8 max-w-md w-full animate-scale-up">
                        <div className="flex gap-4 items-start">
                            <div className="p-4 bg-rose-50 text-rose-600 rounded-3xl shrink-0">
                                <AlertTriangle size={28} />
                            </div>
                            <div>
                                <h3 className="text-xl font-extrabold text-slate-800">Revoke Activation?</h3>
                                <p className="text-sm text-slate-500 mt-2 font-medium leading-relaxed">
                                    Revoking this activation will unbind the current device. The POS terminal will be locked and must be activated again with a new code.
                                </p>
                                <div className="mt-2 p-3 bg-slate-50 rounded-2xl border border-slate-100 text-xs font-mono text-slate-600">
                                    <strong>Terminal:</strong> {revokingProfile.profile_code} ({revokingProfile.terminal_identifier})
                                </div>
                            </div>
                        </div>
                        <div className="mt-8 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => setRevokingProfile(null)}
                                className="px-5 py-2.5 bg-slate-50 hover:bg-slate-100 text-slate-600 rounded-xl font-black text-xs uppercase tracking-widest transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={executeRevoke}
                                disabled={revokingId !== null}
                                className="px-5 py-2.5 bg-rose-600 hover:bg-rose-500 text-white rounded-xl font-black text-xs uppercase tracking-widest transition-colors shadow-lg shadow-rose-600/20"
                            >
                                {revokingId ? 'Revoking…' : 'Revoke Activation'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {profiles.links?.length > 3 && (
                <nav className="mt-6 flex flex-wrap justify-center gap-2" aria-label="Terminal profile pages">
                    {profiles.links.map((link, index) => (
                        <Link
                            key={`${link.label}-${index}`}
                            href={link.url || '#'}
                            preserveScroll
                            className={`rounded-xl px-3 py-2 text-xs font-bold ${link.active ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 border border-slate-200'} ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </nav>
            )}
        </AuthenticatedLayout>
    );
}
