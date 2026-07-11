/**
 * Helper to determine the user-friendly error message based on backend response.
 * Standardizes messages according to Story 4.8 requirements.
 */
export const getCheckoutErrorMessage = (status, data) => {
    if (status === 401 || status === 419) {
        return 'Your POS session has expired. Reconnect or sign in again before continuing.';
    }

    if (status === 422) {
        if (data?.inventory_errors) {
            const firstError = data.inventory_errors[0];
            if (firstError?.product_name && firstError?.reason) {
                return `${firstError.product_name}: ${firstError.reason}`;
            }
            if (data?.message) {
                return data.message;
            }
            return 'One or more items are currently unavailable at this branch.';
        }
        return 'Some items could not be validated. Please review the cart.';
    }
    
    if (status === 409) {
        return 'This checkout request changed unexpectedly. Please review the cart before trying again.';
    }

    if (status === 403) {
        if (data?.code === 'TERMINAL_CONTEXT_INVALID') {
            return data?.message || 'This terminal is not linked to the active branch. Ask support to verify terminal setup.';
        }

        if (data?.code === 'TIMECARD_REQUIRED') {
            return data?.message || 'You must be clocked in before completing this sale.';
        }

        if (data?.message) {
            return data.message;
        }
        return 'You do not have permission to complete this sale.';
    }

    return 'The sale could not be completed. Your cart is safe. Please try again.';
};

export const getPosAccessIssue = (status, data = {}) => {
    if (status === 401 || status === 419) {
        return {
            code: 'SESSION_EXPIRED',
            tone: 'red',
            title: 'POS Session Needs Attention',
            message: 'Your POS session has expired. Reconnect or sign in again before continuing.',
            actionLabel: 'Sign In Again',
            action: 'login',
        };
    }

    if (status === 403 && data?.code === 'TERMINAL_CONTEXT_INVALID') {
        return {
            code: 'TERMINAL_CONTEXT_INVALID',
            tone: 'red',
            title: 'Terminal Context Not Verified',
            message: data?.message || 'This terminal is not linked to the active branch. Ask support to verify terminal setup.',
            actionLabel: 'Retry Context',
            action: 'retry',
        };
    }

    if (status === 403 && data?.code === 'TIMECARD_REQUIRED') {
        return {
            code: 'TIMECARD_REQUIRED',
            tone: 'amber',
            title: 'Clock-In Required',
            message: data?.message || 'You must be clocked in before completing this sale.',
            actionLabel: 'Open Shift',
            action: 'open_shift',
        };
    }

    return null;
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
