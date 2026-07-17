export type OfflineActionDecision = {
    allowed: boolean;
    reason: string | null;
    action: 'allow' | 'block' | 'review_required' | 'lock_terminal' | 'logout_cashier' | 'switch_cashier';
};

type OfflineActionContext = {
    is_offline?: boolean;
    unresolved_cash_records?: number;
};

const ONLINE_ONLY_ACTIONS = new Set([
    'record_server_sale_payment',
    'apply_statutory_discount',
    'issue_manager_approval',
    'print_official_receipt',
    'void_sale',
    'refund_sale',
]);

export const decideOfflineAction = (
    action: string,
    context: OfflineActionContext = {},
): OfflineActionDecision => {
    const isOffline = context.is_offline !== false;
    const unresolvedCashRecords = Number(context.unresolved_cash_records || 0);

    if (action === 'lock_terminal') {
        return { allowed: true, reason: null, action: 'lock_terminal' };
    }

    if (['logout_cashier', 'switch_cashier', 'close_shift'].includes(action) && unresolvedCashRecords > 0) {
        return {
            allowed: false,
            reason: 'unresolved_offline_cash_records',
            action: action === 'switch_cashier' ? 'switch_cashier' : 'logout_cashier',
        };
    }

    if (action === 'capture_cash_sale') {
        return { allowed: true, reason: null, action: 'allow' };
    }

    if (isOffline && ONLINE_ONLY_ACTIONS.has(action)) {
        return { allowed: false, reason: `${action}_requires_online_server`, action: 'block' };
    }

    return { allowed: !isOffline, reason: isOffline ? 'unknown_offline_action' : null, action: isOffline ? 'block' : 'allow' };
};
