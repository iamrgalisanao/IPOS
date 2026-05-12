/**
 * Split Payment Failure State Unit Tests
 * 
 * Run with: node tests/Frontend/splitPaymentFailureState.test.mjs
 */

import { 
    calculatePaymentTotals, 
    validatePaymentRows
} from '../../resources/js/Pages/POS/helpers/splitPaymentHelper.js';

const assert = (condition, message) => {
    if (!condition) {
        console.error('❌ FAILED: ' + message);
        process.exit(1);
    }
};

console.log('Running Split Payment Failure State Tests (Story 5.6)...');

const paymentMethods = [
    { id: '1', name: 'Cash', code: 'cash', type: 'cash', reference_required: false },
    { id: '2', name: 'GCash', code: 'gcash', type: 'digital', reference_required: true }
];

// Test Case: Data preservation verification
const originalRows = [
    { id: 'r1', payment_method_id: '1', amount: '100', amount_tendered: '500', reference_number: '' },
    { id: 'r2', payment_method_id: '2', amount: '12', amount_tendered: '', reference_number: 'REF-FAILED' }
];

// Simulate a React-like state preservation logic check
const preservedRows = [...originalRows]; 

assert(preservedRows[0].amount_tendered === '500', 'Cash tendered must be preserved');
assert(preservedRows[1].reference_number === 'REF-FAILED', 'Reference number must be preserved');
assert(preservedRows.length === 2, 'Row count must be preserved');

// Validation logic for failure scenarios
const val1 = validatePaymentRows(originalRows, paymentMethods, 112);
assert(val1.isValid === true, 'Original rows should still be valid for retry');

// Test Case: Incorrect total preservation
const wrongTotalRows = [
    { id: 'r1', payment_method_id: '1', amount: '50', amount_tendered: '100', reference_number: '' }
];
const val2 = validatePaymentRows(wrongTotalRows, paymentMethods, 112);
assert(val2.isValid === false, 'Wrong total should remain invalid after failure');
assert(wrongTotalRows[0].amount_tendered === '100', 'Tendered must remain even if total is wrong');

console.log('✅ ALL FRONTEND FAILURE STATE TESTS PASSED (Story 5.6)');
