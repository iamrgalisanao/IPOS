import React, { useEffect, useState } from 'react';
import {
    Wifi, WifiOff, RefreshCw, AlertTriangle, Loader2,
    X, CheckCircle2, XCircle, History, ShieldAlert, Download, Database
} from 'lucide-react';
import { useConnectivityStore } from '@/POS/offline/connectivityStore';
import { resolveOfflineCaptureReadiness } from '@/POS/offline/offlineGuards';
import { offlineSalesQueue } from '@/POS/offline/offlineSalesQueue';
import { offlineSyncManager } from '@/POS/offline/offlineSyncManager';
import { offlinePaymentQueue } from '@/POS/offline/offlinePaymentQueue';

export default function ConnectivityBanner() {
    const {
        status,
        isOffline,
        isStale,
        isChecking,
        triggerSync,
        refreshConfiguration,
        refreshResult,
        dismissRefreshResult,
        checkConnectivity,
        lastSyncedAt
    } = useConnectivityStore();

    const [offlineReadiness, setOfflineReadiness] = useState({ allowed: false, message: '' });
    const [queueSummary, setQueueSummary] = useState({
        pending: 0,
        syncing: 0,
        synced: 0,
        failed: 0,
        conflict: 0,
        accepted_with_warning: 0,
        cancelled: 0,
        total: 0,
        lastSyncAttemptAt: null,
        lastSuccessfulSyncAt: null,
    });
    const [transactions, setTransactions] = useState([]);
    const [diagnostics, setDiagnostics] = useState(null);
    const [showDrawer, setShowDrawer] = useState(false);
    const [syncingQueue, setSyncingQueue] = useState(false);
    const [exportingDiagnostics, setExportingDiagnostics] = useState(false);
    const [syncNotice, setSyncNotice] = useState(null);

    useEffect(() => {
        let cancelled = false;

        if (!isOffline) {
            setOfflineReadiness({ allowed: false, message: '' });
            return () => {
                cancelled = true;
            };
        }

        resolveOfflineCaptureReadiness().then((result) => {
            if (!cancelled) {
                setOfflineReadiness(result);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [isOffline, lastSyncedAt]);

    useEffect(() => {
        const refreshQueue = async () => {
            try {
                const sum = await offlineSalesQueue.getStatusSummary();
                const diagnosticBundle = await offlineSalesQueue.getDiagnosticsBundle();
                const paymentSum = offlinePaymentQueue.getStatusSummary();
                setQueueSummary({
                    ...sum,
                    pending: sum.pending + paymentSum.pending,
                    syncing: sum.syncing + paymentSum.syncing,
                    failed: sum.failed + paymentSum.failed,
                    conflict: sum.conflict + (paymentSum.conflict || 0),
                    total: sum.total + paymentSum.total,
                });
                const allSales = await offlineSalesQueue.getAllTransactions();
                const allPayments = offlinePaymentQueue.getAllPayments();
                const normalizedSales = allSales.map((tx) => ({ ...tx, queue_type: 'offline_sale' }));
                const normalizedPayments = allPayments.map((payment) => ({
                    id: payment.id,
                    queue_type: 'server_sale_payment',
                    status: payment.status,
                    created_at: payment.created_at,
                    updated_at: payment.updated_at,
                    error_message: payment.last_error,
                    offline_sequence: `PAY-${payment.sale_id.substring(0, 8)}`,
                    payload: {
                        local_transaction_reference: `PAY-${payment.sale_id.substring(0, 8)}`,
                        cashier_shift_id: null,
                        gross_amount_centavos: Math.round(payment.payload.payments.reduce((sum, row) => sum + Number(row.amount || 0), 0) * 100),
                    },
                    client_totals: {
                        total: payment.payload.payments.reduce((sum, row) => sum + Number(row.amount || 0), 0).toFixed(4),
                    },
                }));

                setTransactions([...normalizedSales, ...normalizedPayments].sort((a, b) => b.created_at.localeCompare(a.created_at)));
                setDiagnostics({
                    ...diagnosticBundle,
                    payment_queue: {
                        summary: paymentSum,
                        records: allPayments.map((payment) => ({
                            id: payment.id,
                            sale_id: payment.sale_id,
                            status: payment.status,
                            created_at: payment.created_at,
                            updated_at: payment.updated_at,
                            terminal_id: payment.context?.terminal_id || null,
                            branch_id: payment.context?.branch_id || null,
                            error_message: payment.last_error || null,
                        })),
                    },
                });
            } catch (err) {
                console.error('Failed to load queue metadata:', err);
            }
        };

        refreshQueue();
        const unsubscribeSales = offlineSalesQueue.subscribe(refreshQueue);
        const unsubscribePayments = offlinePaymentQueue.subscribe(refreshQueue);

        return () => {
            unsubscribeSales();
            unsubscribePayments();
        };
    }, [isOffline, lastSyncedAt]);

    const handleForceSync = async () => {
        if (syncingQueue) return;
        setSyncingQueue(true);
        setSyncNotice(null);
        try {
            await offlineSyncManager.processQueue();
            await offlinePaymentQueue.processQueue();
            const sum = await offlineSalesQueue.getStatusSummary();
            const paymentSum = offlinePaymentQueue.getStatusSummary();
            if (sum.failed > 0) {
                const all = await offlineSalesQueue.getAllTransactions();
                const failed = all.find((tx) => tx.status === 'failed');
                setSyncNotice({
                    tone: 'error',
                    title: 'Retry sync failed',
                    message: failed?.error_message || 'Some offline transactions could not be synchronized.',
                });
            } else if (paymentSum.failed > 0) {
                const failed = offlinePaymentQueue.getAllPayments().find((payment) => payment.status === 'failed');
                setSyncNotice({
                    tone: 'error',
                    title: 'Retry sync failed',
                    message: failed?.last_error || 'Some offline payments could not be synchronized.',
                });
            } else if (sum.conflict > 0 || paymentSum.conflict > 0) {
                setSyncNotice({
                    tone: 'review',
                    title: 'Review required',
                    message: 'Some offline transactions require admin review before posting. Open the queue to inspect the affected records.',
                });
            }
        } catch (err) {
            console.error('Manual queue sync failed:', err);
            setSyncNotice({
                tone: 'error',
                title: 'Retry sync failed',
                message: err?.message || 'Manual queue sync failed.',
            });
        } finally {
            setSyncingQueue(false);
        }
    };

    const handleExportDiagnostics = async () => {
        if (exportingDiagnostics) return;

        setExportingDiagnostics(true);
        try {
            const salesBundle = await offlineSalesQueue.getDiagnosticsBundle();
            const paymentSummary = offlinePaymentQueue.getStatusSummary();
            const paymentRecords = offlinePaymentQueue.getAllPayments().map((payment) => ({
                id: payment.id,
                sale_id: payment.sale_id,
                status: payment.status,
                created_at: payment.created_at,
                updated_at: payment.updated_at,
                terminal_id: payment.context?.terminal_id || null,
                branch_id: payment.context?.branch_id || null,
                error_message: payment.last_error || null,
            }));
            const bundle = {
                ...salesBundle,
                payment_queue: {
                    summary: paymentSummary,
                    records: paymentRecords,
                },
                browser: {
                    user_agent: typeof navigator !== 'undefined' ? navigator.userAgent : null,
                    online: typeof navigator !== 'undefined' ? navigator.onLine : null,
                },
            };
            const blob = new Blob([JSON.stringify(bundle, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = `ipos-terminal-queue-diagnostics-${new Date().toISOString().replace(/[:.]/g, '-')}.json`;
            anchor.click();
            URL.revokeObjectURL(url);
        } catch (err) {
            console.error('Failed to export queue diagnostics:', err);
            setSyncNotice({
                tone: 'error',
                title: 'Diagnostics export failed',
                message: err?.message || 'Unable to generate terminal diagnostics bundle.',
            });
        } finally {
            setExportingDiagnostics(false);
        }
    };

    const formatCentavos = (cents) => {
        const val = Number(cents || 0);
        const whole = Math.floor(val / 100);
        const decimals = String(val % 100).padStart(2, '0');
        return `₱${whole}.${decimals}`;
    };

    const getStatusStyles = (status) => {
        switch (status) {
            case 'synced':
                return 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400';
            case 'accepted_with_warning':
                return 'bg-amber-500/10 border-amber-500/30 text-amber-400';
            case 'conflict':
                return 'bg-rose-500/10 border-rose-500/30 text-rose-400';
            case 'failed':
                return 'bg-rose-500/10 border-rose-500/30 text-rose-400';
            case 'syncing':
                return 'bg-indigo-500/10 border-indigo-500/30 text-indigo-400 animate-pulse';
            case 'pending':
            default:
                return 'bg-slate-500/10 border-slate-500/30 text-slate-400';
        }
    };

    const getStatusLabel = (status) => {
        switch (status) {
            case 'synced': return 'Synced';
            case 'accepted_with_warning': return 'Warning';
            case 'conflict': return 'Conflict';
            case 'failed': return 'Failed';
            case 'syncing': return 'Syncing';
            case 'pending': default: return 'Pending';
        }
    };

    const reviewCount = Number(queueSummary.conflict || 0) + Number(queueSummary.accepted_with_warning || 0);
    const activeQueueCount = Number(queueSummary.pending || 0)
        + Number(queueSummary.syncing || 0)
        + Number(queueSummary.failed || 0)
        + reviewCount;
    const historicalQueueCount = Number(queueSummary.total || 0);
    const hasUnsyncedItems = activeQueueCount > 0;
    const hasConfigurationResult = refreshResult.status !== 'idle';
    const isRefreshingConfiguration = refreshResult.status === 'refreshing';

    // Determine if we should render the banner
    const shouldShowBanner = isOffline || isStale || isChecking || hasUnsyncedItems || hasConfigurationResult;

    if (!shouldShowBanner) {
        return null;
    }

    return (
        <div className="w-full shrink-0 z-40">
            {/* Banner Layout */}
            {isOffline ? (
                <div className={`${offlineReadiness.allowed ? 'bg-gradient-to-r from-amber-950 via-amber-900 to-amber-950 border-amber-800/40 text-amber-100' : 'bg-gradient-to-r from-rose-950 via-rose-900 to-rose-950 border-rose-800/40 text-rose-100'} border-b px-4 py-2.5 flex items-center justify-between text-xs font-semibold shadow-lg`}>
                    <div className="flex items-center gap-2 flex-1 min-w-0">
                        <WifiOff className={`w-4 h-4 shrink-0 animate-pulse ${offlineReadiness.allowed ? 'text-amber-400' : 'text-rose-400'}`} />
                        <span className="truncate">
                            {offlineReadiness.allowed
                                ? `Terminal is offline. Provisional offline capture active (${activeQueueCount} active ${activeQueueCount === 1 ? 'item' : 'items'} queued). Voids, refunds, card/e-wallet checkout are disabled.`
                                : offlineReadiness.message || 'Terminal is offline. Cached product catalog active. Checkouts are locked until online connection is restored.'}
                        </span>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        {historicalQueueCount > 0 && (
                            <button
                                onClick={() => setShowDrawer(true)}
                                className="px-2.5 py-1 bg-slate-800/80 hover:bg-slate-700/80 text-slate-200 border border-slate-700/50 rounded-lg text-[10px] uppercase font-bold tracking-wider transition"
                            >
                                View Queue ({activeQueueCount})
                            </button>
                        )}
                        <button
                            onClick={refreshConfiguration}
                            disabled={isChecking || isRefreshingConfiguration}
                            className={`px-3 py-1 ${offlineReadiness.allowed ? 'bg-amber-800 hover:bg-amber-700' : 'bg-rose-800 hover:bg-rose-700'} text-white rounded-lg flex items-center gap-1.5 transition-all text-[11px] font-black uppercase tracking-wider`}
                        >
                            {isRefreshingConfiguration ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
                            Refresh Config
                        </button>
                    </div>
                </div>
            ) : hasUnsyncedItems ? (
                <div className="bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 border-b border-slate-700/40 text-slate-100 px-4 py-2.5 flex items-center justify-between text-xs font-semibold shadow-lg">
                    <div className="flex items-center gap-2 flex-1 min-w-0">
                        <Wifi className="w-4 h-4 text-emerald-400 shrink-0" />
                        <span className="truncate">
                            POS is Online. Unsynced queue: <strong className="text-amber-400">{queueSummary.pending} pending</strong>, <strong className="text-rose-400">{queueSummary.failed} failed</strong>, <strong className="text-rose-500 font-extrabold">{queueSummary.conflict} conflict</strong>.
                        </span>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        <button
                            onClick={() => setShowDrawer(true)}
                            className="px-2.5 py-1 bg-slate-750 hover:bg-slate-700 text-slate-200 border border-slate-700/50 rounded-lg text-[10px] uppercase font-bold tracking-wider transition"
                        >
                            View Queue ({activeQueueCount})
                        </button>
                        <button
                            onClick={handleForceSync}
                            disabled={syncingQueue || (queueSummary.pending + queueSummary.failed) === 0}
                            className="px-3 py-1 bg-indigo-700 hover:bg-indigo-650 disabled:bg-slate-800 disabled:text-slate-500 text-white rounded-lg flex items-center gap-1.5 transition-all text-[11px] font-black uppercase tracking-wider"
                        >
                            {syncingQueue ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
                            Sync Queue
                        </button>
                        {isStale && (
                            <button
                                onClick={refreshConfiguration}
                                disabled={isRefreshingConfiguration}
                                className="px-3 py-1 bg-amber-800 hover:bg-amber-700 text-white rounded-lg flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wider disabled:opacity-50"
                            >
                                {isRefreshingConfiguration ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
                                Refresh Config
                            </button>
                        )}
                    </div>
                </div>
            ) : isStale ? (
                <div className="bg-gradient-to-r from-amber-950 via-amber-900 to-amber-950 border-b border-amber-800/40 text-amber-100 px-4 py-2.5 flex items-center justify-between text-xs font-semibold shadow-lg shadow-amber-950/20">
                    <div className="flex items-center gap-2 flex-1 min-w-0">
                        <AlertTriangle className="w-4 h-4 text-amber-400 animate-bounce shrink-0" />
                        <span className="truncate">
                            Local database configuration is outdated. Sync configuration to ensure compliant tax calculations.
                        </span>
                    </div>
                    <button
                        onClick={triggerSync}
                        disabled={isChecking || isRefreshingConfiguration}
                        className="px-3 py-1 bg-amber-800 hover:bg-amber-750 text-white rounded-lg flex items-center gap-1.5 transition-all text-[11px] font-black uppercase tracking-wider shrink-0"
                    >
                        {isRefreshingConfiguration ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
                        Refresh Configuration
                    </button>
                </div>
            ) : isChecking ? (
                <div className="bg-gradient-to-r from-indigo-950 via-indigo-900 to-indigo-950 border-b border-indigo-800/40 text-indigo-100 px-4 py-2 flex items-center justify-center gap-2 text-xs font-semibold">
                    <Loader2 className="w-3.5 h-3.5 animate-spin text-indigo-400" />
                    <span>Synchronizing local POS configurations...</span>
                </div>
            ) : null}

            {['refreshing', 'stale', 'offline', 'failure', 'invalid-terminal', 'success'].includes(refreshResult.status) && (
                <div className={`border-b px-4 py-2 text-xs ${
                    refreshResult.status === 'success'
                        ? 'border-emerald-500/30 bg-emerald-950/70 text-emerald-100'
                        : refreshResult.status === 'refreshing'
                            ? 'border-indigo-500/30 bg-indigo-950/70 text-indigo-100'
                        : refreshResult.status === 'failure' || refreshResult.status === 'invalid-terminal'
                            ? 'border-rose-500/30 bg-rose-950/70 text-rose-100'
                            : 'border-amber-500/30 bg-amber-950/70 text-amber-100'
                }`} role="status" aria-live="polite">
                    <div className="flex items-center justify-between gap-3">
                        <span><strong className="mr-1 uppercase tracking-wider">Configuration:</strong>{refreshResult.message}</span>
                        {refreshResult.status !== 'refreshing' && (
                            <button type="button" onClick={dismissRefreshResult} className="font-black uppercase tracking-wider opacity-70 hover:opacity-100">Dismiss</button>
                        )}
                    </div>
                </div>
            )}

            {syncNotice && !isOffline && (
                <div className={`border-b px-4 py-2 text-xs ${
                    syncNotice.tone === 'review'
                        ? 'border-amber-500/30 bg-amber-950/70 text-amber-100'
                        : 'border-rose-500/30 bg-rose-950/70 text-rose-100'
                }`}>
                    <div className="flex items-start gap-2">
                        <AlertTriangle className={`mt-0.5 h-3.5 w-3.5 shrink-0 ${
                            syncNotice.tone === 'review' ? 'text-amber-300' : 'text-rose-300'
                        }`} />
                        <div className="min-w-0">
                            <span className={`font-bold uppercase tracking-wider ${
                                syncNotice.tone === 'review' ? 'text-amber-200' : 'text-rose-200'
                            }`}>
                                {syncNotice.title}:
                            </span>
                            <span className="break-words">{syncNotice.message}</span>
                        </div>
                    </div>
                </div>
            )}

            {/* Sync Queue Drawer Overlay */}
            {showDrawer && (
                <div className="fixed inset-0 z-50 flex justify-end">
                    {/* Backdrop */}
                    <div className="absolute inset-0 bg-black/60 backdrop-blur-sm transition-opacity" onClick={() => setShowDrawer(false)} />

                    {/* Sliding Panel */}
                    <div className="relative w-full max-w-md h-full bg-slate-900 border-l border-slate-800 text-slate-100 flex flex-col shadow-2xl animate-in slide-in-from-right duration-200">

                        {/* Header */}
                        <div className="flex items-center justify-between p-4 border-b border-slate-800 bg-slate-950">
                            <div className="flex items-center gap-2 min-w-0">
                                <History className="w-5 h-5 text-indigo-400 shrink-0" />
                                <h3 className="font-semibold text-sm tracking-wider uppercase truncate">POS Offline Sync Queue</h3>
                            </div>
                            <div className="flex items-center gap-1.5 shrink-0">
                                <button
                                    onClick={handleExportDiagnostics}
                                    disabled={exportingDiagnostics}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-700 px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-300 transition hover:border-indigo-400 hover:text-white disabled:opacity-50"
                                >
                                    {exportingDiagnostics ? <Loader2 className="h-3 w-3 animate-spin" /> : <Download className="h-3 w-3" />}
                                    Export
                                </button>
                                <button
                                    onClick={() => setShowDrawer(false)}
                                    className="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-200 transition"
                                >
                                    <X className="w-4 h-4" />
                                </button>
                            </div>
                        </div>

                        {/* Status Summary Panel */}
                        <div className="grid grid-cols-3 gap-2 p-4 bg-slate-950/40 border-b border-slate-800 text-center text-xs">
                            <div className="bg-slate-800/30 p-2 rounded border border-slate-800/50">
                                <div className="text-slate-400 font-medium">Pending</div>
                                <div className="text-lg font-bold text-slate-200">{queueSummary.pending + queueSummary.syncing}</div>
                            </div>
                            <div className="bg-slate-800/30 p-2 rounded border border-slate-800/50">
                                <div className="text-rose-400 font-medium">Failed</div>
                                <div className="text-lg font-bold text-rose-400">{queueSummary.failed}</div>
                            </div>
                            <div className="bg-slate-800/30 p-2 rounded border border-slate-800/50">
                                <div className="text-rose-500 font-extrabold">Conflict</div>
                                <div className="text-lg font-black text-rose-500">{queueSummary.conflict}</div>
                            </div>
                        </div>
                        <div className="border-b border-slate-800 bg-slate-950/30 px-4 py-2 text-[10px] text-slate-500">
                            Showing {activeQueueCount} active queue {activeQueueCount === 1 ? 'item' : 'items'} across {historicalQueueCount} stored local {historicalQueueCount === 1 ? 'record' : 'records'}.
                        </div>

                        {diagnostics && (
                            <div className="border-b border-slate-800 bg-slate-950/40 px-4 py-3 text-[10px] text-slate-400">
                                <div className="mb-2 flex items-center gap-1.5 font-bold uppercase tracking-wider text-slate-300">
                                    <Database className="h-3.5 w-3.5 text-indigo-300" />
                                    Support Diagnostics
                                </div>
                                <div className="grid grid-cols-2 gap-2">
                                    <div className="rounded-lg border border-slate-800 bg-slate-900/70 px-2 py-1.5">
                                        <div className="text-slate-500">IndexedDB</div>
                                        <div className="font-bold text-slate-200">{diagnostics.storage?.indexed_db_available ? 'Enabled' : 'Unavailable'}</div>
                                    </div>
                                    <div className="rounded-lg border border-slate-800 bg-slate-900/70 px-2 py-1.5">
                                        <div className="text-slate-500">Queue DB</div>
                                        <div className="font-bold text-slate-200">v{diagnostics.storage?.database_version || 'N/A'}</div>
                                    </div>
                                    <div className="rounded-lg border border-slate-800 bg-slate-900/70 px-2 py-1.5">
                                        <div className="text-slate-500">Hash Chain</div>
                                        <div className={`font-bold ${diagnostics.hash_chain_valid ? 'text-emerald-300' : 'text-rose-300'}`}>
                                            {diagnostics.hash_chain_valid ? 'Verified' : 'Needs Review'}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border border-slate-800 bg-slate-900/70 px-2 py-1.5">
                                        <div className="text-slate-500">Stored Records</div>
                                        <div className="font-bold text-slate-200">{diagnostics.historical_record_count}</div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Sync Queue Actions */}
                        {!isOffline && (queueSummary.pending > 0 || queueSummary.failed > 0) && (
                            <div className="p-3 bg-indigo-950/10 border-b border-indigo-950/20 px-4 flex justify-between items-center">
                                <span className="text-[11px] text-slate-400">Connection is available. Ready to upload.</span>
                                <button
                                    onClick={handleForceSync}
                                    disabled={syncingQueue}
                                    className="px-3 py-1 bg-indigo-650 hover:bg-indigo-600 disabled:bg-slate-800 text-white rounded text-[10px] uppercase font-bold tracking-wider transition flex items-center gap-1"
                                >
                                    {syncingQueue ? <Loader2 className="w-2.5 h-2.5 animate-spin" /> : <RefreshCw className="w-2.5 h-2.5" />}
                                    Sync Queue
                                </button>
                            </div>
                        )}

                        {/* Transaction List */}
                        <div className="flex-1 overflow-y-auto p-4 space-y-3">
                            {transactions.length === 0 ? (
                                <div className="h-48 flex flex-col items-center justify-center text-slate-500 gap-1.5">
                                    <CheckCircle2 className="w-8 h-8 text-slate-600" />
                                    <span className="text-xs">Offline sync queue is completely empty!</span>
                                </div>
                            ) : (
                                transactions.map((tx) => (
                                    <div key={tx.id} className="bg-slate-950/50 rounded-xl border border-slate-800/60 p-3.5 space-y-2.5 transition hover:border-slate-700/50">
                                        <div className="flex items-center justify-between">
                                            <div className="font-mono text-xs font-bold text-indigo-300">
                                                    {tx.payload?.local_transaction_reference || tx.offline_sequence || 'NO REF'}
                                            </div>
                                            <div className={`px-2 py-0.5 border rounded-full text-[9px] uppercase font-bold tracking-wider ${getStatusStyles(tx.status)}`}>
                                                {getStatusLabel(tx.status)}
                                            </div>
                                        </div>

                                        <div className="flex justify-between items-end text-xs">
                                            <div className="text-slate-400 text-[10px] space-y-0.5">
                                                <div>Time: {new Date(tx.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</div>
                                                <div>Shift ID: {tx.payload?.cashier_shift_id ? tx.payload.cashier_shift_id.substring(0, 8) + '...' : 'Unknown'}</div>
                                            </div>
                                            <div className="text-right">
                                                <div className="font-semibold text-slate-200">
                                                    {formatCentavos(tx.payload?.gross_amount_centavos || (parseFloat(tx.client_totals.total) * 100))}
                                                </div>
                                                <div className="text-[10px] text-slate-500">
                                                    {tx.queue_type === 'server_sale_payment' ? 'Sale Payment' : 'Cash Sale'}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Status Warnings/Errors */}
                                        {tx.status === 'conflict' && (
                                            <div className="bg-rose-500/10 border border-rose-500/20 text-rose-200 text-[10px] p-2.5 rounded-lg flex gap-1.5 items-start">
                                                <ShieldAlert className="w-3.5 h-3.5 text-rose-400 shrink-0 mt-0.5" />
                                                <div>
                                                    <span className="font-bold uppercase tracking-wider block text-[9px] text-rose-400">Sync Conflict</span>
                                                    {tx.error_message || 'Transaction reconciliation conflict detected. Supervisor intervention required.'}
                                                </div>
                                            </div>
                                        )}
                                        {tx.status === 'failed' && (
                                            <div className="bg-rose-500/10 border border-rose-500/20 text-rose-300 text-[10px] p-2 rounded flex gap-1.5 items-start">
                                                <XCircle className="w-3.5 h-3.5 text-rose-400 shrink-0 mt-0.5" />
                                                <div className="truncate">
                                                    {tx.error_message || 'Connection failed. Retrying...'}
                                                </div>
                                            </div>
                                        )}
                                        {tx.status === 'accepted_with_warning' && (
                                            <div className="bg-amber-500/10 border border-amber-500/20 text-amber-200 text-[10px] p-2 rounded flex gap-1.5 items-start">
                                                <AlertTriangle className="w-3.5 h-3.5 text-amber-400 shrink-0 mt-0.5" />
                                                <div>
                                                    <span className="font-bold uppercase tracking-wider block text-[9px] text-amber-400">Reconciled with warning</span>
                                                    {tx.error_message || 'Sale accepted using cached catalog configuration.'}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>

                        {/* Footer Context */}
                        <div className="p-4 bg-slate-950 border-t border-slate-800 text-[10px] text-slate-500 space-y-1">
                            {queueSummary.lastSuccessfulSyncAt && (
                                <div>Last successful sync: {new Date(queueSummary.lastSuccessfulSyncAt).toLocaleString()}</div>
                            )}
                            <div>PWA Offline Storage (IndexedDB): Enabled</div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
