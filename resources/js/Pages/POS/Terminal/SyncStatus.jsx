import React from 'react';
import TabletPOSLayout from '@/Layouts/TabletPOSLayout';
import TerminalInfoShell from './TerminalInfoShell';
import { AlertTriangle, CheckCircle2, Database, Download, History, Loader2, ShieldCheck, WifiOff, XCircle } from 'lucide-react';
import { useConnectivityStore } from '@/POS/offline/connectivityStore';

export default function SyncStatus({ terminal_context, sync_guidance }) {
    const {
        isOffline,
        isChecking,
        isStale,
        lastSyncedAt,
        lastSnapshotHash,
        refreshResult,
        refreshConfiguration,
        checkConnectivity,
        terminalContextInvalid,
    } = useConnectivityStore();
    const refreshing = refreshResult.status === 'refreshing';

    const resultStyles = {
        success: ['border-emerald-500/30 bg-emerald-500/10 text-emerald-100', CheckCircle2],
        stale: ['border-amber-500/30 bg-amber-500/10 text-amber-100', AlertTriangle],
        offline: ['border-amber-500/30 bg-amber-500/10 text-amber-100', WifiOff],
        failure: ['border-rose-500/30 bg-rose-500/10 text-rose-100', XCircle],
        'invalid-terminal': ['border-rose-500/30 bg-rose-500/10 text-rose-100', XCircle],
        refreshing: ['border-indigo-500/30 bg-indigo-500/10 text-indigo-100', Loader2],
        idle: ['border-slate-800 bg-slate-900/80 text-slate-300', Database],
    };
    const [resultClass, ResultIcon] = resultStyles[refreshResult.status] || resultStyles.idle;

    const formatTimestamp = (value) => {
        if (!value) return 'Not available';
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? 'Not available' : parsed.toLocaleString();
    };

    React.useEffect(() => {
        void checkConnectivity().catch(() => undefined);
    }, []);

    return (
        <TerminalInfoShell
            title="Sync Status"
            subtitle="Operational queue and reconciliation guidance for this terminal."
            terminalContext={terminal_context}
        >
            <section className="rounded-2xl border border-indigo-500/30 bg-gradient-to-br from-indigo-950/80 to-slate-900 p-5">
                <div className="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
                    <div className="max-w-2xl">
                        <div className="flex items-center gap-2">
                            <Download className="h-5 w-5 text-indigo-300" />
                            <h2 className="text-sm font-black uppercase tracking-widest text-indigo-100">Terminal Configuration</h2>
                        </div>
                        <p className="mt-3 text-sm leading-6 text-slate-300">
                            Download the latest protected configuration for this terminal. This does not upload pending offline transactions or clear the active cart.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={refreshConfiguration}
                        disabled={refreshing || terminalContextInvalid}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-xs font-black uppercase tracking-wider text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400"
                    >
                        {refreshing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
                        {refreshing ? 'Refreshing Configuration' : 'Refresh Configuration'}
                    </button>
                </div>

                <div className={`mt-5 rounded-xl border p-4 ${resultClass}`} role="status" aria-live="polite">
                    <div className="flex items-start gap-3">
                        <ResultIcon className={`mt-0.5 h-5 w-5 shrink-0 ${refreshing ? 'animate-spin' : ''}`} />
                        <div>
                            <div className="text-xs font-black uppercase tracking-wider">
                                {terminalContextInvalid ? 'Activation Required' : isStale ? 'Configuration Stale' : refreshResult.status.replace('-', ' ')}
                            </div>
                            <p className="mt-1 text-sm leading-5">{refreshResult.message}</p>
                        </div>
                    </div>
                </div>

                <dl className="mt-4 grid gap-3 text-xs sm:grid-cols-3">
                    <div className="rounded-xl border border-slate-800 bg-slate-950/40 p-3">
                        <dt className="font-bold uppercase tracking-wider text-slate-500">Connection</dt>
                        <dd className={`mt-1 font-black ${isOffline || isChecking ? 'text-amber-300' : 'text-emerald-300'}`}>
                            {isOffline ? 'Offline' : isChecking ? 'Checking' : 'Online'}
                        </dd>
                    </div>
                    <div className="rounded-xl border border-slate-800 bg-slate-950/40 p-3">
                        <dt className="font-bold uppercase tracking-wider text-slate-500">Server Generated</dt>
                        <dd className="mt-1 font-bold text-slate-200">{formatTimestamp(refreshResult.generatedAt || lastSyncedAt)}</dd>
                    </div>
                    <div className="rounded-xl border border-slate-800 bg-slate-950/40 p-3">
                        <dt className="font-bold uppercase tracking-wider text-slate-500">Snapshot Hash</dt>
                        <dd className="mt-1 break-all font-mono text-[10px] text-slate-300">{refreshResult.snapshotHash || lastSnapshotHash || 'Not available'}</dd>
                    </div>
                </dl>
            </section>

            <div className="grid gap-4 md:grid-cols-3">
                <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                    <Database className="mb-4 h-6 w-6 text-indigo-400" />
                    <div className="text-xs font-black uppercase tracking-widest text-slate-500">Local Queue</div>
                    <div className="mt-2 text-lg font-black text-slate-100">IndexedDB</div>
                    <p className="mt-2 text-xs leading-5 text-slate-400">Pending, failed, and review records remain local until synchronized or reviewed.</p>
                </div>
                <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                    <History className="mb-4 h-6 w-6 text-indigo-400" />
                    <div className="text-xs font-black uppercase tracking-widest text-slate-500">Retry Path</div>
                    <div className="mt-2 text-lg font-black text-slate-100">Checkout Drawer</div>
                    <p className="mt-2 text-xs leading-5 text-slate-400">Use the checkout queue drawer for cashier-visible retry and queue inspection.</p>
                </div>
                <div className="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5">
                    <AlertTriangle className="mb-4 h-6 w-6 text-amber-300" />
                    <div className="text-xs font-black uppercase tracking-widest text-amber-200">Review Required</div>
                    <div className="mt-2 text-lg font-black text-amber-100">Admin Only</div>
                    <p className="mt-2 text-xs leading-5 text-amber-100/80">Sequence conflicts and rejected imports must be handled through admin review.</p>
                </div>
            </div>

            <section className="rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
                <div className="mb-4 flex items-center gap-2">
                    <ShieldCheck className="h-5 w-5 text-emerald-400" />
                    <h2 className="text-sm font-black uppercase tracking-widest text-slate-300">Support Guidance</h2>
                </div>
                <div className="space-y-3 text-sm leading-6 text-slate-300">
                    <p>{sync_guidance?.cashier_message}</p>
                    <p>
                        Admin review surface:{' '}
                        <span className="font-mono text-indigo-300">{sync_guidance?.admin_review_route || 'Unavailable'}</span>
                    </p>
                    <p className="text-slate-400">
                        Configuration refresh is separate from transaction upload. Queue retries remain in the checkout queue drawer, while conflicts remain in admin review.
                    </p>
                </div>
            </section>
        </TerminalInfoShell>
    );
}

SyncStatus.layout = (page) => <TabletPOSLayout>{page}</TabletPOSLayout>;
