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

export const createUncertainCheckoutError = (message = 'The sale status is uncertain. Please check status before retrying.') => {
    const error = new Error(message);
    error.kind = 'status_uncertain';
    return error;
};

export const isUncertainCheckoutError = (error) => {
    return error?.kind === 'status_uncertain' || error?.name === 'AbortError';
};

export const getGuardianPresentation = (kind) => {
    const variants = {
        restored: {
            tone: 'blue',
            title: 'Cart Restored',
            announcement: 'Cart Restored',
        },
        checking: {
            tone: 'blue',
            title: 'Checking Status',
            announcement: 'Checking Status',
        },
        uncertain: {
            tone: 'amber',
            title: 'Status Uncertain',
            announcement: 'Status Uncertain',
        },
        retry_available: {
            tone: 'amber',
            title: 'Retry Available',
            announcement: 'Retry Available',
        },
        confirmed: {
            tone: 'emerald',
            title: 'Sale Confirmed',
            announcement: 'Sale Confirmed',
        },
        offline_captured: {
            tone: 'amber',
            title: 'Offline Transaction Captured',
            announcement: 'Offline Transaction Captured',
        },
        failed: {
            tone: 'red',
            title: 'Submission Failed',
            announcement: 'Submission Failed',
        },
    };

    return variants[kind] || variants.failed;
};

/**
 * Validates if the checkout state should be cleared.
 * In Story 4.8, it should only be cleared on explicit success.
 */
export const shouldClearCart = (success) => {
    return !!success;
};
