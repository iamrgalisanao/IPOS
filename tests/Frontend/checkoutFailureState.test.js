import { getCheckoutErrorMessage, getPosAccessIssue, shouldClearCart } from '../../resources/js/Pages/POS/helpers/checkoutFailureHelper.js';

function assert(condition, message) {
    if (!condition) {
        console.error('❌ FAIL:', message);
        process.exit(1);
    } else {
        console.log('✅ PASS:', message);
    }
}

console.log('Running Frontend Logic Tests (Node Harness)...');

// 1. Test Validation Failure (Inventory)
assert(
    getCheckoutErrorMessage(422, { inventory_errors: [] }) === 'One or more items are currently unavailable at this branch.',
    'Should return inventory error message for 422 with inventory_errors'
);

// 2. Test Validation Failure (General)
assert(
    getCheckoutErrorMessage(422, {}) === 'Some items could not be validated. Please review the cart.',
    'Should return general validation message for 422'
);

// 3. Test Conflict (409)
assert(
    getCheckoutErrorMessage(409, {}) === 'This checkout request changed unexpectedly. Please review the cart before trying again.',
    'Should return conflict message for 409'
);

// 4. Test Permissions (403)
assert(
    getCheckoutErrorMessage(403, {}) === 'You do not have permission to complete this sale.',
    'Should return permission message for 403'
);

// 5. Test Stale Session
assert(
    getCheckoutErrorMessage(419, {}) === 'Your POS session has expired. Reconnect or sign in again before continuing.',
    'Should return stale session message for 419'
);

// 6. Test Terminal Context Error
assert(
    getCheckoutErrorMessage(403, { code: 'TERMINAL_CONTEXT_INVALID', message: 'Terminal context missing.' }) === 'Terminal context missing.',
    'Should return terminal context message for terminal binding failures'
);

// 7. Test Timecard Required Error
assert(
    getCheckoutErrorMessage(403, { code: 'TIMECARD_REQUIRED' }) === 'You must be clocked in before completing this sale.',
    'Should return timecard-required message for clock-in failures'
);

// 8. Test Generic Error
assert(
    getCheckoutErrorMessage(500, {}) === 'The sale could not be completed. Your cart is safe. Please try again.',
    'Should return generic message for other errors'
);

// 9. Test Cart Preservation Logic
assert(shouldClearCart(true) === true, 'Should clear cart on success');
assert(shouldClearCart(false) === false, 'Should NOT clear cart on failure');

// 10. Test POS Access Issue Classification
assert(
    getPosAccessIssue(401, {})?.code === 'SESSION_EXPIRED',
    'Should classify 401 as a stale POS session access issue'
);

assert(
    getPosAccessIssue(403, { code: 'TERMINAL_CONTEXT_INVALID' })?.action === 'retry',
    'Should classify terminal context failures as retryable POS access issues'
);

assert(
    getPosAccessIssue(403, { code: 'TIMECARD_REQUIRED' })?.code === 'TIMECARD_REQUIRED',
    'Should classify timecard failures as POS access issues'
);

assert(
    getPosAccessIssue(422, {}) === null,
    'Should not classify validation errors as POS access issues'
);

console.log('\nAll Frontend Logic Tests Passed!');
