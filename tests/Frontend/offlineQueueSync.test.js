import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import axios from 'axios';

class MockIDBRequest {
    constructor(result = null) {
        this.result = result;
        this.onsuccess = null;
        this.onerror = null;
    }
}

class MockIDBObjectStore {
    constructor(dataMap = new Map()) {
        this.dataMap = dataMap;
    }

    createIndex() {}

    clear() {
        this.dataMap.clear();
        const req = new MockIDBRequest();
        queueMicrotask(() => req.onsuccess && req.onsuccess({ target: req }));
        return req;
    }

    put(value, key) {
        const resolvedKey = key !== undefined ? key : value.id;
        this.dataMap.set(resolvedKey, value);
        const req = new MockIDBRequest(resolvedKey);
        queueMicrotask(() => req.onsuccess && req.onsuccess({ target: req }));
        return req;
    }

    add(value) {
        return this.put(value, value.id);
    }

    get(key) {
        const req = new MockIDBRequest(this.dataMap.get(key));
        queueMicrotask(() => req.onsuccess && req.onsuccess({ target: req }));
        return req;
    }

    delete(key) {
        this.dataMap.delete(key);
        const req = new MockIDBRequest();
        queueMicrotask(() => req.onsuccess && req.onsuccess({ target: req }));
        return req;
    }

    getAll() {
        const req = new MockIDBRequest(Array.from(this.dataMap.values()));
        queueMicrotask(() => req.onsuccess && req.onsuccess({ target: req }));
        return req;
    }

    openCursor() {
        const req = new MockIDBRequest();
        const values = Array.from(this.dataMap.values());
        let index = 0;

        const dispatch = () => {
            if (index >= values.length) {
                req.result = null;
            } else {
                req.result = {
                    value: values[index],
                    continue() {
                        index += 1;
                        queueMicrotask(dispatch);
                    },
                };
            }

            req.onsuccess && req.onsuccess({ target: req });
        };

        queueMicrotask(dispatch);
        return req;
    }
}

class MockIDBTransaction {
    constructor(stores) {
        this.stores = stores;
        this.oncomplete = null;
        this.onerror = null;
        queueMicrotask(() => this.oncomplete && this.oncomplete());
    }

    objectStore(name) {
        return this.stores[name];
    }
}

class MockIDBDatabase {
    constructor() {
        this.stores = new Map();
        this.objectStoreNames = {
            contains: (name) => this.stores.has(name),
        };
    }

    createObjectStore(name) {
        const store = new MockIDBObjectStore();
        this.stores.set(name, store);
        return store;
    }

    transaction(storeNames) {
        const names = Array.isArray(storeNames) ? storeNames : [storeNames];
        const scopedStores = Object.fromEntries(names.map((name) => {
            if (!this.stores.has(name)) {
                this.stores.set(name, new MockIDBObjectStore());
            }

            return [name, this.stores.get(name)];
        }));

        return new MockIDBTransaction(scopedStores);
    }
}

const sharedDb = new MockIDBDatabase();

class MockIDBOpenRequest {
    constructor() {
        this.result = sharedDb;
        this.onsuccess = null;
        this.onupgradeneeded = null;
        this.onerror = null;

        queueMicrotask(() => {
            this.onupgradeneeded && this.onupgradeneeded({ target: this });
            this.onsuccess && this.onsuccess({ target: this });
        });
    }
}

global.indexedDB = {
    open() {
        return new MockIDBOpenRequest();
    }
};

Object.defineProperty(global, 'navigator', {
    value: { onLine: true },
    configurable: true,
    writable: true,
});

global.window = {
    addEventListener() {},
    removeEventListener() {},
};

axios.get = async () => ({ status: 200, data: {} });

