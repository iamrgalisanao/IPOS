import { useState, useEffect } from 'react';
import axios from 'axios';
import { catalogCache } from './catalogCache.ts';

export type ConnectivityStatus = 'online' | 'offline' | 'checking';

export interface ConnectivityState {
    status: ConnectivityStatus;
    isStale: boolean;
    lastSyncedAt: string | null;
    terminalContextInvalid: boolean;
}

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
};

const listeners = new Set<(state: ConnectivityState) => void>();
let inFlightConnectivityCheck: Promise<boolean> | null = null;
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

// Function to perform real end-to-end network ping check
export async function checkConnectivity(currentTaxHash?: string, options: { force?: boolean } = {}): Promise<boolean> {
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
             const serverHash = res.data?.tax_configuration_version_hash || null;
             // Check staleness of cache using the retrieved server hash
             const stale = await catalogCache.isStale(serverHash);
             if (stale) {
                 await catalogCache.writeBootstrapPayload(res.data);
             }
             setGlobalState({
                 status: 'online',
                 isStale: false,
                 lastSyncedAt: res.data?.generated_at || null,
                 terminalContextInvalid: false,
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

    const triggerSync = async (): Promise<boolean> => {
        if (globalState.status === 'offline') {
            return false;
        }
        setGlobalState({ status: 'checking' });
        try {
            const payload = await catalogCache.fetchAndStoreBootstrap();
            setGlobalState({
                status: 'online',
                isStale: false,
                lastSyncedAt: payload.generated_at,
                terminalContextInvalid: false,
            });
            return true;
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
            console.error('Trigger sync failed:', err);
            // Recheck connection state on failure
            await checkConnectivity();
            return false;
        }
    };

    return {
        status: state.status,
        isOnline: state.status === 'online',
        isOffline: state.status === 'offline',
        isChecking: state.status === 'checking',
        isStale: state.isStale,
        lastSyncedAt: state.lastSyncedAt,
        terminalContextInvalid: state.terminalContextInvalid,
        checkConnectivity: () => checkConnectivity(undefined, { force: true }),
        triggerSync,
    };
}
