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

export interface OfflineQueueLease {
    lease_id: string | null;
    queue_owner_instance_id: string | null;
    lease_acquired_at: string | null;
    lease_expires_at: string | null;
    lease_heartbeat_at: string | null;
    worker_type: string | null;
    worker_version: string | null;
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
    generated_at: string;
    storage: {
        indexed_db_available: boolean;
        database_name: string;
        database_version: number;
        object_stores: string[];
        persistent_storage_capability?: Record<string, any> | null;
    };
    summary: OfflineQueueSummary;
    hash_chain_valid: boolean;
    active_record_count: number;
    historical_record_count: number;
    tombstone_count?: number;
    records: OfflineQueueDiagnosticRecord[];
}

const DB_NAME = 'ipos_pos_offline_queue';
const DB_VERSION = 2;
const ENVELOPE_SCHEMA_VERSION = 2;
const FINGERPRINT_VERSION = 'ipos-offline-envelope-v1';
const CHECKSUM_VERSION = 'sha-256-canonical-json-v1';
const UNRESOLVED_STATUSES: OfflineSyncStatus[] = ['pending', 'syncing', 'failed', 'conflict', 'accepted_with_warning'];
const PRUNABLE_STATUSES: OfflineSyncStatus[] = ['synced', 'cancelled'];
const listeners = new Set<() => void>();

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
        if (Array.isArray(value)) {
            return value.map((item) => this.canonicalize(item));
        }

        if (value && typeof value === 'object') {
            return Object.keys(value)
                .sort()
                .reduce((acc, key) => {
                    acc[key] = this.canonicalize(value[key], key);
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
        const payloadForHash = this.cloneValue(payload);
        delete (payloadForHash as Record<string, any>).payload_hash;

        return this.computeSHA256(this.canonicalSerialize(payloadForHash));
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
        };
    }

    private terminalIdFromPayload(payload: any): string | null {
        return payload?.terminal_id || payload?.sales_machine_profile_id || null;
    }

    private terminalBindingEpochFromPayload(payload: any): string {
        return String(
            payload?.terminal_binding_epoch
            || payload?.activation_epoch
            || payload?.activated_at
            || payload?.device_id
            || 'epoch-unknown'
        );
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
        leaseMs = 45_000
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

                record.status = 'syncing';
                record.queue_state = 'leased';
                record.server_state = record.server_state || 'not_submitted';
                record.queue_state_revision = nextRevision;
                record.updated_at = startedAt;
                record.last_sync_attempt_at = startedAt;
                record.last_sync_attempt_id = syncAttemptId;
                record.last_attempt_generation = (record.last_attempt_generation || 0) + 1;
                record.lease = {
                    lease_id: leaseId,
                    queue_owner_instance_id: ownerInstanceId,
                    lease_acquired_at: startedAt,
                    lease_expires_at: expiresAt,
                    lease_heartbeat_at: startedAt,
                    worker_type: workerType,
                    worker_version: workerVersion,
                };
                const putReq = store.put(record);
                putReq.onsuccess = () => {
                    Promise.all([
                        this.putQueueProjection(tx, record),
                        this.recordSyncAttemptEvent(tx, record, syncAttemptId, leaseId, record.last_attempt_generation || 1, startedAt),
                        this.recordStatusEvent(tx, record, 'offline_sync_lease_acquired', previousState, {
                            sync_attempt_id: syncAttemptId,
                            lease_id: leaseId,
                            lease_expires_at: expiresAt,
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

    public async getDiagnosticsBundle(): Promise<OfflineQueueDiagnosticsBundle> {
        const db = await this.initDb();
        const records = await this.getAllTransactions();
        const summary = await this.getStatusSummary();
        const hashChainValid = await this.verifyHashChain();
        const persistentStorageCapability = await this.getQueueMetaValue<Record<string, any>>('persistent_storage_capability');
        const tombstoneCount = await this.countTombstones();

        return {
            generated_at: new Date().toISOString(),
            storage: {
                indexed_db_available: true,
                database_name: DB_NAME,
                database_version: DB_VERSION,
                object_stores: Array.from(db.objectStoreNames as any),
                persistent_storage_capability: persistentStorageCapability,
            },
            summary,
            hash_chain_valid: hashChainValid,
            active_record_count: records.filter((record) => UNRESOLVED_STATUSES.includes(record.status)).length,
            historical_record_count: records.length,
            tombstone_count: tombstoneCount,
            records: records.map((record) => this.toDiagnosticRecord(record)),
        };
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

        await new Promise<void>((resolve, reject) => {
            const tx = db.transaction(['transactions', 'offline_tombstones'], 'readwrite');
            const store = tx.objectStore('transactions');
            const tombstoneStore = tx.objectStore('offline_tombstones');
            let remaining = prunable.length;

            prunable.forEach((record) => {
                const tombstoneRequest = tombstoneStore.put({
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
                    tombstoned_at: new Date().toISOString(),
                    schema_version: record.schema_version || ENVELOPE_SCHEMA_VERSION,
                });

                tombstoneRequest.onsuccess = () => {
                    const request = store.delete(record.id);
                    request.onsuccess = () => {
                        remaining -= 1;
                        if (remaining === 0) {
                            resolve();
                        }
                    };
                    request.onerror = () => reject(request.error);
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
