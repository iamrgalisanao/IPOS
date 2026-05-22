export interface DraftContext {
    tenantId: string | null;
    branchId: string | null;
    userId: string | null;
}

export interface CartItemDraft {
    id: any;
    product_id: any;
    display_name: string;
    sku: string;
    barcode: string;
    unit_of_measure?: string;
    quantity: number;
    unit_price: any;
    selling_price: any;
    tax_category_id: any;
    tax_type?: string;
    tax_rate?: any;
    is_inventory_tracked?: boolean | number;
    current_stock?: number;
    stock_available?: number;
    is_discountable?: boolean | number;
    [key: string]: any;
}

export interface PaymentRowDraft {
    id: any;
    payment_method_id: any;
    amount: any;
    amount_tendered: any;
    reference_number?: string;
}

export interface DraftEnvelope {
    schema_version: number;
    tenant_id: string;
    branch_id: string;
    user_id: string;
    client_request_uuid: string;
    cart_state: string;
    items: CartItemDraft[];
    estimated_totals: any;
    active_sale: {
        id: any;
        total: any;
    } | null;
    payment_rows: PaymentRowDraft[];
    payment_wizard_open: boolean;
    updated_at: string;
}

export function generateUUID(): string {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

export function getDraftKey({ tenantId, branchId, userId }: DraftContext): string {
    const tId = tenantId || 'no-tenant';
    const bId = branchId || 'no-branch';
    const uId = userId || 'anonymous';
    return `ipos:cart-draft:${tId}:${bId}:${uId}`;
}

export function sanitizeCartItem(item: any): CartItemDraft {
    return {
        id: item.id || item.product_id,
        product_id: item.product_id || item.id,
        display_name: item.display_name,
        sku: item.sku,
        barcode: item.barcode,
        unit_of_measure: item.unit_of_measure,
        quantity: item.quantity,
        unit_price: item.unit_price || item.selling_price,
        selling_price: item.selling_price || item.unit_price,
        tax_category_id: item.tax_category_id,
        tax_type: item.tax_type,
        tax_rate: item.tax_rate,
        is_inventory_tracked: item.is_inventory_tracked,
        current_stock: item.current_stock,
        stock_available: item.stock_available,
        is_discountable: item.is_discountable
    };
}

export function sanitizePaymentRow(row: any): PaymentRowDraft {
    return {
        id: row.id,
        payment_method_id: row.payment_method_id || '',
        amount: row.amount || '',
        amount_tendered: row.amount_tendered || '',
        reference_number: row.reference_number || ''
    };
}

export function buildDraftEnvelope({
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
}: {
    tenantId: string | null;
    branchId: string | null;
    userId: string | null;
    items: any[];
    totals: any;
    cartState: string;
    clientRequestUuid: string | null;
    activeSale: any;
    paymentRows: any[];
    paymentWizardOpen: boolean;
}): DraftEnvelope {
    return {
        schema_version: 1,
        tenant_id: tenantId || 'no-tenant',
        branch_id: branchId || 'no-branch',
        user_id: userId || 'anonymous',
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
}

export function saveDraft(context: DraftContext, draft: any): void {
    try {
        const key = getDraftKey(context);
        const envelope = buildDraftEnvelope({
            tenantId: context.tenantId,
            branchId: context.branchId,
            userId: context.userId,
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
}

export function loadDraft(context: DraftContext): DraftEnvelope | null {
    try {
        const key = getDraftKey(context);
        const data = window.localStorage.getItem(key);
        if (!data) return null;
        return JSON.parse(data);
    } catch (e) {
        console.error('Failed to parse POS draft from local storage', e);
        return null;
    }
}

export function restoreDraftIfSafe(context: DraftContext): { success: boolean; reason?: string; draft?: DraftEnvelope } {
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
}

export function clearDraft(context: DraftContext): void {
    try {
        const key = getDraftKey(context);
        window.localStorage.removeItem(key);
    } catch (e) {
        console.error('Failed to clear POS draft from local storage', e);
    }
}
