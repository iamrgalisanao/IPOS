/**
 * Split Payment Helper Unit Tests
 * 
 * Run with: node tests/Frontend/splitPaymentHelper.test.mjs
 */

import { 
    calculatePaymentTotals, 
    calculatePaymentProgress,
    validatePaymentRows, 
    requiresReference, 
    sanitizeReference,
    isCashPayment,
    calculateCashChange,
    buildSplitPaymentPayload
} from '../../resources/js/Pages/POS/helpers/splitPaymentHelper.js';

const assert = (condition, message) => {
    if (!condition) {
        console.error('❌ FAILED: ' + message);
        process.exit(1);
    }
};

console.log('Running Split Payment Helper Tests (Story 5.4)...');

const paymentMethods = [
    { id: '1', name: 'Cash', code: 'cash', type: 'cash', reference_required: false },
    { id: '2', name: 'GCash', code: 'gcash', type: 'digital', reference_required: true }
];

// 1. isCashPayment
assert(isCashPayment(paymentMethods[0]) === true, 'Cash method should be identified');
assert(isCashPayment(paymentMethods[1]) === false, 'GCash method should not be identified');

// 2. calculateCashChange
assert(calculateCashChange({ amount: '100', amount_tendered: '200' }) === 100, 'Change should be 100');
assert(calculateCashChange({ amount: '100', amount_tendered: '100' }) === 0, 'Change should be 0');
assert(calculateCashChange({ amount: '100', amount_tendered: '50' }) === 0, 'Change should not be negative');

// 3. validatePaymentRows (Cash Tendered)
// Case: Tendered below amount
const val1 = validatePaymentRows([{ payment_method_id: '1', amount: '100', amount_tendered: '50' }], paymentMethods, 100);
assert(val1.isValid === false, 'Under-tendered cash should fail');
assert(val1.errors.some(e => e.includes('Cash tendered is less than the required amount')), 'Should mention under-tendered');

// Case: Tendered exactly amount
const val2 = validatePaymentRows([{ payment_method_id: '1', amount: '100', amount_tendered: '100' }], paymentMethods, 100);
assert(val2.isValid === true, 'Exact cash tendered should pass');

// Case: Tendered over amount
const val3 = validatePaymentRows([{ payment_method_id: '1', amount: '100', amount_tendered: '200' }], paymentMethods, 100);
assert(val3.isValid === true, 'Over-tendered cash should pass');

// 4. buildSplitPaymentPayload (Exact Amount Verification)
const payload = buildSplitPaymentPayload([{ payment_method_id: '1', amount: '100', amount_tendered: '500' }]);
assert(payload.payments[0].amount === '100.0000', 'Payload must use exact amount, not tendered amount');

// 5. Split payment with digital + cash
const splitRows = [
    { payment_method_id: '2', amount: '300', reference_number: 'REF123' },
    { payment_method_id: '1', amount: '200', amount_tendered: '500' }
];
const val4 = validatePaymentRows(splitRows, paymentMethods, 500);
assert(val4.isValid === true, 'Valid split payment with cash change should pass');
assert(calculateCashChange(splitRows[1]) === 300, 'Split change should be 300');

// 6. Partial cash should expose remaining balance for adding another method
const partialCashRows = [
    { payment_method_id: '1', amount: '85', amount_tendered: '50' }
];
const progress = calculatePaymentProgress(partialCashRows, paymentMethods, 85);
assert(progress.totalPaid === 50, 'Partial cash progress should use tendered cash as paid amount');
assert(progress.remainingBalance === 35, 'Partial cash progress should expose remaining balance for another method');

console.log('✅ ALL FRONTEND HELPER TESTS PASSED (Story 5.4)');
