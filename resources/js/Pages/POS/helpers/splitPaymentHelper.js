/**
 * Helper functions for split payment processing
 */

/**
 * Checks if a payment method is cash
 * 
 * @param {Object} method 
 * @returns {Boolean}
 */
export const isCashPayment = (method) => {
    return method && (method.code === 'cash' || method.type === 'cash');
};

/**
 * Calculates payment totals and remaining balance
 * 
 * @param {Array} rows 
 * @param {Number} saleTotal 
 * @returns {Object}
 */
export const calculatePaymentTotals = (rows, saleTotal) => {
    const totalPaid = rows.reduce((sum, row) => sum + (Number(row.amount) || 0), 0);
    const remainingBalance = Math.max(0, Number(saleTotal) - totalPaid);
    
    return {
        totalPaid: Number(totalPaid.toFixed(4)),
        remainingBalance: Number(remainingBalance.toFixed(4)),
        isExact: Math.abs(totalPaid - Number(saleTotal)) < 0.0001,
        isOverpaid: totalPaid > Number(saleTotal) + 0.0001,
        isUnderpaid: totalPaid < Number(saleTotal) - 0.0001
    };
};

/**
 * Calculates visible payment progress while a cashier is still splitting cash.
 *
 * For cash rows, a tendered amount below the assigned amount is treated as the
 * paid portion so the UI can offer the remaining balance as another method.
 */
export const calculatePaymentProgress = (rows, paymentMethods, saleTotal) => {
    const totalPaid = rows.reduce((sum, row) => {
        const method = paymentMethods.find(m => m.id === row.payment_method_id);
        const amount = Number(row.amount) || 0;
        const tendered = Number(row.amount_tendered) || 0;

        if (isCashPayment(method) && tendered > 0 && tendered < amount) {
            return sum + tendered;
        }

        return sum + amount;
    }, 0);
    const remainingBalance = Math.max(0, Number(saleTotal) - totalPaid);

    return {
        totalPaid: Number(totalPaid.toFixed(4)),
        remainingBalance: Number(remainingBalance.toFixed(4)),
    };
};

/**
 * Calculates cash change due for a row
 * 
 * @param {Object} row 
 * @returns {Number}
 */
export const calculateCashChange = (row) => {
    const amount = Number(row.amount) || 0;
    const tendered = Number(row.amount_tendered) || 0;
    return Math.max(0, tendered - amount);
};

/**
 * Validates payment rows and reference requirements
 * 
 * @param {Array} rows 
 * @param {Array} paymentMethods 
 * @param {Number} saleTotal 
 * @returns {Object} { isValid: boolean, errors: Array }
 */
export const validatePaymentRows = (rows, paymentMethods, saleTotal, isOffline = false) => {
    const errors = [];
    const totals = calculatePaymentTotals(rows, saleTotal);

    if (rows.length === 0) {
        errors.push("At least one payment row is required.");
    }

    rows.forEach((row, index) => {
        const method = paymentMethods.find(m => m.id === row.payment_method_id);
        
        if (!row.payment_method_id) {
            errors.push(`Row ${index + 1}: Payment method is required.`);
        }

        const amount = Number(row.amount) || 0;
        if (amount <= 0) {
            errors.push(`Row ${index + 1}: Amount must be positive.`);
        }

        // Cash Tendered Validation
        if (isCashPayment(method)) {
            const tendered = Number(row.amount_tendered) || 0;
            if (tendered < amount && tendered > 0) {
                errors.push(`Row ${index + 1}: Cash tendered is less than the required amount.`);
            } else if (tendered < amount) {
                errors.push(`Row ${index + 1}: Please enter cash tendered.`);
            }
        }

        if (method) {
            // Reference Number Validation
            const refRequired = method.requires_reference || method.reference_required;
            if (refRequired) {
                const ref = (row.reference_number || '').trim();
                if (!ref) {
                    errors.push(`Row ${index + 1}: Reference number is required for ${method.name}.`);
                }
            }

            // Offline Limit Validation is now aggregated per-method across all rows (see below)
        }
    });

    if (isOffline) {
        const totalsByMethod = {};
        rows.forEach((row) => {
            const methodId = row.payment_method_id;
            const amount = Number(row.amount) || 0;
            if (methodId && amount > 0) {
                totalsByMethod[methodId] = (totalsByMethod[methodId] || 0) + amount;
            }
        });

        Object.entries(totalsByMethod).forEach(([methodId, totalAmount]) => {
            const method = paymentMethods.find((m) => m.id === methodId);
            if (method && method.offline_max_limit_centavos !== null && method.offline_max_limit_centavos !== undefined) {
                const totalCentavos = Math.round(totalAmount * 100);
                if (totalCentavos > method.offline_max_limit_centavos) {
                    const limitFormatted = (method.offline_max_limit_centavos / 100).toFixed(2);
                    errors.push(`${method.name} offline limit is ₱${limitFormatted} per offline sale. Reduce the amount or reconnect to continue.`);
                }
            }
        });
    }

    if (totals.isUnderpaid) {
        errors.push("Payment total is less than the sale total.");
    }

    if (totals.isOverpaid) {
        errors.push("Payment total exceeds the sale total.");
    }

    return {
        isValid: errors.length === 0,
        errors
    };
};

/**
 * Checks if a payment method requires a reference number
 * 
 * @param {Object} paymentMethod 
 * @returns {Boolean}
 */
export const requiresReference = (paymentMethod) => {
    return !!(paymentMethod && paymentMethod.reference_required);
};

/**
 * Sanitizes reference number
 * 
 * @param {String} reference 
 * @returns {String|null}
 */
export const sanitizeReference = (reference) => {
    if (!reference) return null;
    const trimmed = reference.trim();
    return trimmed === '' ? null : trimmed;
};

/**
 * Builds the payload for the split payment API
 * 
 * @param {Array} rows 
 * @returns {Object}
 */
export const buildSplitPaymentPayload = (rows) => {
    return {
        payments: rows.map(row => ({
            payment_method_id: row.payment_method_id,
            amount: Number(row.amount).toFixed(4), // Exact required amount, not tendered
            reference_number: sanitizeReference(row.reference_number)
        }))
    };
};