const { catalogCache } = await import('../../resources/js/POS/offline/catalogCache.ts');
const { offlineSalesQueue } = await import('../../resources/js/POS/offline/offlineSalesQueue.ts');
const { offlineSyncManager } = await import('../../resources/js/POS/offline/offlineSyncManager.ts');
const { canCaptureOffline, resolveOfflineCaptureReadiness } = await import('../../resources/js/POS/offline/offlineGuards.ts');
const { globalState } = await import('../../resources/js/POS/offline/connectivityStore.ts');

const bootstrapPayload = {
    products: [
        { id: 'product-1', name: 'Coffee', selling_price: '125.00' },
        { id: 'product-2', name: 'Tea', selling_price: '75.00' },
    ],
    categories: [],
    tax_categories: [],
    tenant_context: { id: 'tenant-1', tax_mode: 'inclusive', offline_sales_enabled: true },
    branch_context: { id: 'branch-1', status: 'active', offline_sales_enabled: true },
    machine_profile_context: {
        id: 'machine-1',
        profile_code: 'POS-1',
        status: 'active',
        offline_sales_enabled: null,
        offline_sequence_prefix: 'INV-T01-',
        offline_sequence_next_value: 1,
        offline_sequence_status: 'active',
    },
    permissions: ['create_sale'],
    tax_configuration_version_hash: 'hash-123',
    generated_at: new Date().toISOString(),
    cache_ttl_seconds: 3600,
};

beforeEach(async () => {
    sharedDb.stores.clear();
    offlineSalesQueue.db = null;
    catalogCache.db = null;
    navigator.onLine = true;
    globalState.status = 'online';
    globalState.isStale = false;
    globalState.lastSyncedAt = null;
    axios.post = async () => ({ status: 202, data: { imports: [] } });
    axios.get = async () => ({ status: 200, data: bootstrapPayload });
    await catalogCache.writeBootstrapPayload(bootstrapPayload);
});

