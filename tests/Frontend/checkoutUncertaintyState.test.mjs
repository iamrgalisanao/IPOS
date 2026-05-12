import { test } from 'node:test';
import assert from 'node:assert/strict';
import { useTransactionStore } from '../../resources/js/Pages/POS/hooks/useTransactionStore.js';
import {
    createUncertainCheckoutError,
    getGuardianPresentation,
    isUncertainCheckoutError,
} from '../../resources/js/Pages/POS/helpers/checkoutFailureHelper.js';

const mockStorage = new Map();
global.window = {
    localStorage: {
        getItem: (key) => mockStorage.get(key) || null,
        setItem: (key, val) => mockStorage.set(key, val),
        removeItem: (key) => mockStorage.delete(key),
        clear: () => mockStorage.clear(),
    },
};

test('checkout uncertainty helper and persistence behavior', async (t) => {
    const store = useTransactionStore();

    await t.test('uncertain checkout error is classified correctly', () => {
        const error = createUncertainCheckoutError();

        assert.equal(isUncertainCheckoutError(error), true);
        assert.equal(isUncertainCheckoutError({ name: 'AbortError' }), true);
        assert.equal(isUncertainCheckoutError(new Error('plain failure')), false);
    });

    await t.test('guardian presentation maps retry and confirmed states to tri-signal tones', () => {
        assert.equal(getGuardianPresentation('retry_available').tone, 'amber');
        assert.equal(getGuardianPresentation('confirmed').tone, 'emerald');
        assert.equal(getGuardianPresentation('restored').tone, 'blue');
        assert.equal(getGuardianPresentation('failed').tone, 'red');
    });

    await t.test('checking draft persistence retains active sale and payment rows for recovery', () => {
        mockStorage.clear();
        const context = { tenantId: 't1', branchId: 'b1', userId: 'u1' };
        const paymentRows = [
            {
                id: 'row-1',
                payment_method_id: 'cash',
                amount: '100.0000',
                amount_tendered: '500.0000',
                reference_number: '',
            },
        ];

        store.saveDraft(context, {
            items: [{ product_id: 'p1', display_name: 'Item 1', quantity: 1, unit_price: 100 }],
            totals: { subtotal: 100 },
            cartState: 'checking',
            clientRequestUuid: '11111111-1111-4111-8111-111111111111',
            activeSale: { id: 'sale-1', total: '100.0000', ignored: 'x' },
            paymentRows,
            paymentWizardOpen: true,
        });

        const key = store.getDraftKey(context);
        const draft = JSON.parse(mockStorage.get(key));

        assert.equal(draft.cart_state, 'checking');
        assert.deepEqual(draft.active_sale, { id: 'sale-1', total: '100.0000' });
        assert.equal(draft.payment_wizard_open, true);
        assert.deepEqual(draft.payment_rows, paymentRows);
    });
});