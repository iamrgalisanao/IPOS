import { useState, useEffect } from 'react';
import axios from 'axios';
import { catalogCache } from './catalogCache.ts';

export type ConnectivityStatus = 'online' | 'offline' | 'checking';

export interface ConnectivityState {
    status: ConnectivityStatus;
    isStale: boolean;
    lastSyncedAt: string | null;
}

export let globalState: ConnectivityState = {
    status: typeof navigator !== 'undefined' && navigator.onLine ? 'online' : 'offline',
    isStale: false,
    lastSyncedAt: null,
};

const listeners = new Set<(state: ConnectivityState) => void>();

function setGlobalState(newState: Partial<ConnectivityState>) {
    globalState = { ...globalState, ...newState };
    listeners.forEach((listener) => listener(globalState));
}

// Function to perform real end-to-end network ping check
export async function checkConnectivity(currentTaxHash?: string): Promise<boolean> {
    const isNavOffline = typeof navigator !== 'undefined' && !navigator.onLine;
    if (isNavOffline) {
        setGlobalState({ status: 'offline' });
        return false;
    }

    setGlobalState({ status: 'checking' });
    try {
        const res = await axios.get('/api/pos/bootstrap-cache', { timeout: 4000 });
        if (res.status === 200) {
            const serverHash = res.data?.tax_configuration_version_hash || null;
            // Check staleness of cache using the retrieved server hash
            const stale = await catalogCache.isStale(serverHash);
            setGlobalState({
                status: 'online',
                isStale: stale,
                lastSyncedAt: res.data?.generated_at || null,
            });
            return true;
        } else {
            setGlobalState({ status: 'offline' });
            return false;
        }
    } catch (err) {
        setGlobalState({ status: 'offline' });
        return false;
    }
}

// Initialize listeners for browser-level events
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        checkConnectivity();
    });

    window.addEventListener('offline', () => {
        setGlobalState({ status: 'offline' });
    });

    // Check on startup
    checkConnectivity();
}

export function useConnectivityStore() {
    const [state, setState] = useState<ConnectivityState>(globalState);

    useEffect(() => {
        const handleChange = (newState: ConnectivityState) => {
            setState(newState);
        };
        listeners.add(handleChange);
        
        // Trigger check on mount
        checkConnectivity();

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
            });
            return true;
        } catch (err) {
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
        checkConnectivity,
        triggerSync,
    };
}
