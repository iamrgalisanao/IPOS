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

    getAll() {
        const req = new MockIDBRequest(Array.from(this.dataMap.values()));
        queueMicrotask(() => req.onsuccess && req.onsuccess({ target: req }));
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

        assert.match(envelope.offline_sequence, /^INV-T01-\d{8}$/);
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

    await t.test('offline capture is blocked when prefix is missing or registration cache is stale', async () => {
        await catalogCache.writeBootstrapPayload({
            ...bootstrapPayload,
            machine_profile_context: {
                ...bootstrapPayload.machine_profile_context,
                offline_sequence_prefix: null,
            },
        });

        assert.strictEqual(await canCaptureOffline(), false);
        const missingPrefix = await resolveOfflineCaptureReadiness();
        assert.strictEqual(missingPrefix.reason, 'missing_prefix');

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
            assert.strictEqual(url, '/api/pos/offline-sync');
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
        const accepted = records.find((record) => record.id === first.id);
        const duplicate = records.find((record) => record.id === second.id);
        assert.strictEqual(accepted.status, 'accepted');
        assert.strictEqual(duplicate.status, 'duplicate');

        const summary = await offlineSalesQueue.getStatusSummary();
        assert.strictEqual(summary.accepted, 1);
        assert.strictEqual(summary.duplicate, 1);
        assert.ok(summary.lastSuccessfulSyncAt);
    });

    await t.test('sync only runs online and network or 422 failures keep records retryable', async () => {
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
        assert.strictEqual(updated.status, 'failed');
        assert.match(updated.error_message, /queued on this terminal|rejected for now/i);

        axios.post = async () => {
            throw new Error('network down');
        };

        await offlineSyncManager.retryFailed();
        updated = (await offlineSalesQueue.getAllTransactions()).find((item) => item.id === record.id);
        assert.strictEqual(updated.status, 'failed');
        assert.match(updated.error_message, /remain safely queued/i);
    });
});