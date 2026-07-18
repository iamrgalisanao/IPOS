import { v4 as uuidv4 } from 'uuid';

export type OfflineSyncStatus =
    | 'pending'
    | 'syncing'
    | 'synced'
    | 'failed'
    | 'conflict'
    | 'accepted_with_warning'
    | 'cancelled';

export type OfflinePersistenceState =
    | 'creating'
    | 'persisting'
    | 'durably_captured'
    | 'capture_uncertain'
    | 'storage_failed';

export type OfflineQueueState =
    | 'pending'
    | 'leased'
    | 'syncing'
    | 'retry_scheduled'
    | 'blocked'
    | 'processing_complete';

export type OfflineServerState =
    | 'not_submitted'
    | 'accepted'
    | 'replayed'
    | 'retryable_failed'
    | 'review_required'
    | 'rejected';

export type OfflineResolutionState =
    | 'none'
    | 'pending_support'
    | 'resolved_posted'
    | 'resolved_cash_returned'
    | 'resolved_rejected';

export type OfflineRetentionState =
    | 'full_payload'
    | 'retained_full'
    | 'compacted'
    | 'purged';

export type OfflineStorageState =
    | 'storage_available'
    | 'queue_capacity_warning'
    | 'queue_capacity_block'
    | 'storage_unavailable'
    | 'storage_corrupt';

export type OfflineQueueHealthState =
    | 'healthy'
    | 'warning'
    | 'blocked'
    | 'support_required';

export type OfflineTerminalRecoveryState =
    | 'none'
    | 'possible_storage_loss'
    | 'orphan_terminal_missing'
    | 'orphan_terminal_mismatch'
    | 'orphan_epoch_mismatch'
    | 'orphan_binding_revoked'
    | 'orphan_identity_unverifiable';

export type OfflineQueueLeasePurpose =
    | 'sync'
    | 'maintenance'
    | 'migration'
    | 'compaction';

export interface OfflineQueueLease {
    lease_id: string | null;
    queue_owner_instance_id: string | null;
    lease_acquired_at: string | null;
    lease_expires_at: string | null;
    lease_heartbeat_at: string | null;
    worker_type: string | null;
    worker_version: string | null;
    lease_purpose?: OfflineQueueLeasePurpose | null;
}

export interface OfflineSyncAttemptGuard {
    leaseId: string;
    syncAttemptId: string;
    attemptGeneration: number;
    ownerInstanceId?: string;
}

export interface OfflineTransactionEnvelope {
    id: string; // uuid
    offline_transaction_uuid?: string;
    terminal_id?: string | null;
    terminal_binding_epoch?: string | null;
    local_sequence?: string;
    schema_version?: number;
    fingerprint_version?: string;
    checksum_version?: string;
    batch_reference: string;
    offline_sequence: string;
    payload: any; // Original payload from cart
    payload_hash: string;
    payload_checksum?: string;
    previous_hash: string | null;
    row_hash: string;
    status: OfflineSyncStatus;
    persistence_state?: OfflinePersistenceState;
    queue_state?: OfflineQueueState;
    server_state?: OfflineServerState;
    resolution_state?: OfflineResolutionState;
    retention_state?: OfflineRetentionState;
    queue_state_revision?: number;
    lease?: OfflineQueueLease;
    created_at: string;
    updated_at: string;
    last_sync_attempt_at?: string | null;
    last_synced_at?: string | null;
    next_retry_at?: string | null;
    retry_count?: number;
    last_error_category?: string | null;
    last_error_code?: string | null;
    last_sync_attempt_id?: string | null;
    last_attempt_generation?: number;
    client_totals: {
        total: string; // Decimal string
        tax: string; // Decimal string
        subtotal: string; // Decimal string
    };
    error_message?: string;
}

export interface OfflineQueueHealthSnapshot {
    terminal_id: string | null;
    terminal_binding_epoch: string | null;
    highest_local_sequence: string | null;
    unresolved_count: number;
    accepted_tombstone_count: number;
    queue_schema_version: number;
    storage_state: OfflineStorageState;
    queue_health: OfflineQueueHealthState;
    terminal_recovery_state: OfflineTerminalRecoveryState;
    reported_at: string;
}

export interface OfflineQueueSummary {
    pending: number;
    syncing: number;
    synced: number;
    failed: number;
    conflict: number;
    accepted_with_warning: number;
    cancelled: number;
    total: number;
    lastSyncAttemptAt: string | null;
    lastSuccessfulSyncAt: string | null;
}

export interface OfflineQueueDiagnosticRecord {
    id: string;
    offline_transaction_uuid?: string | null;
    terminal_binding_epoch?: string | null;
    local_sequence?: string | null;
    batch_reference: string;
    offline_sequence: string;
    status: OfflineSyncStatus;
    persistence_state?: OfflinePersistenceState;
    queue_state?: OfflineQueueState;
    server_state?: OfflineServerState;
    resolution_state?: OfflineResolutionState;
    retention_state?: OfflineRetentionState;
    queue_state_revision?: number;
    lease_id?: string | null;
    queue_owner_instance_id?: string | null;
    lease_expires_at?: string | null;
    retry_count?: number;
    next_retry_at?: string | null;
    last_error_category?: string | null;
    last_error_code?: string | null;
    created_at: string;
    updated_at: string;
    last_sync_attempt_at?: string | null;
    last_synced_at?: string | null;
    local_transaction_reference?: string | null;
    terminal_id?: string | null;
    branch_id?: string | null;
    cashier_shift_id?: string | null;
    gross_amount_centavos?: number | string | null;
    client_total?: string | null;
    error_message?: string | null;
    payload_hash: string;
    previous_hash: string | null;
    row_hash: string;
}

export interface OfflineQueueDiagnosticsBundle {
    export_id?: string;
    generated_at: string;
    generated_by?: string | null;
    filter_summary?: Record<string, any>;
    export_checksum?: string;
    label?: 'provisional local evidence';
    storage: {
        indexed_db_available: boolean;
        database_name: string;
        database_version: number;
        object_stores: string[];
        persistent_storage_capability?: Record<string, any> | null;
        storage_state?: OfflineStorageState;
        queue_health?: OfflineQueueHealthState;
        terminal_recovery_state?: OfflineTerminalRecoveryState;
        last_queue_health_heartbeat?: OfflineQueueHealthSnapshot | null;
    };
    summary: OfflineQueueSummary;
    hash_chain_valid: boolean;
    active_record_count: number;
    historical_record_count: number;
    tombstone_count?: number;
    records: OfflineQueueDiagnosticRecord[];
}

export interface OfflineDiagnosticsExportOptions {
    generatedBy?: string | null;
    status?: OfflineSyncStatus;
    offlineTransactionUuid?: string;
    localSequenceFrom?: string;
    localSequenceTo?: string;
    cashExposure?: string;
    epoch?: string;
    from?: string;
    to?: string;
}

const DB_NAME = 'ipos_pos_offline_queue';
const DB_VERSION = 3;
const ENVELOPE_SCHEMA_VERSION = 2;
const FINGERPRINT_VERSION = 'ipos-offline-envelope-v1';
const CHECKSUM_VERSION = 'sha-256-canonical-json-v1';
const TOMBSTONE_CHECKSUM_VERSION = 'sha-256-canonical-json-v1';
const UNRESOLVED_STATUSES: OfflineSyncStatus[] = ['pending', 'syncing', 'failed', 'conflict', 'accepted_with_warning'];
const PRUNABLE_STATUSES: OfflineSyncStatus[] = ['synced', 'cancelled'];
const STORAGE_STATES: OfflineStorageState[] = [
    'storage_available',
    'queue_capacity_warning',
    'queue_capacity_block',
    'storage_unavailable',
    'storage_corrupt',
];
const listeners = new Set<() => void>();

