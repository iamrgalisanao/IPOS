/**
 * Helper to determine the user-friendly error message based on backend response.
 * Standardizes messages according to Story 4.8 requirements.
 */
export const getCheckoutErrorMessage = (status, data) => {
    if (status === 422) {
        if (data?.inventory_errors) {
            return 'One or more items are currently unavailable at this branch.';
        }
        return 'Some items could not be validated. Please review the cart.';
    }
    
    if (status === 409) {
        return 'This checkout request changed unexpectedly. Please review the cart before trying again.';
    }

    if (status === 403) {
        return 'You do not have permission to complete this sale.';
    }

    return 'The sale could not be completed. Your cart is safe. Please try again.';
};

/**
 * Validates if the checkout state should be cleared.
 * In Story 4.8, it should only be cleared on explicit success.
 */
export const shouldClearCart = (success) => {
    return !!success;
};