test('Story 28.11 offline queue and sync hardening', async (t) => {
    await t.test('queue append works and payload/hash/sequence remain immutable', async () => {
        const envelope = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 2, unit_price: '125.00' }],
            client_subtotal: '223.21',
            client_tax_total: '26.79',
            client_total: '250.00',
        }, {
            subtotal: '223.21',
            tax: '26.79',
            total: '250.00',
        }, {
            prefix: 'INV-T01-',
            initialNextValue: 1,
        });

        assert.match(envelope.offline_sequence, /^INV-T01-\d{6}$/);
        assert.ok(envelope.payload_hash);
        assert.ok(envelope.row_hash);
        assert.throws(() => {
            envelope.payload.items[0].quantity = 99;
        }, TypeError);
    });

    await t.test('hash chain detects tampering across queued records', async () => {
        const first = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const second = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:01:00.000Z',
            items: [{ product_id: 'product-2', quantity: 1, unit_price: '75.00' }],
            client_subtotal: '66.96',
            client_tax_total: '8.04',
            client_total: '75.00',
        }, { subtotal: '66.96', tax: '8.04', total: '75.00' }, { prefix: 'INV-T01-', initialNextValue: 2 });

        assert.ok(first.row_hash);
        assert.strictEqual(second.previous_hash, first.row_hash);
        assert.strictEqual(await offlineSalesQueue.verifyHashChain(), true);

        sharedDb.stores.get('transactions').dataMap.get(second.id).payload.items[0].quantity = 9;
        assert.strictEqual(await offlineSalesQueue.verifyHashChain(), false);
    });

    await t.test('offline capture allows local prefix fallback but blocks stale registration cache', async () => {
        await catalogCache.writeBootstrapPayload({
            ...bootstrapPayload,
            machine_profile_context: {
                ...bootstrapPayload.machine_profile_context,
                offline_sequence_prefix: null,
            },
        });

        assert.strictEqual(await canCaptureOffline(), true);
        const missingPrefix = await resolveOfflineCaptureReadiness();
        assert.strictEqual(missingPrefix.reason, 'allowed');

        await catalogCache.writeBootstrapPayload({
            ...bootstrapPayload,
            generated_at: new Date(Date.now() - (73 * 60 * 60 * 1000)).toISOString(),
        });

        const staleReadiness = await resolveOfflineCaptureReadiness();
        assert.strictEqual(staleReadiness.allowed, false);
        assert.strictEqual(staleReadiness.reason, 'stale_registration_cache');
    });

    await t.test('valid config allows provisional capture and sync 202 updates local statuses correctly', async () => {
        const first = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const second = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:01:00.000Z',
            items: [{ product_id: 'product-2', quantity: 1, unit_price: '75.00' }],
            client_subtotal: '66.96',
            client_tax_total: '8.04',
            client_total: '75.00',
        }, { subtotal: '66.96', tax: '8.04', total: '75.00' }, { prefix: 'INV-T01-', initialNextValue: 2 });

        navigator.onLine = true;
        globalState.status = 'online';

        axios.post = async (url, payload) => {
            assert.strictEqual(url, '/pos/offline-sync');
            const submittedSequence = payload.imports[0].offline_sequence_number;
            assert.ok([first.offline_sequence, second.offline_sequence].includes(submittedSequence));
            assert.ok(payload.imports[0].items.length > 0);

            return {
                status: 202,
                data: {
                    imports: [
                        {
                            offline_sequence_number: submittedSequence,
                            status: submittedSequence === first.offline_sequence ? 'pending' : 'duplicate'
                        },
                    ],
                },
            };
        };

        await offlineSyncManager.processQueue();

        const records = await offlineSalesQueue.getAllTransactions();
        const synced = records.find((record) => record.id === first.id);
        const duplicate = records.find((record) => record.id === second.id);
        assert.strictEqual(synced.status, 'synced');
        assert.strictEqual(duplicate.status, 'synced');

        const summary = await offlineSalesQueue.getStatusSummary();
        assert.strictEqual(summary.synced, 2);
        assert.ok(summary.lastSuccessfulSyncAt);
    });

    await t.test('sync only runs online, 422 moves to review, and network failures stay retryable', async () => {
        const record = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        navigator.onLine = false;
        globalState.status = 'offline';
        let postCalled = false;
        axios.post = async () => {
            postCalled = true;
            return { status: 202, data: { imports: [] } };
        };

        await offlineSyncManager.processQueue();
        assert.strictEqual(postCalled, false);

        navigator.onLine = true;
        globalState.status = 'online';
        axios.post = async () => {
            const error = new Error('validation failed');
            error.response = { status: 422, data: { message: 'Offline sync batch rejected for now.' } };
            throw error;
        };

        await offlineSyncManager.processQueue();
        let updated = (await offlineSalesQueue.getAllTransactions()).find((item) => item.id === record.id);
        assert.strictEqual(updated.status, 'conflict');
        assert.match(updated.error_message, /review|rejected for now/i);

        const retryable = await offlineSalesQueue.getQueuedTransactions();
        assert.strictEqual(retryable.some((item) => item.id === record.id), false);

        const networkRecord = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:01:00.000Z',
            items: [{ product_id: 'product-2', quantity: 1, unit_price: '75.00' }],
            client_subtotal: '66.96',
            client_tax_total: '8.04',
            client_total: '75.00',
        }, { subtotal: '66.96', tax: '8.04', total: '75.00' }, { prefix: 'INV-T01-', initialNextValue: 2 });

        sharedDb.stores.get('transactions').dataMap.get(networkRecord.id).last_sync_attempt_at = new Date(Date.now() - 60_000).toISOString();
        axios.post = async () => {
            throw new Error('network down');
        };

        await offlineSyncManager.retryFailed();
        updated = (await offlineSalesQueue.getAllTransactions()).find((item) => item.id === networkRecord.id);
        assert.strictEqual(updated.status, 'failed');
        assert.match(updated.error_message, /remain safely queued/i);
    });

    await t.test('legacy failed 422 records move to review without another sync POST', async () => {
        const record = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const storedRecord = sharedDb.stores.get('transactions').dataMap.get(record.id);
        storedRecord.status = 'failed';
        storedRecord.error_message = 'Request failed with status code 422';
        storedRecord.last_sync_attempt_at = new Date(Date.now() - 60_000).toISOString();

        navigator.onLine = true;
        globalState.status = 'online';
        let postCalled = false;
        axios.post = async () => {
            postCalled = true;
            throw new Error('Legacy validation failures should not be posted again.');
        };

        await offlineSyncManager.retryFailed();

        const updated = (await offlineSalesQueue.getAllTransactions()).find((item) => item.id === record.id);
        assert.strictEqual(postCalled, false);
        assert.strictEqual(updated.status, 'conflict');
        assert.match(updated.error_message, /422/);
    });

    await t.test('diagnostics bundle is support-safe and includes hash-chain status', async () => {
        const record = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:00:00.000Z',
            terminal_id: 'terminal-1',
            branch_id: 'branch-1',
            cashier_shift_id: 'shift-1',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
            sensitive_customer_note: 'do not export this raw payload',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const bundle = await offlineSalesQueue.getDiagnosticsBundle();

        assert.strictEqual(bundle.storage.indexed_db_available, true);
        assert.strictEqual(bundle.storage.database_name, 'ipos_pos_offline_queue');
        assert.strictEqual(bundle.storage.database_version, 1);
        assert.strictEqual(bundle.hash_chain_valid, true);
        assert.strictEqual(bundle.active_record_count, 1);
        assert.strictEqual(bundle.historical_record_count, 1);
        assert.strictEqual(bundle.records[0].offline_sequence, record.offline_sequence);
        assert.strictEqual(bundle.records[0].terminal_id, 'terminal-1');
        assert.strictEqual(bundle.records[0].branch_id, 'branch-1');
        assert.strictEqual(bundle.records[0].cashier_shift_id, 'shift-1');
        assert.ok(bundle.records[0].payload_hash);
        assert.strictEqual(bundle.records[0].payload, undefined);
        assert.strictEqual(JSON.stringify(bundle).includes('do not export this raw payload'), false);
    });

    await t.test('resolved-record pruning never removes unresolved queue records', async () => {
        const oldSynced = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const failed = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:01:00.000Z',
            items: [{ product_id: 'product-2', quantity: 1, unit_price: '75.00' }],
            client_subtotal: '66.96',
            client_tax_total: '8.04',
            client_total: '75.00',
        }, { subtotal: '66.96', tax: '8.04', total: '75.00' }, { prefix: 'INV-T01-', initialNextValue: 2 });

        const conflict = await offlineSalesQueue.appendTransaction({
            submitted_at: '2026-05-20T12:02:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 3 });

        await offlineSalesQueue.updateTransactionStatus(oldSynced.id, 'syncing');
        await offlineSalesQueue.updateTransactionStatus(oldSynced.id, 'synced');
        await offlineSalesQueue.updateTransactionStatus(failed.id, 'syncing');
        await offlineSalesQueue.updateTransactionStatus(failed.id, 'failed', 'network down');
        await offlineSalesQueue.updateTransactionStatus(conflict.id, 'syncing');
        await offlineSalesQueue.updateTransactionStatus(conflict.id, 'conflict', 'sequence_out_of_order');

        const oldDate = '2026-05-20T12:00:00.000Z';
        const syncedRecord = sharedDb.stores.get('transactions').dataMap.get(oldSynced.id);
        syncedRecord.updated_at = oldDate;
        syncedRecord.last_synced_at = oldDate;

        const result = await offlineSalesQueue.pruneResolvedTransactions(7, new Date('2026-06-01T12:00:00.000Z'));
        const remaining = await offlineSalesQueue.getAllTransactions();

        assert.deepStrictEqual(result, { pruned: 1, retained: 2 });
        assert.strictEqual(remaining.some((record) => record.id === oldSynced.id), false);
        assert.strictEqual(remaining.some((record) => record.id === failed.id), true);
        assert.strictEqual(remaining.some((record) => record.id === conflict.id), true);
    });
});