export function canonicalizeOfflineValue(value: any, keyName: string | null = null): any {
    if (Array.isArray(value)) {
        return value.map((item) => canonicalizeOfflineValue(item));
    }

    if (value && typeof value === 'object') {
        return Object.keys(value)
            .sort()
            .reduce((acc, key) => {
                acc[key] = canonicalizeOfflineValue(value[key], key);
                return acc;
            }, {} as Record<string, any>);
    }

    if (typeof value === 'string') {
        const normalized = value.normalize('NFC');
        if (keyName && /currency/i.test(keyName)) {
            return normalized.toUpperCase();
        }

        if (/^-?\d+(\.\d+)?$/.test(normalized)) {
            return normalized;
        }

        if (/e/i.test(normalized) && /^-?\d+(\.\d+)?e[+-]?\d+$/i.test(normalized)) {
            throw new Error(`Scientific notation is not allowed in offline queue canonical payloads (${keyName || 'value'}).`);
        }

        return normalized;
    }

    if (typeof value === 'number' && !Number.isFinite(value)) {
        throw new Error(`Non-finite number is not allowed in offline queue canonical payloads (${keyName || 'value'}).`);
    }

    return value;
}

export function canonicalizeOfflineEnvelope(payload: any, version = CHECKSUM_VERSION): string {
    if (version !== CHECKSUM_VERSION) {
        throw new Error(`Unsupported offline envelope canonicalization version: ${version}`);
    }

    const payloadForHash = JSON.parse(JSON.stringify(payload));
    delete (payloadForHash as Record<string, any>).payload_hash;
    delete (payloadForHash as Record<string, any>).payload_checksum;

    return JSON.stringify(canonicalizeOfflineValue(payloadForHash));
}

export function canonicalizeOfflineTombstone(tombstone: any, version = TOMBSTONE_CHECKSUM_VERSION): string {
    if (version !== TOMBSTONE_CHECKSUM_VERSION) {
        throw new Error(`Unsupported offline tombstone canonicalization version: ${version}`);
    }

    const tombstoneForHash = JSON.parse(JSON.stringify(tombstone));
    delete (tombstoneForHash as Record<string, any>).tombstone_checksum;
    delete (tombstoneForHash as Record<string, any>).tombstone_checksum_algorithm;

    return JSON.stringify(canonicalizeOfflineValue(tombstoneForHash));
}

export class OfflineSalesQueueService {
    private db: IDBDatabase | null = null;

    subscribe(listener: () => void): () => void {
        listeners.add(listener);
        return () => listeners.delete(listener);
    }

    private emitChange(): void {
        listeners.forEach((listener) => listener());
    }

