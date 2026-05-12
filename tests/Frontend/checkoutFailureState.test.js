import { getCheckoutErrorMessage, shouldClearCart } from '../../resources/js/Pages/POS/helpers/checkoutFailureHelper.js';

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

// 5. Test Generic Error
assert(
    getCheckoutErrorMessage(500, {}) === 'The sale could not be completed. Your cart is safe. Please try again.',
    'Should return generic message for other errors'
);

// 6. Test Cart Preservation Logic
assert(shouldClearCart(true) === true, 'Should clear cart on success');
assert(shouldClearCart(false) === false, 'Should NOT clear cart on failure');

console.log('\nAll Frontend Logic Tests Passed!');
