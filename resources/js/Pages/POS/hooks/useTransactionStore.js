import * as cartDraftStore from '../../../POS/offline/cartDraftStore.ts';

export function useTransactionStore() {
    return {
        generateUUID: cartDraftStore.generateUUID,
        getDraftKey: cartDraftStore.getDraftKey,
        sanitizeCartItem: cartDraftStore.sanitizeCartItem,
        sanitizePaymentRow: cartDraftStore.sanitizePaymentRow,
        buildDraftEnvelope: cartDraftStore.buildDraftEnvelope,
        saveDraft: cartDraftStore.saveDraft,
        loadDraft: cartDraftStore.loadDraft,
        restoreDraftIfSafe: cartDraftStore.restoreDraftIfSafe,
        clearDraft: cartDraftStore.clearDraft
    };
}