    async initDb(): Promise<IDBDatabase> {
        if (this.db) return this.db;

        const idb = typeof indexedDB !== 'undefined' ? indexedDB : (global as any).indexedDB;
        if (!idb) {
            throw new Error('IndexedDB is not supported/available in this environment.');
        }

        return new Promise((resolve, reject) => {
            const request = idb.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (event: any) => {
                const db = request.result;
                if (!db.objectStoreNames.contains('transactions')) {
                    const store = db.createObjectStore('transactions', { keyPath: 'id' });
                    store.createIndex('status', 'status', { unique: false });
                    store.createIndex('created_at', 'created_at', { unique: false });
                    store.createIndex('offline_transaction_uuid', 'offline_transaction_uuid', { unique: true });
                    store.createIndex('terminal_sequence', ['terminal_id', 'terminal_binding_epoch', 'local_sequence'], { unique: true });
                } else {
                    const store = request.transaction?.objectStore('transactions');
                    if (store && !store.indexNames.contains('offline_transaction_uuid')) {
                        store.createIndex('offline_transaction_uuid', 'offline_transaction_uuid', { unique: true });
                    }
                    if (store && !store.indexNames.contains('terminal_sequence')) {
                        store.createIndex('terminal_sequence', ['terminal_id', 'terminal_binding_epoch', 'local_sequence'], { unique: true });
                    }
                }
                if (!db.objectStoreNames.contains('metadata')) {
                    db.createObjectStore('metadata');
                }
                if (!db.objectStoreNames.contains('offline_queue_state')) {
                    const store = db.createObjectStore('offline_queue_state', { keyPath: 'id' });
                    store.createIndex('queue_state', 'queue_state', { unique: false });
                    store.createIndex('server_state', 'server_state', { unique: false });
                    store.createIndex('updated_at', 'updated_at', { unique: false });
                }
                if (!db.objectStoreNames.contains('offline_status_events')) {
                    const store = db.createObjectStore('offline_status_events', { keyPath: 'id' });
                    store.createIndex('offline_transaction_uuid', 'offline_transaction_uuid', { unique: false });
                    store.createIndex('created_at', 'created_at', { unique: false });
                }
                if (!db.objectStoreNames.contains('offline_sync_attempts')) {
                    const store = db.createObjectStore('offline_sync_attempts', { keyPath: 'id' });
                    store.createIndex('offline_transaction_uuid', 'offline_transaction_uuid', { unique: false });
                    store.createIndex('sync_attempt_id', 'sync_attempt_id', { unique: false });
                    store.createIndex('started_at', 'started_at', { unique: false });
                }
                if (!db.objectStoreNames.contains('offline_tombstones')) {
                    const store = db.createObjectStore('offline_tombstones', { keyPath: 'id' });
                    store.createIndex('offline_transaction_uuid', 'offline_transaction_uuid', { unique: false });
                    store.createIndex('created_at', 'created_at', { unique: false });
                }
                if (!db.objectStoreNames.contains('offline_queue_meta')) {
                    db.createObjectStore('offline_queue_meta');
                }
                if (!db.objectStoreNames.contains('offline_recovery_events')) {
                    const store = db.createObjectStore('offline_recovery_events', { keyPath: 'id' });
                    store.createIndex('event_type', 'event_type', { unique: false });
                    store.createIndex('offline_transaction_uuid', 'offline_transaction_uuid', { unique: false });
                    store.createIndex('created_at', 'created_at', { unique: false });
                }
            };

            request.onsuccess = () => {
                this.db = request.result;
                resolve(this.db);
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    private async computeSHA256(data: string): Promise<string> {
        const encoder = new TextEncoder();
        const dataBuffer = encoder.encode(data);
        const hashBuffer = await crypto.subtle.digest('SHA-256', dataBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }

    private canonicalize(value: any, keyName: string | null = null): any {
        return canonicalizeOfflineValue(value, keyName);
    }

    public canonicalSerialize(value: any): string {
        return JSON.stringify(this.canonicalize(value));
    }

    private cloneValue<T>(value: T): T {
        return JSON.parse(JSON.stringify(value)) as T;
    }

    private freezeDeep<T>(value: T): T {
        if (value && typeof value === 'object' && !Object.isFrozen(value)) {
            Object.freeze(value);
            Object.getOwnPropertyNames(value).forEach((prop) => {
                const child = (value as Record<string, any>)[prop];
                if (child && typeof child === 'object') {
                    this.freezeDeep(child);
                }
            });
        }

        return value;
    }

    private async computePayloadHash(payload: any): Promise<string> {
        return this.computeSHA256(canonicalizeOfflineEnvelope(payload, CHECKSUM_VERSION));
    }

    private async computeRowHash(previousHash: string | null, payloadHash: string, sequence: string, batchReference: string): Promise<string> {
        return this.computeSHA256(JSON.stringify({
            previous_hash: previousHash,
            payload_hash: payloadHash,
            offline_sequence: sequence,
            batch_reference: batchReference,
        }));
    }

    private async getLastHash(tx: IDBTransaction): Promise<string | null> {
        return new Promise((resolve, reject) => {
            const store = tx.objectStore('metadata');
            const request = store.get('last_transaction_hash');
            request.onsuccess = () => resolve(request.result || null);
            request.onerror = () => reject(request.error);
        });
    }

    private async updateLastHash(tx: IDBTransaction, hash: string): Promise<void> {
        return new Promise((resolve, reject) => {
            const store = tx.objectStore('metadata');
            const request = store.put(hash, 'last_transaction_hash');
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    private defaultLease(): OfflineQueueLease {
        return {
            lease_id: null,
            queue_owner_instance_id: null,
            lease_acquired_at: null,
            lease_expires_at: null,
            lease_heartbeat_at: null,
            worker_type: null,
            worker_version: null,
            lease_purpose: null,
        };
    }

    private terminalIdFromPayload(payload: any): string | null {
        return payload?.terminal_id || payload?.sales_machine_profile_id || null;
    }

    private terminalBindingEpochFromPayload(payload: any): string {
        if (!payload?.terminal_binding_epoch) {
            throw new Error('Offline capture requires a server-issued terminal binding epoch.');
        }

        return String(payload.terminal_binding_epoch);
    }

    private queueProjection(record: OfflineTransactionEnvelope): Record<string, any> {
        return {
            id: record.id,
            offline_transaction_uuid: record.offline_transaction_uuid || record.id,
            terminal_id: record.terminal_id || null,
            terminal_binding_epoch: record.terminal_binding_epoch || null,
            local_sequence: record.local_sequence || record.offline_sequence,
            status: record.status,
            persistence_state: record.persistence_state || 'durably_captured',
            queue_state: record.queue_state || this.queueStateForStatus(record.status),
            server_state: record.server_state || this.serverStateForStatus(record.status),
            resolution_state: record.resolution_state || 'none',
            retention_state: record.retention_state || 'full_payload',
            queue_state_revision: record.queue_state_revision || 1,
            lease: record.lease || this.defaultLease(),
            retry_count: record.retry_count || 0,
            next_retry_at: record.next_retry_at || null,
            last_error_category: record.last_error_category || null,
            last_error_code: record.last_error_code || null,
            updated_at: record.updated_at,
        };
    }

    private putQueueProjection(tx: IDBTransaction, record: OfflineTransactionEnvelope): Promise<void> {
        return new Promise((resolve, reject) => {
            const store = tx.objectStore('offline_queue_state');
            const request = store.put(this.queueProjection(record));
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    private recordStatusEvent(
        tx: IDBTransaction,
        record: OfflineTransactionEnvelope,
        eventType: string,
        previousState: Record<string, any> | null = null,
        details: Record<string, any> = {}
    ): Promise<void> {
        return new Promise((resolve, reject) => {
            const now = new Date().toISOString();
            const request = tx.objectStore('offline_status_events').add({
                id: uuidv4(),
                offline_transaction_uuid: record.offline_transaction_uuid || record.id,
                local_sequence: record.local_sequence || record.offline_sequence,
                event_type: eventType,
                previous_state: previousState,
                next_state: this.queueProjection(record),
                details,
                created_at: now,
            });
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    private recordRecoveryEvent(
        tx: IDBTransaction,
        eventType: string,
        details: Record<string, any> = {},
        offlineTransactionUuid: string | null = null
    ): Promise<void> {
        return new Promise((resolve, reject) => {
            const now = new Date().toISOString();
            const request = tx.objectStore('offline_recovery_events').add({
                id: uuidv4(),
                event_type: eventType,
                offline_transaction_uuid: offlineTransactionUuid,
                details,
                created_at: now,
            });
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    private recordSyncAttemptEvent(
        tx: IDBTransaction,
        record: OfflineTransactionEnvelope,
        syncAttemptId: string,
        leaseId: string,
        attemptGeneration: number,
        startedAt: string
    ): Promise<void> {
        return new Promise((resolve, reject) => {
            const request = tx.objectStore('offline_sync_attempts').add({
                id: uuidv4(),
                offline_transaction_uuid: record.offline_transaction_uuid || record.id,
                sync_attempt_id: syncAttemptId,
                lease_id: leaseId,
                attempt_generation: attemptGeneration,
                started_at: startedAt,
                completed_at: null,
                outcome: 'started',
            });
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    private queueStateForStatus(status: OfflineSyncStatus): OfflineQueueState {
        switch (status) {
            case 'syncing':
                return 'syncing';
            case 'failed':
                return 'retry_scheduled';
            case 'conflict':
                return 'blocked';
            case 'synced':
            case 'accepted_with_warning':
            case 'cancelled':
                return 'processing_complete';
            case 'pending':
            default:
                return 'pending';
        }
    }

    private serverStateForStatus(status: OfflineSyncStatus): OfflineServerState {
        switch (status) {
            case 'synced':
            case 'accepted_with_warning':
                return 'accepted';
            case 'failed':
                return 'retryable_failed';
            case 'conflict':
                return 'review_required';
            case 'pending':
            case 'syncing':
            case 'cancelled':
            default:
                return 'not_submitted';
        }
    }

    private async getMetadataValue<T>(key: string): Promise<T | null> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata'], 'readonly');
            const request = tx.objectStore('metadata').get(key);
            request.onsuccess = () => resolve((request.result ?? null) as T | null);
            request.onerror = () => reject(request.error);
        });
    }

    private async setMetadataValue<T>(key: string, value: T): Promise<void> {
        const db = await this.initDb();
        await new Promise<void>((resolve, reject) => {
            const tx = db.transaction(['metadata'], 'readwrite');
            const request = tx.objectStore('metadata').put(value, key);
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    private async setQueueMetaValue<T>(key: string, value: T): Promise<void> {
        const db = await this.initDb();
        await new Promise<void>((resolve, reject) => {
            const tx = db.transaction(['offline_queue_meta'], 'readwrite');
            const request = tx.objectStore('offline_queue_meta').put(value, key);
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    private async getQueueMetaValue<T>(key: string): Promise<T | null> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['offline_queue_meta'], 'readonly');
            const request = tx.objectStore('offline_queue_meta').get(key);
            request.onsuccess = () => resolve((request.result ?? null) as T | null);
            request.onerror = () => reject(request.error);
        });
    }

    private async requestPersistentStorageCapability(): Promise<void> {
        if (typeof navigator === 'undefined' || !navigator.storage || typeof navigator.storage.persist !== 'function') {
            await this.setQueueMetaValue('persistent_storage_capability', {
                supported: false,
                granted: false,
                checked_at: new Date().toISOString(),
            });
            return;
        }

        let granted = false;
        try {
            granted = await navigator.storage.persist();
        } catch (error) {
            await this.setQueueMetaValue('persistent_storage_capability', {
                supported: true,
                granted: false,
                error: error?.message || String(error),
                checked_at: new Date().toISOString(),
            });
            return;
        }

        await this.setQueueMetaValue('persistent_storage_capability', {
            supported: true,
            granted,
            checked_at: new Date().toISOString(),
        });
    }

    private async countUnresolvedTransactions(): Promise<number> {
        const records = await this.getAllTransactions();
        return records.filter((record) => {
            const queueState = record.queue_state || this.queueStateForStatus(record.status);
            return queueState !== 'processing_complete' && record.retention_state !== 'purged';
        }).length;
    }

    private async countTombstones(): Promise<number> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['offline_tombstones'], 'readonly');
            const request = tx.objectStore('offline_tombstones').getAll();
            request.onsuccess = () => resolve((request.result || []).length);
            request.onerror = () => reject(request.error);
        });
    }

    private async getHighestLocalSequence(): Promise<string | null> {
        const records = await this.getAllTransactions();
        const sequences = records
            .map((record) => record.local_sequence || record.offline_sequence)
            .filter(Boolean)
            .sort();

        return sequences.length > 0 ? sequences[sequences.length - 1] : null;
    }

    private queueHealthForStorageState(storageState: OfflineStorageState): OfflineQueueHealthState {
        if (!STORAGE_STATES.includes(storageState)) {
            throw new Error(`Unsupported offline storage state: ${storageState}`);
        }

        switch (storageState) {
            case 'queue_capacity_warning':
                return 'warning';
            case 'queue_capacity_block':
            case 'storage_unavailable':
                return 'blocked';
            case 'storage_corrupt':
                return 'support_required';
            case 'storage_available':
            default:
                return 'healthy';
        }
    }

    public async recordQueueHealthHeartbeat(input: {
        terminal_id?: string | null;
        terminal_binding_epoch?: string | null;
        storage_state?: OfflineStorageState;
        terminal_recovery_state?: OfflineTerminalRecoveryState;
        reported_at?: string;
    } = {}): Promise<OfflineQueueHealthSnapshot> {
        const storageState = input.storage_state || 'storage_available';
        const snapshot: OfflineQueueHealthSnapshot = {
            terminal_id: input.terminal_id || null,
            terminal_binding_epoch: input.terminal_binding_epoch || null,
            highest_local_sequence: await this.getHighestLocalSequence(),
            unresolved_count: await this.countUnresolvedTransactions(),
            accepted_tombstone_count: await this.countTombstones(),
            queue_schema_version: ENVELOPE_SCHEMA_VERSION,
            storage_state: storageState,
            queue_health: this.queueHealthForStorageState(storageState),
            terminal_recovery_state: input.terminal_recovery_state || 'none',
            reported_at: input.reported_at || new Date().toISOString(),
        };

        await this.setQueueMetaValue('last_queue_health_heartbeat', snapshot);
        this.emitChange();

        return this.freezeDeep(this.cloneValue(snapshot));
    }

    public async compareQueueHealthAfterReactivation(current: {
        terminal_id?: string | null;
        terminal_binding_epoch?: string | null;
        local_profile_empty?: boolean;
    } = {}): Promise<OfflineTerminalRecoveryState> {
        const prior = await this.getQueueMetaValue<OfflineQueueHealthSnapshot>('last_queue_health_heartbeat');
        if (!prior || !current.local_profile_empty || Number(prior.unresolved_count || 0) === 0) {
            return 'none';
        }

        let recoveryState: OfflineTerminalRecoveryState = 'possible_storage_loss';

        if (!current.terminal_id || !current.terminal_binding_epoch) {
            recoveryState = 'orphan_identity_unverifiable';
        } else if (prior.terminal_id && current.terminal_id !== prior.terminal_id) {
            recoveryState = 'orphan_terminal_mismatch';
        } else if (prior.terminal_binding_epoch && current.terminal_binding_epoch !== prior.terminal_binding_epoch) {
            recoveryState = 'orphan_epoch_mismatch';
        }

        await this.setQueueMetaValue('last_queue_health_heartbeat', {
            ...prior,
            terminal_recovery_state: recoveryState,
            queue_health: recoveryState === 'possible_storage_loss' ? 'support_required' : prior.queue_health,
            reported_at: new Date().toISOString(),
        });

        return recoveryState;
    }

    public async recordServerIssuedBinding(binding: {
        terminal_id: string;
        terminal_binding_epoch: string | number;
        binding_issued_at: string;
        binding_status: string;
    }): Promise<void> {
        if (!binding.terminal_id || binding.terminal_binding_epoch === undefined || binding.terminal_binding_epoch === null) {
            throw new Error('Terminal binding must include server-issued terminal_id and terminal_binding_epoch.');
        }

        const nextEpoch = String(binding.terminal_binding_epoch);
        const prior = await this.getQueueMetaValue<Record<string, any>>('server_terminal_binding');
        if (prior?.terminal_id && prior.terminal_id !== binding.terminal_id && await this.countUnresolvedTransactions() > 0) {
            throw new Error('Unresolved queue belongs to another terminal binding. Support review is required before rebinding.');
        }

        if (prior?.terminal_id === binding.terminal_id && prior?.terminal_binding_epoch !== undefined) {
            const priorNumeric = Number(prior.terminal_binding_epoch);
            const nextNumeric = Number(nextEpoch);
            if (Number.isFinite(priorNumeric) && Number.isFinite(nextNumeric) && nextNumeric < priorNumeric) {
                throw new Error('Client cannot restore an older terminal binding epoch.');
            }
        }

        await this.setQueueMetaValue('server_terminal_binding', {
            terminal_id: binding.terminal_id,
            terminal_binding_epoch: nextEpoch,
            binding_issued_at: binding.binding_issued_at,
            binding_status: binding.binding_status,
            recorded_at: new Date().toISOString(),
        });
    }

    private async readCaptureSnapshot(prefix: string, initialNextValue = 1, padLength = 6): Promise<{
        previousHash: string | null;
        sequence: string;
        allocatedValue: number;
        nextValue: number;
        sequenceKey: string;
    }> {
        const db = await this.initDb();

        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata'], 'readonly');
            const store = tx.objectStore('metadata');
            const sequenceKey = `sequence_next_value:${prefix}`;
            const sequenceRequest = store.get(sequenceKey);
            const hashRequest = store.get('last_transaction_hash');
            let sequenceResult: number | null = null;
            let previousHash: string | null = null;
            let completed = 0;

            const finish = () => {
                completed += 1;
                if (completed < 2) {
                    return;
                }

                const allocatedValue = Number.isInteger(sequenceResult) && Number(sequenceResult) > 0
                    ? Number(sequenceResult)
                    : initialNextValue;

                resolve({
                    previousHash,
                    sequence: `${prefix}${String(allocatedValue).padStart(padLength, '0')}`,
                    allocatedValue,
                    nextValue: allocatedValue + 1,
                    sequenceKey,
                });
            };

            sequenceRequest.onsuccess = () => {
                sequenceResult = sequenceRequest.result ?? null;
                finish();
            };
            hashRequest.onsuccess = () => {
                previousHash = hashRequest.result || null;
                finish();
            };
            sequenceRequest.onerror = () => reject(sequenceRequest.error);
            hashRequest.onerror = () => reject(hashRequest.error);
        });
    }

    private isLeaseExpired(record: OfflineTransactionEnvelope, now = new Date()): boolean {
        const leaseExpiresAt = record.lease?.lease_expires_at;
        if (!leaseExpiresAt) {
            return false;
        }

        const expiresAt = Date.parse(leaseExpiresAt);
        return Number.isFinite(expiresAt) && expiresAt <= now.getTime();
    }

    private isActiveSyncAttempt(record: OfflineTransactionEnvelope, guard: OfflineSyncAttemptGuard): boolean {
        if (this.isLeaseExpired(record)) {
            return false;
        }

        if (!record.lease || record.lease.lease_id !== guard.leaseId) {
            return false;
        }

        if (guard.ownerInstanceId && record.lease.queue_owner_instance_id !== guard.ownerInstanceId) {
            return false;
        }

        return record.last_sync_attempt_id === guard.syncAttemptId
            && Number(record.last_attempt_generation || 0) === Number(guard.attemptGeneration);
    }

    public async getNextOfflineSequence(prefix: string, initialNextValue = 1, padLength = 6): Promise<string> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata'], 'readwrite');
            const store = tx.objectStore('metadata');
            const sequenceKey = `sequence_next_value:${prefix}`;
            const request = store.get(sequenceKey);

            request.onsuccess = () => {
                const nextVal = Number.isInteger(request.result) && request.result > 0
                    ? request.result
                    : initialNextValue;
                const formattedSequence = `${prefix}${String(nextVal).padStart(padLength, '0')}`;

                // Advance the sequence
                const putReq = store.put(nextVal + 1, sequenceKey);
                putReq.onsuccess = () => resolve(formattedSequence);
                putReq.onerror = () => reject(putReq.error);
            };
            request.onerror = () => reject(request.error);
        });
    }

    private readNextOfflineSequence(tx: IDBTransaction, prefix: string, initialNextValue = 1, padLength = 6): Promise<{ sequence: string; nextValue: number; sequenceKey: string }> {
        return new Promise((resolve, reject) => {
            const store = tx.objectStore('metadata');
            const sequenceKey = `sequence_next_value:${prefix}`;
            const request = store.get(sequenceKey);

            request.onsuccess = () => {
                const nextVal = Number.isInteger(request.result) && request.result > 0
                    ? request.result
                    : initialNextValue;
                const formattedSequence = `${prefix}${String(nextVal).padStart(padLength, '0')}`;
                resolve({ sequence: formattedSequence, nextValue: nextVal + 1, sequenceKey });
            };
            request.onerror = () => reject(request.error);
        });
    }

    private advanceOfflineSequence(tx: IDBTransaction, sequenceKey: string, nextValue: number): Promise<void> {
        return new Promise((resolve, reject) => {
            const request = tx.objectStore('metadata').put(nextValue, sequenceKey);
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    public async checkDuplicateLocalReference(ref: string): Promise<boolean> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['transactions'], 'readonly');
            const store = tx.objectStore('transactions');
            const request = store.openCursor();

            request.onsuccess = (event: any) => {
                const cursor = event.target.result;
                if (cursor) {
                    const record = cursor.value;
                    if (record.payload?.local_transaction_reference === ref) {
                        return resolve(true);
                    }
                    cursor.continue();
                } else {
                    resolve(false);
                }
            };
            request.onerror = () => reject(request.error);
        });
    }

    public async appendTransaction(
        payload: any,
        clientTotals: { total: string, tax: string, subtotal: string },
        options: { prefix: string, initialNextValue?: number, batchReference?: string, maxUnresolvedRecords?: number }
    ): Promise<OfflineTransactionEnvelope> {
        // Enforce fixed-point math requirement (decimal strings)
        if (
            typeof clientTotals.total !== 'string' ||
            typeof clientTotals.tax !== 'string' ||
            typeof clientTotals.subtotal !== 'string'
        ) {
            throw new Error('Totals must be provided as decimal strings to avoid floating point precision issues.');
        }

        const db = await this.initDb();
        await this.requestPersistentStorageCapability();

        const maxUnresolved = options.maxUnresolvedRecords ?? 500;
        const unresolvedCount = await this.countUnresolvedTransactions();
        if (unresolvedCount >= maxUnresolved) {
            throw new Error(`Offline queue limit reached (${maxUnresolved} unresolved records). Reconnect and synchronize before capturing more offline transactions.`);
        }

        const batchReference = options.batchReference || uuidv4();
        const now = new Date().toISOString();
        const immutablePayload = this.cloneValue(payload);
        const captureSnapshot = await this.readCaptureSnapshot(options.prefix, options.initialNextValue || 1, 6);
        const isDupRef = await this.checkDuplicateLocalReference(captureSnapshot.sequence);
        if (isDupRef) {
            throw new Error(`Duplicate local transaction reference detected: ${captureSnapshot.sequence}`);
        }

        immutablePayload.local_transaction_reference = captureSnapshot.sequence;
        immutablePayload.local_receipt_number = captureSnapshot.sequence;

        const payloadHash = await this.computePayloadHash(immutablePayload);
        immutablePayload.payload_hash = payloadHash;
        const rowHash = await this.computeRowHash(captureSnapshot.previousHash, payloadHash, captureSnapshot.sequence, batchReference);
        const offlineTransactionUuid = uuidv4();
        const envelope: OfflineTransactionEnvelope = {
            id: offlineTransactionUuid,
            offline_transaction_uuid: offlineTransactionUuid,
            terminal_id: this.terminalIdFromPayload(immutablePayload),
            terminal_binding_epoch: this.terminalBindingEpochFromPayload(immutablePayload),
            local_sequence: captureSnapshot.sequence,
            schema_version: ENVELOPE_SCHEMA_VERSION,
            fingerprint_version: FINGERPRINT_VERSION,
            checksum_version: CHECKSUM_VERSION,
            batch_reference: batchReference,
            offline_sequence: captureSnapshot.sequence,
            payload: this.cloneValue(immutablePayload),
            payload_hash: payloadHash,
            payload_checksum: payloadHash,
            previous_hash: captureSnapshot.previousHash,
            row_hash: rowHash,
            status: 'pending',
            persistence_state: 'persisting',
            queue_state: 'pending',
            server_state: 'not_submitted',
            resolution_state: 'none',
            retention_state: 'full_payload',
            queue_state_revision: 1,
            lease: this.defaultLease(),
            created_at: now,
            updated_at: now,
            last_sync_attempt_at: null,
            last_synced_at: null,
            next_retry_at: null,
            retry_count: 0,
            last_error_category: null,
            last_error_code: null,
            last_sync_attempt_id: null,
            last_attempt_generation: 0,
            client_totals: this.cloneValue(clientTotals),
        };

        const captured = await new Promise<OfflineTransactionEnvelope>((resolve, reject) => {
            const tx = db.transaction([
                'transactions',
                'metadata',
                'offline_queue_state',
                'offline_status_events',
            ], 'readwrite');
            const metadataStore = tx.objectStore('metadata');
            const transactionStore = tx.objectStore('transactions');
            const queueStore = tx.objectStore('offline_queue_state');
            const eventStore = tx.objectStore('offline_status_events');

            tx.oncomplete = () => {
                this.emitChange();
                resolve(this.freezeDeep(this.cloneValue(envelope)));
            };
            tx.onerror = () => reject(tx.error);
            tx.onabort = () => reject(tx.error || new Error('Offline capture transaction was aborted.'));

            const sequenceCheck = metadataStore.get(captureSnapshot.sequenceKey);
            sequenceCheck.onsuccess = () => {
                const currentValue = Number.isInteger(sequenceCheck.result) && Number(sequenceCheck.result) > 0
                    ? Number(sequenceCheck.result)
                    : (options.initialNextValue || 1);
                if (currentValue !== captureSnapshot.allocatedValue) {
                    tx.abort();
                    return;
                }

                transactionStore.add(envelope);
                metadataStore.put(rowHash, 'last_transaction_hash');
                metadataStore.put(captureSnapshot.nextValue, captureSnapshot.sequenceKey);
                queueStore.put(this.queueProjection(envelope));
                eventStore.add({
                    id: uuidv4(),
                    offline_transaction_uuid: envelope.offline_transaction_uuid || envelope.id,
                    local_sequence: envelope.local_sequence || envelope.offline_sequence,
                    event_type: 'offline_capture_persisting',
                    previous_state: null,
                    next_state: this.queueProjection(envelope),
                    details: {
                        fingerprint_version: FINGERPRINT_VERSION,
                        checksum_version: CHECKSUM_VERSION,
                    },
                    created_at: now,
                });
            };
            sequenceCheck.onerror = () => reject(sequenceCheck.error);
        });

        return this.verifyLocalCapture(captured.id);
    }

    private async verifyLocalCapture(id: string): Promise<OfflineTransactionEnvelope> {
        const db = await this.initDb();
        const record = await new Promise<OfflineTransactionEnvelope>((resolve, reject) => {
            const tx = db.transaction(['transactions'], 'readonly');
            const request = tx.objectStore('transactions').get(id);
            request.onsuccess = () => request.result
                ? resolve(request.result as OfflineTransactionEnvelope)
                : reject(new Error(`Offline capture ${id} was not readable after local persistence.`));
            request.onerror = () => reject(request.error);
        });

        const payloadHash = await this.computePayloadHash(record.payload);
        const rowHash = await this.computeRowHash(record.previous_hash, payloadHash, record.offline_sequence, record.batch_reference);
        const verified = payloadHash === record.payload_hash && rowHash === record.row_hash;

        return new Promise((resolve, reject) => {
            const tx = db.transaction(['transactions', 'offline_queue_state', 'offline_status_events'], 'readwrite');
            const store = tx.objectStore('transactions');
            const getReq = store.get(id);

            getReq.onsuccess = () => {
                const latest: OfflineTransactionEnvelope = getReq.result;
                if (!latest) {
                    return reject(new Error(`Offline capture ${id} disappeared before verification completed.`));
                }

                const previousState = this.queueProjection(latest);
                latest.persistence_state = verified ? 'durably_captured' : 'capture_uncertain';
                latest.updated_at = new Date().toISOString();
                latest.queue_state_revision = (latest.queue_state_revision || 1) + 1;
                if (!verified) {
                    latest.queue_state = 'blocked';
                    latest.status = 'conflict';
                    latest.server_state = 'review_required';
                    latest.error_message = 'Local capture read-back verification failed. Support review is required before sync.';
                    latest.last_error_category = 'local_storage_integrity';
                    latest.last_error_code = 'CAPTURE_READBACK_MISMATCH';
                }

                const putReq = store.put(latest);
                putReq.onsuccess = () => {
                    Promise.all([
                        this.putQueueProjection(tx, latest),
                        this.recordStatusEvent(tx, latest, verified ? 'offline_capture_verified' : 'offline_capture_uncertain', previousState, {
                            payload_hash_matches: payloadHash === record.payload_hash,
                            row_hash_matches: rowHash === record.row_hash,
                        }),
                    ]).then(() => {
                        this.emitChange();
                        resolve(this.freezeDeep(this.cloneValue(latest)));
                    }).catch(reject);
                };
                putReq.onerror = () => reject(putReq.error);
            };
            getReq.onerror = () => reject(getReq.error);
        });
    }

    public async getQueuedTransactions(): Promise<OfflineTransactionEnvelope[]> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['transactions'], 'readonly');
            const store = tx.objectStore('transactions');
            const request = store.getAll();

            request.onsuccess = () => {
                const results = request.result || [];
                const pending = results.filter((r: OfflineTransactionEnvelope) => {
                    const queueState = r.queue_state || this.queueStateForStatus(r.status);
                    const isRetryableStatus = ['pending', 'failed'].includes(r.status);
                    const isExpiredLease = r.status === 'syncing' && ['leased', 'syncing'].includes(queueState) && this.isLeaseExpired(r);

                    return (isRetryableStatus || isExpiredLease)
                        && (r.persistence_state || 'durably_captured') === 'durably_captured'
                        && !['blocked', 'processing_complete'].includes(queueState);
                });
                resolve(pending.map((record: OfflineTransactionEnvelope) => this.freezeDeep(this.cloneValue(record))));
            };
            request.onerror = () => reject(request.error);
        });
    }

    public async acquireLease(
        id: string,
        ownerInstanceId: string,
        workerType = 'offline-sales-sync',
        workerVersion = 'story-41.2',
        leaseMs = 45_000,
        leasePurpose: OfflineQueueLeasePurpose = 'sync'
    ): Promise<OfflineTransactionEnvelope> {
        const db = await this.initDb();
        const now = new Date();
        const leaseId = uuidv4();
        const syncAttemptId = uuidv4();

        return new Promise((resolve, reject) => {
            const tx = db.transaction([
                'transactions',
                'offline_queue_state',
                'offline_status_events',
                'offline_sync_attempts',
            ], 'readwrite');
            const store = tx.objectStore('transactions');
            const getReq = store.get(id);

            getReq.onsuccess = () => {
                const record: OfflineTransactionEnvelope = getReq.result;
                if (!record) {
                    return reject(new Error(`Transaction ${id} not found.`));
                }

                const queueState = record.queue_state || this.queueStateForStatus(record.status);
                const currentLease = record.lease || this.defaultLease();
                const leaseStillActive = currentLease.lease_expires_at
                    ? Date.parse(currentLease.lease_expires_at) > now.getTime()
                    : false;

                if (leaseStillActive) {
                    return reject(new Error(`Transaction ${id} is already leased by another queue worker.`));
                }

                if (!['pending', 'retry_scheduled', 'leased', 'syncing'].includes(queueState)) {
                    return reject(new Error(`Transaction ${id} cannot be leased from queue state ${queueState}.`));
                }

                const previousState = this.queueProjection(record);
                const nextRevision = (record.queue_state_revision || 1) + 1;
                const startedAt = now.toISOString();
                const expiresAt = new Date(now.getTime() + leaseMs).toISOString();

                record.queue_state = 'leased';
                record.server_state = record.server_state || 'not_submitted';
                record.queue_state_revision = nextRevision;
                record.updated_at = startedAt;
                if (leasePurpose === 'sync') {
                    record.status = 'syncing';
                    record.last_sync_attempt_at = startedAt;
                    record.last_sync_attempt_id = syncAttemptId;
                    record.last_attempt_generation = (record.last_attempt_generation || 0) + 1;
                }
                record.lease = {
                    lease_id: leaseId,
                    queue_owner_instance_id: ownerInstanceId,
                    lease_acquired_at: startedAt,
                    lease_expires_at: expiresAt,
                    lease_heartbeat_at: startedAt,
                    worker_type: workerType,
                    worker_version: workerVersion,
                    lease_purpose: leasePurpose,
                };
                const putReq = store.put(record);
                putReq.onsuccess = () => {
                    const eventWrites = [
                        this.putQueueProjection(tx, record),
                        this.recordStatusEvent(tx, record, leasePurpose === 'sync' ? 'offline_sync_lease_acquired' : 'offline_maintenance_lease_acquired', previousState, {
                            sync_attempt_id: syncAttemptId,
                            lease_id: leaseId,
                            lease_purpose: leasePurpose,
                            lease_expires_at: expiresAt,
                        }),
                    ];
                    if (leasePurpose === 'sync') {
                        eventWrites.push(this.recordSyncAttemptEvent(tx, record, syncAttemptId, leaseId, record.last_attempt_generation || 1, startedAt));
                    }

                    Promise.all(eventWrites).then(() => {
                        this.emitChange();
                        resolve(this.freezeDeep(this.cloneValue(record)));
                    }).catch(reject);
                };
                putReq.onerror = () => reject(putReq.error);
            };
            getReq.onerror = () => reject(getReq.error);
        });
    }

    public async acquireMaintenanceLease(
        id: string,
        ownerInstanceId: string,
        leasePurpose: Exclude<OfflineQueueLeasePurpose, 'sync'>,
        workerVersion = 'story-41.7',
        leaseMs = 45_000
    ): Promise<OfflineTransactionEnvelope> {
        return this.acquireLease(id, ownerInstanceId, `offline-queue-${leasePurpose}`, workerVersion, leaseMs, leasePurpose);
    }

    public async recordCaptureUiAcknowledged(
        offlineTransactionUuid: string,
        details: { session_id?: string | null; cashier_id?: string | null; acknowledged_at?: string | null } = {}
    ): Promise<void> {
        const db = await this.initDb();
        await new Promise<void>((resolve, reject) => {
            const tx = db.transaction(['offline_recovery_events'], 'readwrite');
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
            tx.onabort = () => reject(tx.error || new Error('Unable to record offline capture UI acknowledgment.'));

            this.recordRecoveryEvent(tx, 'offline_capture_ui_acknowledged', {
                session_id: details.session_id || null,
                cashier_id: details.cashier_id || null,
                acknowledged_at: details.acknowledged_at || new Date().toISOString(),
            }, offlineTransactionUuid).catch(reject);
        });
    }

    public async updateTransactionStatus(
        id: string,
        newStatus: OfflineSyncStatus,
        errorMessage?: string,
        guard?: OfflineSyncAttemptGuard
    ): Promise<OfflineTransactionEnvelope> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['transactions', 'offline_queue_state', 'offline_status_events'], 'readwrite');
            const store = tx.objectStore('transactions');
            const getReq = store.get(id);

            getReq.onsuccess = () => {
                const record: OfflineTransactionEnvelope = getReq.result;
                if (!record) {
                    return reject(new Error(`Transaction ${id} not found.`));
                }

                if (guard && !this.isActiveSyncAttempt(record, guard)) {
                    this.recordStatusEvent(tx, record, 'offline_stale_sync_response_ignored', this.queueProjection(record), {
                        attempted_status: newStatus,
                        lease_id: guard.leaseId,
                        sync_attempt_id: guard.syncAttemptId,
                        attempt_generation: guard.attemptGeneration,
                    }).then(() => {
                        resolve(this.freezeDeep(this.cloneValue(record)));
                    }).catch(reject);
                    return;
                }

                // Enforce status lifecycle
                if (!this.isValidStatusTransition(record.status, newStatus)) {
                    return reject(new Error(`Invalid status transition from ${record.status} to ${newStatus}`));
                }

                const previousState = this.queueProjection(record);

                // Append-only rule: payload, sequence, and hashes cannot be modified.
                record.status = newStatus;
                record.updated_at = new Date().toISOString();
                record.queue_state = this.queueStateForStatus(newStatus);
                record.server_state = this.serverStateForStatus(newStatus);
                record.queue_state_revision = (record.queue_state_revision || 1) + 1;
                if (newStatus === 'syncing') {
                    record.last_sync_attempt_at = record.updated_at;
                }
                if (['synced', 'conflict', 'accepted_with_warning'].includes(newStatus)) {
                    record.last_synced_at = record.updated_at;
                }
                if (newStatus === 'synced') {
                    record.resolution_state = 'resolved_posted';
                    record.retention_state = 'retained_full';
                    record.next_retry_at = null;
                    record.retry_count = record.retry_count || 0;
                    record.lease = this.defaultLease();
                }
                if (newStatus === 'accepted_with_warning') {
                    record.resolution_state = 'pending_support';
                    record.retention_state = 'retained_full';
                    record.lease = this.defaultLease();
                }
                if (newStatus === 'conflict') {
                    record.resolution_state = 'pending_support';
                    record.last_error_category = record.last_error_category || 'server_review';
                    record.last_error_code = record.last_error_code || 'REVIEW_REQUIRED';
                    record.lease = this.defaultLease();
                }
                if (newStatus === 'failed') {
                    const retryCount = (record.retry_count || 0) + 1;
                    const retryDelaySeconds = Math.min(300, Math.pow(2, retryCount) * 5);
                    record.retry_count = retryCount;
                    record.next_retry_at = new Date(Date.parse(record.updated_at) + retryDelaySeconds * 1000).toISOString();
                    record.last_error_category = record.last_error_category || 'transport';
                    record.last_error_code = record.last_error_code || 'RETRYABLE_SYNC_FAILURE';
                    record.lease = this.defaultLease();
                }
                if (newStatus === 'cancelled') {
                    record.resolution_state = 'resolved_rejected';
                    record.retention_state = 'retained_full';
                    record.lease = this.defaultLease();
                }
                if (errorMessage !== undefined) {
                    record.error_message = errorMessage;
                } else if (newStatus !== 'failed') {
                    delete record.error_message;
                    record.last_error_category = null;
                    record.last_error_code = null;
                }

                const putReq = store.put(record);
                putReq.onsuccess = () => {
                    Promise.all([
                        this.putQueueProjection(tx, record),
                        this.recordStatusEvent(tx, record, 'offline_status_transition', previousState, {
                            status: newStatus,
                            error_message: errorMessage || null,
                        }),
                    ]).then(() => {
                        this.emitChange();
                        resolve(this.freezeDeep(this.cloneValue(record)));
                    }).catch(reject);
                };
                putReq.onerror = () => reject(putReq.error);
            };
            getReq.onerror = () => reject(getReq.error);
        });
    }

    private isValidStatusTransition(current: OfflineSyncStatus, next: OfflineSyncStatus): boolean {
        if (current === next) return true;

        const allowedTransitions: Record<OfflineSyncStatus, OfflineSyncStatus[]> = {
            'pending': ['syncing', 'cancelled'],
            'syncing': ['synced', 'failed', 'conflict', 'accepted_with_warning'],
            'failed': ['syncing', 'cancelled', 'conflict'],
            'synced': [],
            'conflict': [],
            'accepted_with_warning': [],
            'cancelled': []
        };

        return allowedTransitions[current]?.includes(next) ?? false;
    }

    public async getAllTransactions(): Promise<OfflineTransactionEnvelope[]> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['transactions'], 'readonly');
            const store = tx.objectStore('transactions');
            const request = store.getAll();

