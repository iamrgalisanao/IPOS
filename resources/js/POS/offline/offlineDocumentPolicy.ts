const stableStringify = (value: unknown): string => {
    if (Array.isArray(value)) {
        return `[${value.map(stableStringify).join(',')}]`;
    }

    if (value && typeof value === 'object') {
        return `{${Object.keys(value as Record<string, unknown>).sort().map((key) => (
            `${JSON.stringify(key)}:${stableStringify((value as Record<string, unknown>)[key])}`
        )).join(',')}}`;
    }

    return JSON.stringify(value);
};

const hashString = (input: string): string => {
    let hash = 0;

    for (let index = 0; index < input.length; index += 1) {
        hash = ((hash << 5) - hash) + input.charCodeAt(index);
        hash |= 0;
    }

    return Math.abs(hash).toString(16).padStart(8, '0').repeat(8).slice(0, 64);
};

export const buildOfflineAcknowledgmentPrintEvent = (payload: Record<string, unknown>) => ({
    event_type: 'offline_acknowledgment_printed',
    event_version: 1,
    event_uuid: globalThis.crypto?.randomUUID?.() || `offline-print-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    occurred_at: new Date().toISOString(),
    document_hash: hashString(stableStringify(payload)),
    document_label: 'OFFLINE ACKNOWLEDGMENT',
    payload,
});
