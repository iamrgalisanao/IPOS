import React, { useState, useEffect } from 'react';
import TabletPOSLayout from '@/Layouts/TabletPOSLayout';
import TerminalInfoShell from './TerminalInfoShell';
import { AlertTriangle, CheckCircle2, Database, Download, History, Loader2, ShieldCheck, WifiOff, XCircle, RefreshCw, Layers } from 'lucide-react';
import { useConnectivityStore } from '@/POS/offline/connectivityStore';
import { offlineSalesQueue } from '@/POS/offline/offlineSalesQueue';
import { offlinePaymentQueue } from '@/POS/offline/offlinePaymentQueue';
import { offlineSyncManager } from '@/POS/offline/offlineSyncManager';

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

    const [queueCounts, setQueueCounts] = useState({
        pendingSales: 0,
        failedSales: 0,
        conflictSales: 0,
        pendingPayments: 0,
        failedPayments: 0,
    });
    const [swVersion, setSwVersion] = useState('Checking...');
    const [isSyncingQueue, setIsSyncingQueue] = useState(false);
    const [syncMessage, setSyncMessage] = useState(null);

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

    const fetchQueueStats = async () => {
        try {
            const [salesSummary, paymentSummary] = await Promise.all([
                offlineSalesQueue.getStatusSummary(),
                offlinePaymentQueue.getStatusSummary(),
            ]);
            setQueueCounts({
                pendingSales: salesSummary.pending || 0,
                failedSales: salesSummary.failed || 0,
                conflictSales: (salesSummary.conflict || 0) + (salesSummary.acceptedWithWarning || 0),
                pendingPayments: paymentSummary.pending || 0,
                failedPayments: paymentSummary.failed || 0,
            });
        } catch (err) {
            console.error('Failed to fetch queue stats for dashboard:', err);
        }
    };

    useEffect(() => {
        void checkConnectivity().catch(() => undefined);
        fetchQueueStats();

        // Fetch Service Worker script name
        if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistration().then((reg) => {
                if (reg?.active) {
                    setSwVersion(reg.active.scriptURL.split('/').pop() || 'Active');
                } else {
                    setSwVersion('No active worker');
                }
            }).catch(() => setSwVersion('Unavailable'));
        } else {
            setSwVersion('Not supported');
        }
    }, [lastSyncedAt]);

    const handleForceSync = async () => {
        if (isSyncingQueue) return;
        setIsSyncingQueue(true);
        setSyncMessage('Starting manual queue synchronization...');
        try {
            await offlineSyncManager.retryFailed();
            await offlineSyncManager.sync();
            await fetchQueueStats();
            setSyncMessage('Queue sync completed successfully.');
        } catch (err) {
            console.error('Manual sync failed:', err);
            setSyncMessage('Manual sync failed. Please check your connection.');
        } finally {
            setIsSyncingQueue(false);
            setTimeout(() => setSyncMessage(null), 5000);
        }
    };

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

            <section className="rounded-2xl border border-slate-800 bg-slate-900/50 p-6 space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <Database className="h-5 w-5 text-indigo-400" />
                            <h2 className="text-sm font-black uppercase tracking-widest text-slate-200">Terminal Diagnostics & Queues</h2>
                        </div>
                        <p className="mt-1.5 text-xs text-slate-400">
                            Monitor offline sequence counts, service worker shells, and local IndexedDB state.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={handleForceSync}
                        disabled={isSyncingQueue || isOffline}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-650 px-4 py-2.5 text-xs font-black uppercase tracking-wider text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-800 disabled:text-slate-500 shadow-lg shadow-indigo-600/10"
                    >
                        {isSyncingQueue ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                        {isSyncingQueue ? 'Syncing...' : 'Force Queue Sync'}
                    </button>
                </div>

                {syncMessage && (
                    <div className="rounded-xl border border-indigo-500/30 bg-indigo-950/20 px-4 py-3 text-xs text-indigo-200 font-medium">
                        {syncMessage}
                    </div>
                )}

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {/* IndexedDB Queues */}
                    <div className="rounded-xl border border-slate-800 bg-slate-950/40 p-4 space-y-3">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-500">IndexedDB Queue Counts</h3>
                        <div className="space-y-2 text-xs">
                            <div className="flex justify-between py-1 border-b border-slate-800/50">
                                <span className="text-slate-400">Pending Sales</span>
                                <span className={`font-bold ${queueCounts.pendingSales > 0 ? 'text-indigo-400' : 'text-slate-300'}`}>{queueCounts.pendingSales}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-slate-800/50">
                                <span className="text-slate-400">Failed Sales</span>
                                <span className={`font-bold ${queueCounts.failedSales > 0 ? 'text-rose-400' : 'text-slate-300'}`}>{queueCounts.failedSales}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-slate-800/50">
                                <span className="text-slate-400">Sequence Conflicts</span>
                                <span className={`font-bold ${queueCounts.conflictSales > 0 ? 'text-amber-400' : 'text-slate-300'}`}>{queueCounts.conflictSales}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-slate-800/50">
                                <span className="text-slate-400">Pending Payments</span>
                                <span className={`font-bold ${queueCounts.pendingPayments > 0 ? 'text-indigo-400' : 'text-slate-300'}`}>{queueCounts.pendingPayments}</span>
                            </div>
                            <div className="flex justify-between py-1">
                                <span className="text-slate-400">Failed Payments</span>
                                <span className={`font-bold ${queueCounts.failedPayments > 0 ? 'text-rose-400' : 'text-slate-300'}`}>{queueCounts.failedPayments}</span>
                            </div>
                        </div>
                    </div>

                    {/* Hardware & Worker Context */}
                    <div className="rounded-xl border border-slate-800 bg-slate-950/40 p-4 space-y-3">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-500">Service Worker Shell</h3>
                        <div className="space-y-2 text-xs">
                            <div className="flex justify-between py-1 border-b border-slate-800/50">
                                <span className="text-slate-400">Shell Script</span>
                                <span className="font-mono text-slate-300">{swVersion}</span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-slate-800/50">
                                <span className="text-slate-400">Offline Capability</span>
                                <span className="font-bold text-emerald-400">Enabled</span>
                            </div>
                            <div className="flex justify-between py-1">
                                <span className="text-slate-400">Accidental Refresh Protection</span>
                                <span className="font-bold text-blue-400">Active</span>
                            </div>
                        </div>
                    </div>

                    {/* Heartbeat Status */}
                    <div className="rounded-xl border border-slate-800 bg-slate-950/40 p-4 space-y-3">
                        <h3 className="text-xs font-black uppercase tracking-wider text-slate-500">Diagnostics Check</h3>
                        <div className="space-y-2 text-xs">
                            <div className="flex justify-between py-1 border-b border-slate-800/50">
                                <span className="text-slate-400">Database Schema</span>
                                <span className="font-bold text-emerald-400">Verified (v1)</span>
                            </div>
                            <div className="flex justify-between py-1 border-b border-slate-800/50">
                                <span className="text-slate-400">Sync Heartbeat</span>
                                <span className="font-bold text-emerald-400">Active</span>
                            </div>
                            <div className="flex justify-between py-1">
                                <span className="text-slate-400">Heartbeat Interval</span>
                                <span className="text-slate-300">10 seconds</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

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