            request.onsuccess = () => resolve((request.result || []).map((record: OfflineTransactionEnvelope) => this.freezeDeep(this.cloneValue(record))));
            request.onerror = () => reject(request.error);
        });
    }

    private filterDiagnosticsRecords(
        records: OfflineTransactionEnvelope[],
        options: OfflineDiagnosticsExportOptions = {}
    ): OfflineTransactionEnvelope[] {
        return records.filter((record) => {
            if (options.status && record.status !== options.status) {
                return false;
            }
            if (options.offlineTransactionUuid && (record.offline_transaction_uuid || record.id) !== options.offlineTransactionUuid) {
                return false;
            }
            if (options.epoch && String(record.terminal_binding_epoch || '') !== String(options.epoch)) {
                return false;
            }
            const sequence = record.local_sequence || record.offline_sequence || '';
            if (options.localSequenceFrom && sequence < options.localSequenceFrom) {
                return false;
            }
            if (options.localSequenceTo && sequence > options.localSequenceTo) {
                return false;
            }
            const createdAt = Date.parse(record.created_at);
            if (options.from && Number.isFinite(createdAt) && createdAt < Date.parse(options.from)) {
                return false;
            }
            if (options.to && Number.isFinite(createdAt) && createdAt > Date.parse(options.to)) {
                return false;
            }
            if (options.cashExposure && String(record.payload?.cash_status || record.payload?.cash_exposure || '') !== options.cashExposure) {
                return false;
            }

            return true;
        });
    }

    private isBoundedDiagnosticsRequest(options: OfflineDiagnosticsExportOptions = {}): boolean {
        if ((options.from && !Number.isFinite(Date.parse(options.from))) || (options.to && !Number.isFinite(Date.parse(options.to)))) {
            throw new Error('Support diagnostics export received an invalid date bound.');
        }

        return Boolean(
            options.status
            || options.offlineTransactionUuid
            || options.localSequenceFrom
            || options.localSequenceTo
            || options.cashExposure
            || options.epoch
            || (options.from && options.to)
        );
    }

    public async getDiagnosticsBundle(options: OfflineDiagnosticsExportOptions = {}): Promise<OfflineQueueDiagnosticsBundle> {
        if (options.generatedBy && !this.isBoundedDiagnosticsRequest(options)) {
            throw new Error('Support diagnostics export requires a bounded filter.');
        }

        const db = await this.initDb();
        const records = await this.getAllTransactions();
        const filteredRecords = this.filterDiagnosticsRecords(records, options);
        const summary = await this.getStatusSummary();
        const hashChainValid = await this.verifyHashChain();
        const persistentStorageCapability = await this.getQueueMetaValue<Record<string, any>>('persistent_storage_capability');
        const tombstoneCount = await this.countTombstones();
        const lastQueueHealthHeartbeat = await this.getQueueMetaValue<OfflineQueueHealthSnapshot>('last_queue_health_heartbeat');
        const bundle: OfflineQueueDiagnosticsBundle = {
            export_id: options.generatedBy ? uuidv4() : undefined,
            generated_at: new Date().toISOString(),
            generated_by: options.generatedBy || null,
            filter_summary: {
                status: options.status || null,
                offline_transaction_uuid: options.offlineTransactionUuid || null,
                local_sequence_from: options.localSequenceFrom || null,
                local_sequence_to: options.localSequenceTo || null,
                cash_exposure: options.cashExposure || null,
                epoch: options.epoch || null,
                from: options.from || null,
                to: options.to || null,
            },
            label: 'provisional local evidence',
            storage: {
                indexed_db_available: true,
                database_name: DB_NAME,
                database_version: DB_VERSION,
                object_stores: Array.from(db.objectStoreNames as any),
                persistent_storage_capability: persistentStorageCapability,
                storage_state: lastQueueHealthHeartbeat?.storage_state || 'storage_available',
                queue_health: lastQueueHealthHeartbeat?.queue_health || 'healthy',
                terminal_recovery_state: lastQueueHealthHeartbeat?.terminal_recovery_state || 'none',
                last_queue_health_heartbeat: lastQueueHealthHeartbeat,
            },
            summary,
            hash_chain_valid: hashChainValid,
            active_record_count: records.filter((record) => UNRESOLVED_STATUSES.includes(record.status)).length,
            historical_record_count: records.length,
            tombstone_count: tombstoneCount,
            records: filteredRecords.map((record) => this.toDiagnosticRecord(record)),
        };

        if (options.generatedBy) {
            bundle.export_checksum = await this.computeSHA256(this.canonicalSerialize({
                generated_at: bundle.generated_at,
                generated_by: bundle.generated_by,
                filter_summary: bundle.filter_summary,
                storage: bundle.storage,
                records: bundle.records,
            }));
        }

        return bundle;
    }

    public async pruneResolvedTransactions(retentionDays = 7, now = new Date()): Promise<{ pruned: number; retained: number }> {
        const db = await this.initDb();
        const records = await this.getAllTransactions();
        const cutoff = now.getTime() - (Math.max(0, retentionDays) * 24 * 60 * 60 * 1000);
        const prunable = records.filter((record) => {
            if (!PRUNABLE_STATUSES.includes(record.status)) {
                return false;
            }

            const timestamp = record.last_synced_at || record.updated_at || record.created_at;
            const time = Date.parse(timestamp);

            return Number.isFinite(time) && time < cutoff;
        });

        if (prunable.length === 0) {
            return { pruned: 0, retained: records.length };
        }

        const tombstones = await Promise.all(prunable.map(async (record) => {
            const retainedServerReference = record.payload?.server_sale_uuid
                || record.payload?.sale_uuid
                || record.payload?.server_sale_number
                || record.payload?.sale_number
                || record.payload?.official_invoice_number
                || null;

            if (!retainedServerReference) {
                throw new Error(`Cannot compact ${record.offline_transaction_uuid || record.id} without required server identity.`);
            }

            const tombstone = {
                id: record.id,
                offline_transaction_uuid: record.offline_transaction_uuid || record.id,
                terminal_id: record.terminal_id || null,
                terminal_binding_epoch: record.terminal_binding_epoch || null,
                local_sequence: record.local_sequence || record.offline_sequence,
                offline_sequence: record.offline_sequence,
                status: record.status,
                server_state: record.server_state || this.serverStateForStatus(record.status),
                resolution_state: record.resolution_state || 'none',
                payload_hash: record.payload_hash,
                row_hash: record.row_hash,
                retained_from: record.created_at,
                resolved_at: record.last_synced_at || record.updated_at,
                server_sale_uuid: record.payload?.server_sale_uuid || record.payload?.sale_uuid || null,
                server_sale_number: record.payload?.server_sale_number || record.payload?.sale_number || null,
                official_invoice_number: record.payload?.official_invoice_number || null,
                retained_server_reference: retainedServerReference,
                tombstoned_at: new Date().toISOString(),
                schema_version: record.schema_version || ENVELOPE_SCHEMA_VERSION,
                tombstone_schema_version: 1,
                tombstone_checksum_algorithm: TOMBSTONE_CHECKSUM_VERSION,
            } as Record<string, any>;

            return {
                ...tombstone,
                tombstone_checksum: await this.computeSHA256(canonicalizeOfflineTombstone(tombstone, TOMBSTONE_CHECKSUM_VERSION)),
            };
        }));

        await new Promise<void>((resolve, reject) => {
            const tx = db.transaction(['transactions', 'offline_tombstones', 'offline_recovery_events'], 'readwrite');
            const store = tx.objectStore('transactions');
            const tombstoneStore = tx.objectStore('offline_tombstones');
            const recoveryStore = tx.objectStore('offline_recovery_events');
            let remaining = tombstones.length;

            tombstones.forEach((tombstone) => {
                const tombstoneRequest = tombstoneStore.put(tombstone);
                tombstoneRequest.onsuccess = () => {
                    const verifyRequest = tombstoneStore.get(tombstone.id);
                    verifyRequest.onsuccess = () => {
                        const verified = verifyRequest.result;
                        const abortWithVerificationError = (message: string) => {
                            tx.abort();
                            console.warn(message);
                            reject(new Error(`Tombstone verification failed for ${tombstone.offline_transaction_uuid}.`));
                        };
                        if (!verified || !verified.retained_server_reference) {
                            abortWithVerificationError('Tombstone missing required server identity.');
                            return;
                        }

                        this.computeSHA256(canonicalizeOfflineTombstone(verified, TOMBSTONE_CHECKSUM_VERSION)).then((verifiedChecksum) => {
                            if (verifiedChecksum !== verified.tombstone_checksum || verifiedChecksum !== tombstone.tombstone_checksum) {
                                abortWithVerificationError('Tombstone checksum mismatch.');
                                return;
                            }

                            const eventRequest = recoveryStore.add({
                                id: uuidv4(),
                                event_type: 'offline_payload_compacting',
                                offline_transaction_uuid: tombstone.offline_transaction_uuid,
                                details: {
                                    tombstone_checksum: tombstone.tombstone_checksum,
                                    tombstone_schema_version: tombstone.tombstone_schema_version,
                                },
                                created_at: new Date().toISOString(),
                            });
                            eventRequest.onsuccess = () => {
                                const request = store.delete(tombstone.id);
                                request.onsuccess = () => {
                                    remaining -= 1;
                                    if (remaining === 0) {
                                        resolve();
                                    }
                                };
                                request.onerror = () => reject(request.error);
                            };
                            eventRequest.onerror = () => reject(eventRequest.error);
                        }).catch((error) => {
                            tx.abort();
                            reject(error);
                        });
                    };
                    verifyRequest.onerror = () => reject(verifyRequest.error);
                };
                tombstoneRequest.onerror = () => reject(tombstoneRequest.error);
            });
        });

        this.emitChange();

        return { pruned: prunable.length, retained: records.length - prunable.length };
    }

    public async recordSyncAttempt(at: string): Promise<void> {
        await this.setMetadataValue('last_sync_attempt_at', at);
        this.emitChange();
    }

    public async recordSyncSuccess(at: string): Promise<void> {
        await this.setMetadataValue('last_successful_sync_at', at);
        this.emitChange();
    }

    public async getStatusSummary(): Promise<OfflineQueueSummary> {
        const records = await this.getAllTransactions();
        const lastSyncAttemptAt = await this.getMetadataValue<string>('last_sync_attempt_at');
        const lastSuccessfulSyncAt = await this.getMetadataValue<string>('last_successful_sync_at');

        const summary = records.reduce((acc, record) => {
            if (acc[record.status] !== undefined) {
                acc[record.status] += 1;
            }
            acc.total += 1;
            return acc;
        }, {
            pending: 0,
            syncing: 0,
            synced: 0,
            failed: 0,
            conflict: 0,
            accepted_with_warning: 0,
            cancelled: 0,
            total: 0,
        });

        return {
            ...summary,
            lastSyncAttemptAt,
            lastSuccessfulSyncAt,
        };
    }

    public async verifyHashChain(): Promise<boolean> {
        const records = await this.getAllTransactions();
        const ordered = [...records].sort((left, right) => left.created_at.localeCompare(right.created_at));
        let expectedPreviousHash: string | null = null;

        for (const record of ordered) {
            const payloadHash = await this.computePayloadHash(record.payload);
            const rowHash = await this.computeRowHash(record.previous_hash, payloadHash, record.offline_sequence, record.batch_reference);

            if (record.payload_hash !== payloadHash || record.row_hash !== rowHash || record.previous_hash !== expectedPreviousHash) {
                return false;
            }

            expectedPreviousHash = record.row_hash;
        }

        return true;
    }

    private toDiagnosticRecord(record: OfflineTransactionEnvelope): OfflineQueueDiagnosticRecord {
        return {
            id: record.id,
            offline_transaction_uuid: record.offline_transaction_uuid || record.id,
            terminal_binding_epoch: record.terminal_binding_epoch || null,
            local_sequence: record.local_sequence || record.offline_sequence,
            batch_reference: record.batch_reference,
            offline_sequence: record.offline_sequence,
            status: record.status,
            persistence_state: record.persistence_state || 'durably_captured',
            queue_state: record.queue_state || this.queueStateForStatus(record.status),
            server_state: record.server_state || this.serverStateForStatus(record.status),
            resolution_state: record.resolution_state || 'none',
            retention_state: record.retention_state || 'full_payload',
            queue_state_revision: record.queue_state_revision || 1,
            lease_id: record.lease?.lease_id || null,
            queue_owner_instance_id: record.lease?.queue_owner_instance_id || null,
            lease_expires_at: record.lease?.lease_expires_at || null,
            retry_count: record.retry_count || 0,
            next_retry_at: record.next_retry_at || null,
            last_error_category: record.last_error_category || null,
            last_error_code: record.last_error_code || null,
            created_at: record.created_at,
            updated_at: record.updated_at,
            last_sync_attempt_at: record.last_sync_attempt_at,
            last_synced_at: record.last_synced_at,
            local_transaction_reference: record.payload?.local_transaction_reference || null,
            terminal_id: record.payload?.terminal_id || null,
            branch_id: record.payload?.branch_id || null,
            cashier_shift_id: record.payload?.cashier_shift_id || null,
            gross_amount_centavos: record.payload?.gross_amount_centavos ?? null,
            client_total: record.client_totals?.total ?? null,
            error_message: record.error_message || null,
            payload_hash: record.payload_hash,
            previous_hash: record.previous_hash,
            row_hash: record.row_hash,
        };
    }
}

export const offlineSalesQueue = new OfflineSalesQueueService();
