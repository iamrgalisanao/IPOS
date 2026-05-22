import React, { useEffect, useState } from 'react';
import { Wifi, WifiOff, RefreshCw, AlertTriangle, Loader2 } from 'lucide-react';
import { useConnectivityStore } from '@/POS/offline/connectivityStore';
import { resolveOfflineCaptureReadiness } from '@/POS/offline/offlineGuards';

export default function ConnectivityBanner() {
    const {
        status,
        isOffline,
        isStale,
        isChecking,
        triggerSync,
        checkConnectivity,
        lastSyncedAt
    } = useConnectivityStore();
    const [offlineReadiness, setOfflineReadiness] = useState({ allowed: false, message: '' });

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

    if (!isOffline && !isStale && !isChecking) {
        return null;
    }

    return (
        <div className="w-full shrink-0 z-50">
            {isOffline && (
                <div className={`${offlineReadiness.allowed ? 'bg-gradient-to-r from-amber-950 via-amber-900 to-amber-950 border-amber-800/40 text-amber-100 shadow-amber-950/20' : 'bg-gradient-to-r from-rose-950 via-rose-900 to-rose-950 border-rose-800/40 text-rose-100 shadow-rose-950/20'} border-b px-4 py-2.5 flex items-center justify-between text-xs font-semibold shadow-lg`}>
                    <div className="flex items-center gap-2">
                        <WifiOff className={`w-4 h-4 animate-pulse ${offlineReadiness.allowed ? 'text-amber-400' : 'text-rose-400'}`} />
                        <span>
                            {offlineReadiness.allowed
                                ? 'Terminal is offline. Provisional offline capture is enabled on this terminal. Pending server synchronization and reconciliation. This is not final ledger posting.'
                                : offlineReadiness.message || 'Terminal is offline. Cached product catalog active. Checkouts are locked until online connection is restored.'}
                        </span>
                    </div>
                    <button
                        onClick={checkConnectivity}
                        disabled={isChecking}
                        className={`px-3 py-1 ${offlineReadiness.allowed ? 'bg-amber-800 hover:bg-amber-700' : 'bg-rose-800 hover:bg-rose-700'} text-white rounded-lg flex items-center gap-1.5 transition-all text-[11px] font-black uppercase tracking-wider`}
                    >
                        {isChecking ? (
                            <Loader2 className="w-3 h-3 animate-spin" />
                        ) : (
                            <RefreshCw className="w-3 h-3" />
                        )}
                        Check Connection
                    </button>
                </div>
            )}

            {!isOffline && isStale && (
                <div className="bg-gradient-to-r from-amber-950 via-amber-900 to-amber-950 border-b border-amber-800/40 text-amber-100 px-4 py-2.5 flex items-center justify-between text-xs font-semibold shadow-lg shadow-amber-950/20">
                    <div className="flex items-center gap-2">
                        <AlertTriangle className="w-4 h-4 text-amber-400 animate-bounce" />
                        <span>
                            Local database configuration is outdated. Sync configuration to ensure compliant tax calculations.
                        </span>
                    </div>
                    <button
                        onClick={triggerSync}
                        disabled={isChecking}
                        className="px-3 py-1 bg-amber-800 hover:bg-amber-750 text-white rounded-lg flex items-center gap-1.5 transition-all text-[11px] font-black uppercase tracking-wider"
                    >
                        {isChecking ? (
                            <Loader2 className="w-3 h-3 animate-spin" />
                        ) : (
                            <RefreshCw className="w-3 h-3" />
                        )}
                        Sync Now
                    </button>
                </div>
            )}

            {!isOffline && !isStale && isChecking && (
                <div className="bg-gradient-to-r from-indigo-950 via-indigo-900 to-indigo-950 border-b border-indigo-800/40 text-indigo-100 px-4 py-2 flex items-center justify-center gap-2 text-xs font-semibold">
                    <Loader2 className="w-3.5 h-3.5 animate-spin text-indigo-400" />
                    <span>Synchronizing local POS configurations...</span>
                </div>
            )}
        </div>
    );
}
