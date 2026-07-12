import { useState, useEffect } from 'react';
import axios from 'axios';
import { catalogCache, validateBootstrapPayload } from './catalogCache.ts';

export type ConnectivityStatus = 'online' | 'offline' | 'checking';

export interface ConnectivityState {
    status: ConnectivityStatus;
    isStale: boolean;
    lastSyncedAt: string | null;
    terminalContextInvalid: boolean;
    lastSnapshotHash: string | null;
    refreshResult: ConfigurationRefreshResult;
}

export type ConfigurationRefreshStatus = 'idle' | 'refreshing' | 'success' | 'stale' | 'offline' | 'failure' | 'invalid-terminal';

export interface ConfigurationRefreshResult {
    status: ConfigurationRefreshStatus;
    message: string;
    generatedAt: string | null;
    snapshotHash: string | null;
    completedAt: string | null;
}

const idleRefreshResult: ConfigurationRefreshResult = {
    status: 'idle',
    message: 'Configuration has not been manually refreshed in this session.',
    generatedAt: null,
    snapshotHash: null,
    completedAt: null,
};

const getInitialConnectivityStatus = (): ConnectivityStatus => {
    if (typeof navigator === 'undefined') {
        return 'checking';
    }

    return navigator.onLine ? 'checking' : 'offline';
};

export const globalState: ConnectivityState = {
    status: getInitialConnectivityStatus(),
    isStale: false,
    lastSyncedAt: null,
    terminalContextInvalid: false,
    lastSnapshotHash: null,
    refreshResult: idleRefreshResult,
};

const listeners = new Set<(state: ConnectivityState) => void>();
let inFlightConnectivityCheck: Promise<boolean> | null = null;
let inFlightConfigurationRefresh: Promise<ConfigurationRefreshResult> | null = null;
let lastConnectivityFailureAt = 0;
const OFFLINE_RECHECK_COOLDOWN_MS = 10000;

function setGlobalState(newState: Partial<ConnectivityState>) {
    Object.assign(globalState, newState);
    listeners.forEach((listener) => listener(globalState));
}

function isExpectedReachabilityFailure(error: any): boolean {
    return (
        error?.response?.status === 401 ||
        error?.response?.status === 419 ||
        error?.message === 'Network Error' ||
        error?.code === 'ERR_NETWORK' ||
        error?.code === 'ECONNABORTED' ||
        (error?.request && !error?.response)
    );
}

function refreshResult(status: ConfigurationRefreshStatus, message: string, payload?: any): ConfigurationRefreshResult {
    return {
        status,
        message,
        generatedAt: payload?.generated_at || null,
        snapshotHash: payload?.config_snapshot_hash || null,
        completedAt: status === 'refreshing' ? null : new Date().toISOString(),
    };
}

// Function to perform real end-to-end network ping check
export async function checkConnectivity(currentTaxHash?: string, options: { force?: boolean } = {}): Promise<boolean> {
    if (inFlightConfigurationRefresh) {
        return (await inFlightConfigurationRefresh).status === 'success';
    }

    if (inFlightConnectivityCheck) {
        return inFlightConnectivityCheck;
    }

    if (!options.force && globalState.status === 'offline' && Date.now() - lastConnectivityFailureAt < OFFLINE_RECHECK_COOLDOWN_MS) {
        return false;
    }

    const isNavOffline = typeof navigator !== 'undefined' && !navigator.onLine;
    if (isNavOffline) {
        setGlobalState({ status: 'offline' });
        return false;
    }

    setGlobalState({ status: 'checking' });

    inFlightConnectivityCheck = (async () => {
    try {
        const res = await axios.get('/api/pos/bootstrap-cache', { timeout: 4000 });
        if (res.status === 200) {
             validateBootstrapPayload(res.data);
             const cached = await catalogCache.getConfigSnapshotMetadata();
             const hasCachedSnapshot = Boolean(cached.config_snapshot_hash && cached.generated_at);
             const stale = hasCachedSnapshot
                 ? await catalogCache.isStale(currentTaxHash || res.data.tax_configuration_version_hash || null)
                    || cached.config_snapshot_hash !== res.data.config_snapshot_hash
                 : false;

             if (!hasCachedSnapshot) {
                 await catalogCache.writeBootstrapPayload(res.data);
             }
             setGlobalState({
                 status: 'online',
                 isStale: stale,
                 lastSyncedAt: hasCachedSnapshot ? cached.generated_at : res.data.generated_at,
                 lastSnapshotHash: hasCachedSnapshot ? cached.config_snapshot_hash : res.data.config_snapshot_hash,
                 terminalContextInvalid: false,
                 ...(stale ? {
                     refreshResult: refreshResult(
                         'stale',
                         'A newer or expired configuration was detected. Refresh configuration before relying on updated settings.',
                         cached,
                     ),
                 } : globalState.refreshResult.status === 'stale' ? {
                     refreshResult: idleRefreshResult,
                 } : {}),
             });
             return true;
        } else {
            setGlobalState({ status: 'offline' });
            return false;
        }
    } catch (err) {
        const status = err?.response?.status;
        const data = err?.response?.data || {};
        if (status === 403 && data.code === 'TERMINAL_CONTEXT_INVALID') {
            setGlobalState({
                status: 'offline',
                terminalContextInvalid: true
            });
            return false;
        }
        if (!isExpectedReachabilityFailure(err)) {
            console.error('checkConnectivity failed:', err);
        }
        lastConnectivityFailureAt = Date.now();
        setGlobalState({
            status: 'offline',
            terminalContextInvalid: globalState.terminalContextInvalid,
        });
        return false;
    } finally {
        inFlightConnectivityCheck = null;
    }
    })();

    return inFlightConnectivityCheck;
}

