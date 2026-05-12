export function useTransactionStore() {
    const generateUUID = () => {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    };

    const getDraftKey = ({ tenantId, branchId, userId }) => {
        const tId = tenantId || 'no-tenant'; // Fallback just in case, though tenant should always exist
        const bId = branchId || 'no-branch';
        const uId = userId || 'anonymous';
        return `ipos:cart-draft:${tId}:${bId}:${uId}`;
    };

    const sanitizeCartItem = (item) => {
        // Only persist safe fields, explicitly omitting cost_price, etc.
        return {
            id: item.id || item.product_id, // Preserve ID for React keys
            product_id: item.product_id || item.id,
            display_name: item.display_name,
            sku: item.sku,
            barcode: item.barcode,
            unit_of_measure: item.unit_of_measure,
            quantity: item.quantity,
            unit_price: item.unit_price || item.selling_price,
            selling_price: item.selling_price || item.unit_price, // Needed for cart total
            tax_category_id: item.tax_category_id,
            tax_type: item.tax_type,
            tax_rate: item.tax_rate,
            is_inventory_tracked: item.is_inventory_tracked,
            current_stock: item.current_stock,
            stock_available: item.stock_available,
            is_discountable: item.is_discountable
        };
    };

    const sanitizePaymentRow = (row) => {
        return {
            id: row.id,
            payment_method_id: row.payment_method_id || '',
            amount: row.amount || '',
            amount_tendered: row.amount_tendered || '',
            reference_number: row.reference_number || ''
        };
    };

    const buildDraftEnvelope = ({
        tenantId,
        branchId,
        userId,
        items,
        totals,
        cartState,
        clientRequestUuid,
        activeSale,
        paymentRows,
        paymentWizardOpen,
    }) => {
        return {
            schema_version: 1,
            tenant_id: tenantId,
            branch_id: branchId,
            user_id: userId,
            client_request_uuid: clientRequestUuid || generateUUID(),
            cart_state: cartState,
            items: items.map(sanitizeCartItem),
            estimated_totals: totals,
            active_sale: activeSale
                ? {
                    id: activeSale.id,
                    total: activeSale.total,
                }
                : null,
            payment_rows: (paymentRows || []).map(sanitizePaymentRow),
            payment_wizard_open: !!paymentWizardOpen,
            updated_at: new Date().toISOString()
        };
    };

    const saveDraft = (context, draft) => {
        try {
            const key = getDraftKey(context);
            const envelope = buildDraftEnvelope({
                ...context,
                items: draft.items || [],
                totals: draft.totals || {},
                cartState: draft.cartState || 'draft',
                clientRequestUuid: draft.clientRequestUuid,
                activeSale: draft.activeSale || null,
                paymentRows: draft.paymentRows || [],
                paymentWizardOpen: draft.paymentWizardOpen || false,
            });
            window.localStorage.setItem(key, JSON.stringify(envelope));
        } catch (e) {
            console.error('Failed to save POS draft to local storage', e);
        }
    };

    const loadDraft = (context) => {
        try {
            const key = getDraftKey(context);
            const data = window.localStorage.getItem(key);
            if (!data) return null;
            return JSON.parse(data);
        } catch (e) {
            console.error('Failed to parse POS draft from local storage', e);
            return null; // Invalid JSON
        }
    };

    const restoreDraftIfSafe = (context) => {
        const draft = loadDraft(context);
        if (!draft) return { success: false, reason: 'no-draft' };

        // 1. Validate Schema Version
        if (draft.schema_version !== 1) {
            clearDraft(context);
            return { success: false, reason: 'unsupported-schema' };
        }

        // 2. Validate Tenant
        if (draft.tenant_id !== context.tenantId) {
            return { success: false, reason: 'tenant-mismatch' };
        }

        // 3. Validate Branch (if context has branch)
        if (context.branchId && draft.branch_id !== context.branchId) {
            return { success: false, reason: 'branch-mismatch' };
        }

        // 4. Validate User (if context has user)
        if (context.userId && draft.user_id !== context.userId) {
            return { success: false, reason: 'user-mismatch' };
        }

        return { success: true, draft };
    };

    const clearDraft = (context) => {
        try {
            const key = getDraftKey(context);
            window.localStorage.removeItem(key);
        } catch (e) {
            console.error('Failed to clear POS draft from local storage', e);
        }
    };

    return {
        generateUUID,
        getDraftKey,
        sanitizeCartItem,
        sanitizePaymentRow,
        buildDraftEnvelope,
        saveDraft,
        loadDraft,
        restoreDraftIfSafe,
        clearDraft
    };
}
