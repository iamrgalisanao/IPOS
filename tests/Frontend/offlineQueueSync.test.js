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
const { BrowserPrintAdapter } = await import('../../resources/js/POS/Hardware/BrowserPrintAdapter.js');
const { NoOpHardwareAdapter } = await import('../../resources/js/POS/Hardware/NoOpHardwareAdapter.js');

const bootstrapPayload = {
    products: [
        { id: 'product-1', name: 'Coffee', selling_price: '125.00' },
        { id: 'product-2', name: 'Tea', selling_price: '75.00' },
    ],
    categories: [],
    tax_categories: [],
    payment_methods: [],
    promotion_rules: [],
    tenant_context: { id: 'tenant-1', tax_mode: 'inclusive', offline_sales_enabled: true },
    branch_context: { id: 'branch-1', status: 'active', offline_sales_enabled: true },
    machine_profile_context: {
        id: 'machine-1',
        profile_code: 'POS-1',
        terminal_binding_epoch: 'epoch-1',
        status: 'active',
        offline_sales_enabled: null,
        offline_sequence_prefix: 'INV-T01-',
        offline_sequence_next_value: 1,
        offline_sequence_status: 'active',
    },
    permissions: ['create_sale'],
    tax_configuration_version_hash: 'hash-123',
    catalog_version_hash: 'catalog-hash-123',
    layout_version_hash: 'layout-hash-123',
    discount_rules_version_hash: 'discount-hash-123',
    payment_methods_version_hash: 'payment-hash-123',
    terminal_policy_version_hash: 'policy-hash-123',
    printer_profile_version_hash: 'printer-hash-123',
    config_snapshot_hash: 'snapshot-hash-123',
    config_snapshot: {
        schema_version: 1,
        config_snapshot_hash: 'snapshot-hash-123',
        catalog_version_hash: 'catalog-hash-123',
        tax_configuration_version_hash: 'hash-123',
    },
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
            terminal_binding_epoch: 'epoch-1',
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
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const second = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
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

    await t.test('valid config allows provisional capture and v1 sync updates local statuses correctly', async () => {
        const snapshot = await catalogCache.getConfigSnapshotMetadata();
        const first = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
            ...snapshot,
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const second = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:01:00.000Z',
            items: [{ product_id: 'product-2', quantity: 1, unit_price: '75.00' }],
            client_subtotal: '66.96',
            client_tax_total: '8.04',
            client_total: '75.00',
            ...snapshot,
        }, { subtotal: '66.96', tax: '8.04', total: '75.00' }, { prefix: 'INV-T01-', initialNextValue: 2 });

        navigator.onLine = true;
        globalState.status = 'online';

		axios.post = async (url, payload) => {
			assert.strictEqual(url, '/api/v1/pos/offline-sales/sync');
			const submittedSequence = payload.imports[0].offline_sequence_number;
			assert.ok([first.offline_sequence, second.offline_sequence].includes(submittedSequence));
			assert.ok(payload.imports[0].items.length > 0);
			assert.ok(payload.imports[0].business_payload_fingerprint);
			assert.strictEqual(payload.imports[0].payload_hash, payload.imports[0].business_payload_fingerprint);
			assert.strictEqual(payload.imports[0].config_snapshot_hash, 'snapshot-hash-123');
			assert.strictEqual(payload.imports[0].layout_version_hash, 'layout-hash-123');
			assert.strictEqual(payload.imports[0].catalog_version_hash, 'catalog-hash-123');
            assert.strictEqual(payload.imports[0].payment_methods_version_hash, 'payment-hash-123');
            assert.strictEqual(payload.imports[0].config_snapshot.config_snapshot_hash, 'snapshot-hash-123');
            assert.ok(payload.imports[0].offline_transaction_uuid);
            assert.ok(payload.imports[0].sync_attempt_id);
            assert.ok(payload.imports[0].lease_id);
            assert.strictEqual(payload.imports[0].attempt_generation, 1);
            assert.ok(payload.imports[0].queue_state_revision >= 3);

            return {
                status: 202,
                data: {
					imports: [
						{
							offline_transaction_uuid: payload.imports[0].offline_transaction_uuid,
							offline_sequence_number: submittedSequence,
							status: submittedSequence === first.offline_sequence ? 'accepted' : 'replayed'
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
        assert.strictEqual(synced.queue_state, 'processing_complete');
        assert.strictEqual(synced.server_state, 'accepted');
        assert.strictEqual(synced.resolution_state, 'resolved_posted');
        assert.strictEqual(synced.lease.lease_id, null);
        assert.strictEqual(duplicate.status, 'synced');

        const summary = await offlineSalesQueue.getStatusSummary();
		assert.strictEqual(summary.synced, 2);
		assert.ok(summary.lastSuccessfulSyncAt);
	});

	await t.test('missing v1 envelope result remains retryable instead of synced', async () => {
		const snapshot = await catalogCache.getConfigSnapshotMetadata();
		const record = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
			items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
			client_subtotal: '111.61',
			client_tax_total: '13.39',
			client_total: '125.00',
			...snapshot,
		}, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

		navigator.onLine = true;
		globalState.status = 'online';
		axios.post = async () => ({
			status: 200,
			data: { imports: [] },
		});

		await offlineSyncManager.processQueue();

		const updated = (await offlineSalesQueue.getAllTransactions()).find((item) => item.id === record.id);
		assert.strictEqual(updated.status, 'failed');
		assert.match(updated.error_message, /did not include a result/i);
		const retryable = await offlineSalesQueue.getQueuedTransactions();
		assert.strictEqual(retryable.some((item) => item.id === record.id), true);
	});

    await t.test('sync only runs online, 422 moves to review, and network failures stay retryable', async () => {
        const snapshot = await catalogCache.getConfigSnapshotMetadata();
        const record = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
            ...snapshot,
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
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:01:00.000Z',
            items: [{ product_id: 'product-2', quantity: 1, unit_price: '75.00' }],
            client_subtotal: '66.96',
            client_tax_total: '8.04',
            client_total: '75.00',
            ...snapshot,
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
            terminal_binding_epoch: 'epoch-1',
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

    await t.test('leases block duplicate workers, expired leases retry, and stale responses are ignored', async () => {
        const record = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const leased = await offlineSalesQueue.acquireLease(record.id, 'owner-a', 'test-worker', 'test', 45_000);
        await assert.rejects(
            () => offlineSalesQueue.acquireLease(record.id, 'owner-a', 'test-worker', 'test', 45_000),
            /already leased/
        );

        const stored = sharedDb.stores.get('transactions').dataMap.get(record.id);
        stored.lease.lease_expires_at = new Date(Date.now() - 1_000).toISOString();
        const retryable = await offlineSalesQueue.getQueuedTransactions();
        assert.strictEqual(retryable.some((item) => item.id === record.id), true);

        stored.last_sync_attempt_id = '00000000-0000-4000-8000-000000000002';
        stored.lease.lease_expires_at = new Date(Date.now() + 45_000).toISOString();
        await offlineSalesQueue.updateTransactionStatus(record.id, 'synced', undefined, {
            leaseId: leased.lease.lease_id,
            syncAttemptId: leased.last_sync_attempt_id,
            attemptGeneration: leased.last_attempt_generation,
            ownerInstanceId: 'owner-a',
        });

        const afterStale = sharedDb.stores.get('transactions').dataMap.get(record.id);
        assert.strictEqual(afterStale.status, 'syncing');
        assert.strictEqual(afterStale.queue_state, 'leased');
    });

    await t.test('diagnostics bundle is support-safe and includes hash-chain status', async () => {
        const record = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
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
        assert.strictEqual(bundle.storage.database_version, 3);
        assert.strictEqual(bundle.storage.persistent_storage_capability.supported, false);
        assert.strictEqual(bundle.storage.storage_state, 'storage_available');
        assert.strictEqual(bundle.storage.queue_health, 'healthy');
        assert.strictEqual(bundle.storage.terminal_recovery_state, 'none');
        assert.strictEqual(bundle.hash_chain_valid, true);
        assert.strictEqual(bundle.active_record_count, 1);
        assert.strictEqual(bundle.historical_record_count, 1);
        assert.strictEqual(bundle.tombstone_count, 0);
        assert.strictEqual(bundle.records[0].offline_sequence, record.offline_sequence);
        assert.strictEqual(bundle.records[0].persistence_state, 'durably_captured');
        assert.strictEqual(bundle.records[0].queue_state, 'pending');
        assert.strictEqual(bundle.records[0].server_state, 'not_submitted');
        assert.strictEqual(bundle.records[0].resolution_state, 'none');
        assert.strictEqual(bundle.records[0].retention_state, 'full_payload');
        assert.ok(bundle.records[0].queue_state_revision >= 2);
        assert.strictEqual(bundle.records[0].terminal_id, 'terminal-1');
        assert.strictEqual(bundle.records[0].branch_id, 'branch-1');
        assert.strictEqual(bundle.records[0].cashier_shift_id, 'shift-1');
        assert.ok(bundle.records[0].payload_hash);
        assert.strictEqual(bundle.records[0].payload, undefined);
        assert.strictEqual(JSON.stringify(bundle).includes('do not export this raw payload'), false);
    });

    await t.test('queue health heartbeat classifies possible storage loss without inventing sync status', async () => {
        await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
            terminal_id: 'terminal-1',
            terminal_binding_epoch: '5',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const heartbeat = await offlineSalesQueue.recordQueueHealthHeartbeat({
            terminal_id: 'terminal-1',
            terminal_binding_epoch: '5',
            storage_state: 'storage_available',
        });

        assert.strictEqual(heartbeat.unresolved_count, 1);
        assert.strictEqual(heartbeat.queue_health, 'healthy');
        assert.strictEqual(heartbeat.highest_local_sequence, 'INV-T01-000001');

        const recoveryState = await offlineSalesQueue.compareQueueHealthAfterReactivation({
            terminal_id: 'terminal-1',
            terminal_binding_epoch: '5',
            local_profile_empty: true,
        });

        assert.strictEqual(recoveryState, 'possible_storage_loss');

        const bundle = await offlineSalesQueue.getDiagnosticsBundle();
        assert.strictEqual(bundle.storage.terminal_recovery_state, 'possible_storage_loss');
        assert.strictEqual(bundle.storage.queue_health, 'support_required');
        assert.strictEqual(bundle.summary.pending, 1);
    });

    await t.test('server-issued binding rejects client attempts to restore an older epoch', async () => {
        await offlineSalesQueue.recordServerIssuedBinding({
            terminal_id: 'terminal-1',
            terminal_binding_epoch: '7',
            binding_issued_at: '2026-05-20T12:00:00.000Z',
            binding_status: 'active',
        });

        await assert.rejects(
            () => offlineSalesQueue.recordServerIssuedBinding({
                terminal_id: 'terminal-1',
                terminal_binding_epoch: '6',
                binding_issued_at: '2026-05-20T12:01:00.000Z',
                binding_status: 'active',
            }),
            /older terminal binding epoch/
        );

        await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: '7',
            submitted_at: '2026-05-20T12:00:00.000Z',
            terminal_id: 'terminal-1',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        await assert.rejects(
            () => offlineSalesQueue.recordServerIssuedBinding({
                terminal_id: 'terminal-2',
                terminal_binding_epoch: '1',
                binding_issued_at: '2026-05-20T12:02:00.000Z',
                binding_status: 'active',
            }),
            /Unresolved queue belongs/
        );
    });

    await t.test('maintenance leases are distinct from sync and block competing mutators', async () => {
        const record = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const leased = await offlineSalesQueue.acquireMaintenanceLease(record.id, 'maintenance-worker', 'migration');
        assert.strictEqual(leased.lease.lease_purpose, 'migration');
        assert.strictEqual(leased.lease.worker_type, 'offline-queue-migration');
        assert.strictEqual(leased.status, 'pending');
        assert.strictEqual(leased.last_sync_attempt_id, null);

        await assert.rejects(
            () => offlineSalesQueue.acquireLease(record.id, 'sync-worker', 'offline-sales-sync', 'story-41.7'),
            /already leased/
        );
    });

    await t.test('bounded support export uses allowlisted fields and rejects broad dumps', async () => {
        const record = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
            terminal_id: 'terminal-1',
            terminal_binding_epoch: '8',
            cash_status: 'collected',
            customer_secret: 'never export me',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        await assert.rejects(
            () => offlineSalesQueue.getDiagnosticsBundle({ generatedBy: 'support-1' }),
            /bounded filter/
        );
        await assert.rejects(
            () => offlineSalesQueue.getDiagnosticsBundle({ generatedBy: 'support-1', from: 'not-a-date', to: 'also-not-a-date' }),
            /invalid date bound/
        );

        const exportBundle = await offlineSalesQueue.getDiagnosticsBundle({
            generatedBy: 'support-1',
            offlineTransactionUuid: record.offline_transaction_uuid,
        });

        assert.ok(exportBundle.export_id);
        assert.ok(exportBundle.export_checksum);
        assert.strictEqual(exportBundle.generated_by, 'support-1');
        assert.strictEqual(exportBundle.label, 'provisional local evidence');
        assert.strictEqual(exportBundle.records.length, 1);
        assert.strictEqual(exportBundle.records[0].payload, undefined);
        assert.strictEqual(JSON.stringify(exportBundle).includes('never export me'), false);
    });

    await t.test('UI acknowledgment is append-only recovery evidence', async () => {
        const record = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        await offlineSalesQueue.recordCaptureUiAcknowledged(record.offline_transaction_uuid, {
            session_id: 'session-1',
            cashier_id: 'cashier-1',
            acknowledged_at: '2026-05-20T12:00:05.000Z',
        });

        const stored = sharedDb.stores.get('transactions').dataMap.get(record.id);
        assert.strictEqual(stored.payload.session_id, undefined);
        const events = Array.from(sharedDb.stores.get('offline_recovery_events').dataMap.values());
        assert.strictEqual(events.length, 1);
        assert.strictEqual(events[0].event_type, 'offline_capture_ui_acknowledged');
        assert.strictEqual(events[0].details.session_id, 'session-1');
    });

    await t.test('resolved-record pruning never removes unresolved queue records', async () => {
        const oldSynced = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:00:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 1 });

        const failed = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:01:00.000Z',
            items: [{ product_id: 'product-2', quantity: 1, unit_price: '75.00' }],
            client_subtotal: '66.96',
            client_tax_total: '8.04',
            client_total: '75.00',
        }, { subtotal: '66.96', tax: '8.04', total: '75.00' }, { prefix: 'INV-T01-', initialNextValue: 2 });

        const conflict = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
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
        syncedRecord.payload.server_sale_uuid = 'sale-1';
        syncedRecord.updated_at = oldDate;
        syncedRecord.last_synced_at = oldDate;

        const result = await offlineSalesQueue.pruneResolvedTransactions(7, new Date('2026-06-01T12:00:00.000Z'));
        const remaining = await offlineSalesQueue.getAllTransactions();
        const diagnostics = await offlineSalesQueue.getDiagnosticsBundle();
        const tombstones = Array.from(sharedDb.stores.get('offline_tombstones').dataMap.values());

        assert.deepStrictEqual(result, { pruned: 1, retained: 2 });
        assert.strictEqual(diagnostics.tombstone_count, 1);
        assert.ok(tombstones[0].tombstone_checksum);
        assert.strictEqual(tombstones[0].tombstone_schema_version, 1);
        assert.ok(tombstones[0].retained_server_reference);
        assert.strictEqual(remaining.some((record) => record.id === oldSynced.id), false);
        assert.strictEqual(remaining.some((record) => record.id === failed.id), true);
        assert.strictEqual(remaining.some((record) => record.id === conflict.id), true);

        const missingServerIdentity = await offlineSalesQueue.appendTransaction({
            terminal_binding_epoch: 'epoch-1',
            submitted_at: '2026-05-20T12:03:00.000Z',
            items: [{ product_id: 'product-1', quantity: 1, unit_price: '125.00' }],
            client_subtotal: '111.61',
            client_tax_total: '13.39',
            client_total: '125.00',
        }, { subtotal: '111.61', tax: '13.39', total: '125.00' }, { prefix: 'INV-T01-', initialNextValue: 4 });
        await offlineSalesQueue.updateTransactionStatus(missingServerIdentity.id, 'syncing');
        await offlineSalesQueue.updateTransactionStatus(missingServerIdentity.id, 'synced');
        const missingIdentityRecord = sharedDb.stores.get('transactions').dataMap.get(missingServerIdentity.id);
        missingIdentityRecord.updated_at = oldDate;
        missingIdentityRecord.last_synced_at = oldDate;

        await assert.rejects(
            () => offlineSalesQueue.pruneResolvedTransactions(7, new Date('2026-06-01T12:00:00.000Z')),
            /without required server identity/
        );
    });

    await t.test('hardware adapters do not overclaim physical readiness', async () => {
        let printCalled = false;
        global.window.print = () => {
            printCalled = true;
        };

        const browser = new BrowserPrintAdapter();
        const browserPrint = await browser.printReceipt({ total: '125.00' });
        const browserStatus = await browser.getHardwareStatus();

        assert.strictEqual(printCalled, true);
        assert.strictEqual(browserPrint.status, 'browser_print_invoked');
        assert.strictEqual(browserPrint.physically_validated, false);
        assert.strictEqual(await browser.getPrinterStatus(), 'available_limited');
        assert.strictEqual(browserStatus.printerCapability, 'available_limited');
        assert.strictEqual(browserStatus.physicallyValidated, false);

        global.window.print = () => {
            throw new Error('printer bridge unavailable');
        };
        const failedBrowserPrint = await browser.printReceipt({ total: '125.00' });
        assert.strictEqual(failedBrowserPrint.status, 'hardware_failed');
        assert.strictEqual(failedBrowserPrint.error_code, 'browser_print_failed');

        const noop = new NoOpHardwareAdapter();
        const noopPrint = await noop.printReceipt({ total: '125.00' });
        const noopDrawer = await noop.openCashDrawer();
        const noopStatus = await noop.getHardwareStatus();

        assert.strictEqual(noopPrint.status, 'hardware_unavailable');
        assert.strictEqual(noopDrawer.status, 'hardware_unavailable');
        assert.strictEqual(await noop.getPrinterStatus(), 'unavailable');
        assert.strictEqual(noopStatus.printerCapability, 'unavailable');
        assert.strictEqual(noopStatus.drawerCapability, 'unavailable');
        assert.strictEqual(noopStatus.physicallyValidated, false);
    });
});
