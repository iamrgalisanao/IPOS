import { v4 as uuidv4 } from 'uuid';

export type OfflineSyncStatus = 
    | 'queued' 
    | 'syncing' 
    | 'accepted' 
    | 'duplicate' 
    | 'rejected' 
    | 'conflict' 
    | 'failed' 
    | 'cancelled';

export interface OfflineTransactionEnvelope {
    id: string; // uuid
    batch_reference: string;
    offline_sequence: string;
    payload: any; // Original payload from cart
    payload_hash: string;
    previous_hash: string | null;
    row_hash: string;
    status: OfflineSyncStatus;
    created_at: string;
    updated_at: string;
    last_sync_attempt_at?: string | null;
    last_synced_at?: string | null;
    client_totals: {
        total: string; // Decimal string
        tax: string; // Decimal string
        subtotal: string; // Decimal string
    };
    error_message?: string;
}

export interface OfflineQueueSummary {
    queued: number;
    syncing: number;
    accepted: number;
    duplicate: number;
    rejected: number;
    conflict: number;
    failed: number;
    cancelled: number;
    total: number;
    lastSyncAttemptAt: string | null;
    lastSuccessfulSyncAt: string | null;
}

const DB_NAME = 'ipos_pos_offline_queue';
const DB_VERSION = 1;
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
                }
                if (!db.objectStoreNames.contains('metadata')) {
                    db.createObjectStore('metadata');
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

    private canonicalize(value: any): any {
        if (Array.isArray(value)) {
            return value.map((item) => this.canonicalize(item));
        }

        if (value && typeof value === 'object') {
            return Object.keys(value)
                .sort()
                .reduce((acc, key) => {
                    acc[key] = this.canonicalize(value[key]);
                    return acc;
                }, {} as Record<string, any>);
        }

        return value;
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
        return this.computeSHA256(JSON.stringify(this.canonicalize(payload)));
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

    public async getNextOfflineSequence(prefix: string, initialNextValue = 1): Promise<string> {
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
                const formattedSequence = `${prefix}${String(nextVal).padStart(8, '0')}`;
                
                // Advance the sequence
                const putReq = store.put(nextVal + 1, sequenceKey);
                putReq.onsuccess = () => resolve(formattedSequence);
                putReq.onerror = () => reject(putReq.error);
            };
            request.onerror = () => reject(request.error);
        });
    }

    public async appendTransaction(
        payload: any, 
        clientTotals: { total: string, tax: string, subtotal: string },
        options: { prefix: string, initialNextValue?: number, batchReference?: string }
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
        const batchReference = options.batchReference || uuidv4();
        const now = new Date().toISOString();
        const immutablePayload = this.cloneValue(payload);
        const sequence = await this.getNextOfflineSequence(options.prefix, options.initialNextValue || 1);

        const payloadHash = await this.computePayloadHash(immutablePayload);

        return new Promise((resolve, reject) => {
            const tx = db.transaction(['transactions', 'metadata'], 'readwrite');
            
            this.getLastHash(tx).then(async (previousHash) => {
                const rowHash = await this.computeRowHash(previousHash, payloadHash, sequence, batchReference);
                const envelope: OfflineTransactionEnvelope = {
                    id: uuidv4(),
                    batch_reference: batchReference,
                    offline_sequence: sequence,
                    payload: this.cloneValue(immutablePayload),
                    payload_hash: payloadHash,
                    previous_hash: previousHash,
                    row_hash: rowHash,
                    status: 'queued',
                    created_at: now,
                    updated_at: now,
                    last_sync_attempt_at: null,
                    last_synced_at: null,
                    client_totals: this.cloneValue(clientTotals),
                };

                const store = tx.objectStore('transactions');
                const addReq = store.add(envelope);

                addReq.onsuccess = () => {
                    this.updateLastHash(tx, rowHash).then(() => {
                        const frozenEnvelope = this.freezeDeep(this.cloneValue(envelope));
                        this.emitChange();
                        resolve(frozenEnvelope);
                    }).catch(reject);
                };
                addReq.onerror = () => reject(addReq.error);
            }).catch(reject);
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
                // Filter by queued or failed
                const pending = results.filter((r: OfflineTransactionEnvelope) => 
                    ['queued', 'failed'].includes(r.status)
                );
                resolve(pending.map((record: OfflineTransactionEnvelope) => this.freezeDeep(this.cloneValue(record))));
            };
            request.onerror = () => reject(request.error);
        });
    }

    public async updateTransactionStatus(id: string, newStatus: OfflineSyncStatus, errorMessage?: string): Promise<OfflineTransactionEnvelope> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['transactions'], 'readwrite');
            const store = tx.objectStore('transactions');
            const getReq = store.get(id);

            getReq.onsuccess = () => {
                const record: OfflineTransactionEnvelope = getReq.result;
                if (!record) {
                    return reject(new Error(`Transaction ${id} not found.`));
                }

                // Enforce status lifecycle
                if (!this.isValidStatusTransition(record.status, newStatus)) {
                    return reject(new Error(`Invalid status transition from ${record.status} to ${newStatus}`));
                }

                // Append-only rule: payload, sequence, and hashes cannot be modified.
                record.status = newStatus;
                record.updated_at = new Date().toISOString();
                if (newStatus === 'syncing') {
                    record.last_sync_attempt_at = record.updated_at;
                }
                if (['accepted', 'duplicate', 'rejected', 'conflict'].includes(newStatus)) {
                    record.last_synced_at = record.updated_at;
                }
                if (errorMessage !== undefined) {
                    record.error_message = errorMessage;
                } else if (newStatus !== 'failed') {
                    delete record.error_message;
                }

                const putReq = store.put(record);
                putReq.onsuccess = () => {
                    this.emitChange();
                    resolve(this.freezeDeep(this.cloneValue(record)));
                };
                putReq.onerror = () => reject(putReq.error);
            };
            getReq.onerror = () => reject(getReq.error);
        });
    }

    private isValidStatusTransition(current: OfflineSyncStatus, next: OfflineSyncStatus): boolean {
        if (current === next) return true;

        const allowedTransitions: Record<OfflineSyncStatus, OfflineSyncStatus[]> = {
            'queued': ['syncing', 'cancelled'],
            'syncing': ['accepted', 'duplicate', 'rejected', 'conflict', 'failed'],
            'failed': ['syncing', 'cancelled'],
            'accepted': [], // blocked from moving to queued or cancelled
            'rejected': [], // blocked from moving to queued
            'conflict': [], // blocked from moving to queued
            'cancelled': [],
            'duplicate': []
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
            acc[record.status] += 1;
            acc.total += 1;
            return acc;
        }, {
            queued: 0,
            syncing: 0,
            accepted: 0,
            duplicate: 0,
            rejected: 0,
            conflict: 0,
            failed: 0,
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
}

export const offlineSalesQueue = new OfflineSalesQueueService();