export function refreshConfiguration(): Promise<ConfigurationRefreshResult> {
    if (inFlightConfigurationRefresh) {
        return inFlightConfigurationRefresh;
    }

    const isNavOffline = typeof navigator !== 'undefined' && !navigator.onLine;
    if (isNavOffline) {
        const result = refreshResult('offline', 'No configuration was downloaded because this terminal is offline. The previous configuration remains available.');
        setGlobalState({ status: 'offline', refreshResult: result });
        return Promise.resolve(result);
    }

    setGlobalState({
        refreshResult: refreshResult('refreshing', 'Downloading and saving the latest terminal configuration...'),
    });

    inFlightConfigurationRefresh = (async () => {
        try {
            if (inFlightConnectivityCheck) {
                await inFlightConnectivityCheck;
            }
            const payload = await catalogCache.fetchAndStoreBootstrap();
            const result = refreshResult('success', 'Configuration downloaded and saved. Offline transaction uploads were not changed.', payload);
            setGlobalState({
                status: 'online',
                isStale: false,
                lastSyncedAt: payload.generated_at,
                lastSnapshotHash: payload.config_snapshot_hash || null,
                terminalContextInvalid: false,
                refreshResult: result,
            });
            return result;
        } catch (err: any) {
            const status = err?.response?.status;
            const data = err?.response?.data || {};
            let result: ConfigurationRefreshResult;

            if (status === 403 && data.code === 'TERMINAL_CONTEXT_INVALID') {
                result = refreshResult('invalid-terminal', 'This terminal must be activated again before configuration can be refreshed. The previous cache was not deleted.');
                setGlobalState({ status: 'offline', terminalContextInvalid: true, refreshResult: result });
                return result;
            }

            if (isExpectedReachabilityFailure(err) && !err?.response) {
                result = refreshResult('offline', 'The configuration server could not be reached. No replacement was downloaded and the previous configuration remains available.');
                setGlobalState({ status: 'offline', refreshResult: result });
                return result;
            }

            result = refreshResult('failure', 'Configuration refresh failed before a replacement could be saved. The previous configuration remains available.');
            setGlobalState({ refreshResult: result });
            return result;
        } finally {
            inFlightConfigurationRefresh = null;
        }
    })();

    return inFlightConfigurationRefresh;
}

// Initialize listeners for browser-level events
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        void checkConnectivity(undefined, { force: true }).catch(() => undefined);
    });

    window.addEventListener('offline', () => {
        setGlobalState({ status: 'offline' });
    });

}

export function useConnectivityStore() {
    const [state, setState] = useState<ConnectivityState>(globalState);

    useEffect(() => {
        const handleChange = (newState: ConnectivityState) => {
            setState({ ...newState });
        };
        listeners.add(handleChange);
        
        // Periodically verify connectivity every 30 seconds if tab is active
        const interval = setInterval(() => {
            if (typeof document !== 'undefined' && !document.hidden && globalState.status !== 'offline') {
                checkConnectivity();
            }
        }, 30000);

        return () => {
            listeners.delete(handleChange);
            clearInterval(interval);
        };
    }, []);

    const triggerSync = async (): Promise<boolean> => (await refreshConfiguration()).status === 'success';
    const dismissRefreshResult = () => setGlobalState({ refreshResult: idleRefreshResult });

    return {
        status: state.status,
        isOnline: state.status === 'online',
        isOffline: state.status === 'offline',
        isChecking: state.status === 'checking',
        isStale: state.isStale,
        lastSyncedAt: state.lastSyncedAt,
        terminalContextInvalid: state.terminalContextInvalid,
        lastSnapshotHash: state.lastSnapshotHash,
        refreshResult: state.refreshResult,
        checkConnectivity: () => checkConnectivity(undefined, { force: true }),
        refreshConfiguration,
        dismissRefreshResult,
        triggerSync,
    };
}
