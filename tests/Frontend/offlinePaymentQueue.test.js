import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

const storage = new Map();

global.localStorage = {
    getItem(key) {
        return storage.has(key) ? storage.get(key) : null;
    },
    setItem(key, value) {
        storage.set(key, String(value));
    },
    removeItem(key) {
        storage.delete(key);
    },
    clear() {
        storage.clear();
    },
};

global.window = {
    addEventListener() {},
    removeEventListener() {},
};

const { offlinePaymentQueue } = await import('../../resources/js/POS/offline/offlinePaymentQueue.ts');

beforeEach(() => {
    storage.clear();
});

test('offline payment queue sync behavior', async (t) => {
    await t.test('server sale payment is quarantined instead of queued for posting', async () => {
        const record = offlinePaymentQueue.queuePayment({
            sale_id: 'sale-123',
            payload: {
                payments: [{ payment_method_id: 'cash', amount: '45.0000', reference_number: null }],
            },
            rows: [],
            context: {},
        });

        await offlinePaymentQueue.processQueue();

        const updated = offlinePaymentQueue.getAllPayments().find((payment) => payment.id === record.id);
        const summary = offlinePaymentQueue.getStatusSummary();

        assert.strictEqual(updated.status, 'legacy_conflict');
        assert.strictEqual(summary.conflict, 1);
        assert.match(updated.last_error, /server-created sales is disabled/i);
        assert.strictEqual(updated.legacy_quarantine.quarantine_reason, 'offline_server_sale_payment_queue_disabled');
    });

    await t.test('offline draft payment is quarantined without posting to server', async () => {
        const record = offlinePaymentQueue.queuePayment({
            sale_id: 'offline-draft-94edb2c0-528d-46e4-ab15-50e1f011ccf1',
            payload: {
                payments: [{ payment_method_id: 'cash', amount: '45.0000', reference_number: null }],
            },
            rows: [],
            context: {},
        });

        await offlinePaymentQueue.processQueue();

        const updated = offlinePaymentQueue.getAllPayments().find((payment) => payment.id === record.id);
        assert.strictEqual(updated.status, 'legacy_conflict');
        assert.match(updated.last_error, /server-created sales is disabled/i);
        assert.strictEqual(updated.legacy_quarantine.quarantine_reason, 'offline_draft_payment_legacy_queue');
    });

    await t.test('legacy pending records are migrated non-destructively', async () => {
        localStorage.setItem('ipos_pending_server_sale_payments_v1', JSON.stringify([{
            id: 'legacy-1',
            sale_id: 'sale-legacy',
            payload: {
                payments: [{ payment_method_id: 'cash', amount: '45.0000', reference_number: null }],
            },
            rows: [],
            context: {},
            status: 'pending',
            created_at: '2026-07-17T00:00:00.000Z',
            updated_at: '2026-07-17T00:00:00.000Z',
            last_error: null,
        }]));

        await offlinePaymentQueue.processQueue();

        const [updated] = offlinePaymentQueue.getAllPayments();
        assert.strictEqual(updated.status, 'legacy_conflict');
        assert.strictEqual(updated.legacy_quarantine.original_status, 'pending');
        assert.strictEqual(updated.sale_id, 'sale-legacy');
    });
});
