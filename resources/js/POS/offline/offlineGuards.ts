import { globalState } from './connectivityStore.ts';
import { catalogCache } from './catalogCache.ts';

export interface OfflineCaptureReadiness {
    allowed: boolean;
    reason: string;
    message: string;
    machineProfile: Record<string, any> | null;
}

export function isOffline(): boolean {
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
        return true;
    }
    return globalState.status === 'offline';
}

export async function resolveOfflineCaptureReadiness(): Promise<OfflineCaptureReadiness> {
    try {
        const catalog = await catalogCache.getCachedCatalog();
        const tenant = catalog.tenant_context;
        const branch = catalog.branch_context;
        const machine = catalog.machine_profile_context;

        if (!tenant || !branch || !machine) {
            return {
                allowed: false,
                reason: 'missing_terminal_context',
                message: 'Terminal registration data is incomplete. Reconnect to refresh this terminal before capturing offline transactions.',
                machineProfile: machine ?? null,
            };
        }

        if (!tenant.offline_sales_enabled) {
            return {
                allowed: false,
                reason: 'tenant_disabled',
                message: 'Controlled offline sales are disabled for this tenant. Reconnect and contact an administrator if this is unexpected.',
                machineProfile: machine,
            };
        }

        if (!branch.offline_sales_enabled) {
            return {
                allowed: false,
                reason: 'branch_disabled',
                message: 'Controlled offline sales are disabled for this branch. Reconnect and contact an administrator if this is unexpected.',
                machineProfile: machine,
            };
        }

        if (machine.status !== 'active') {
            return {
                allowed: false,
                reason: 'terminal_inactive',
                message: 'This terminal is not active for controlled offline sales. Reconnect and verify the terminal status.',
                machineProfile: machine,
            };
        }

        if (machine.offline_sales_enabled === false) {
            return {
                allowed: false,
                reason: 'terminal_disabled',
                message: 'Controlled offline sales are disabled for this terminal. Reconnect and verify terminal settings.',
                machineProfile: machine,
            };
        }

        if (!machine.offline_sequence_prefix) {
            return {
                allowed: false,
                reason: 'missing_prefix',
                message: 'This terminal is missing its offline sequence prefix. Reconnect before capturing offline transactions.',
                machineProfile: machine,
            };
        }

        if ((machine.offline_sequence_status ?? 'active') !== 'active') {
            return {
                allowed: false,
                reason: 'sequence_inactive',
                message: 'This terminal offline sequence is not active. Reconnect before capturing offline transactions.',
                machineProfile: machine,
            };
        }

        if (!Number.isInteger(machine.offline_sequence_next_value) || machine.offline_sequence_next_value < 1) {
            return {
                allowed: false,
                reason: 'missing_sequence_state',
                message: 'Offline sequence state is unavailable on this terminal. Reconnect before capturing offline transactions.',
                machineProfile: machine,
            };
        }

        if (!catalog.generated_at) {
            return {
                allowed: false,
                reason: 'missing_cache_timestamp',
                message: 'Terminal registration cache is missing a freshness timestamp. Reconnect before capturing offline transactions.',
                machineProfile: machine,
            };
        }

        const generatedTime = new Date(catalog.generated_at).getTime();
        const maxAgeMs = 72 * 60 * 60 * 1000;
        if (Number.isNaN(generatedTime) || Date.now() - generatedTime > maxAgeMs) {
            return {
                allowed: false,
                reason: 'stale_registration_cache',
                message: 'Terminal registration cache is older than 72 hours. Reconnect before capturing offline transactions.',
                machineProfile: machine,
            };
        }

        if (!catalog.tax_configuration_version_hash) {
            return {
                allowed: false,
                reason: 'missing_tax_hash',
                message: 'Tax configuration hash is unavailable in the local cache. Reconnect before capturing offline transactions.',
                machineProfile: machine,
            };
        }

        return {
            allowed: true,
            reason: 'allowed',
            message: 'Controlled offline sales are available on this terminal.',
            machineProfile: machine,
        };
    } catch (err) {
        console.error('Error checking offline capture eligibility:', err);
        return {
            allowed: false,
            reason: 'cache_unavailable',
            message: 'Offline readiness could not be verified from the local cache. Reconnect before capturing offline transactions.',
            machineProfile: null,
        };
    }
}

export async function canCaptureOffline(): Promise<boolean> {
    const readiness = await resolveOfflineCaptureReadiness();
    return readiness.allowed;
}

export async function validateCheckoutAllowed(): Promise<void> {
    if (isOffline()) {
        const readiness = await resolveOfflineCaptureReadiness();
        if (!readiness.allowed) {
            throw new Error(readiness.message);
        }
    }
}
