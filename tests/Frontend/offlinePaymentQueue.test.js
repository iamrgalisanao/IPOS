import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import axios from 'axios';

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
    axios.post = async () => ({ status: 200, data: {} });
});

test('offline payment queue sync behavior', async (t) => {
    await t.test('server sale payment posts and is removed from queue', async () => {
        let postedUrl = null;
        axios.post = async (url) => {
            postedUrl = url;
            return { status: 200, data: {} };
        };

        offlinePaymentQueue.queuePayment({
            sale_id: 'sale-123',
            payload: {
                payments: [{ payment_method_id: 'cash', amount: '45.0000', reference_number: null }],
            },
            rows: [],
            context: {},
        });

        await offlinePaymentQueue.processQueue();

        assert.strictEqual(postedUrl, '/pos/sales/sale-123/payments/split');
        assert.deepStrictEqual(offlinePaymentQueue.getAllPayments(), []);
    });

    await t.test('offline draft payment is quarantined without posting to server', async () => {
        let postCalled = false;
        axios.post = async () => {
            postCalled = true;
            throw new Error('Offline drafts must not post to the server payment endpoint.');
        };

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
        assert.strictEqual(postCalled, false);
        assert.strictEqual(updated.status, 'conflict');
        assert.match(updated.last_error, /offline draft payments cannot sync/i);
    });
});
