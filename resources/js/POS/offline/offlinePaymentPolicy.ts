type PaymentMethodLike = {
    id?: string | null;
    type?: string | null;
    code?: string | null;
    name?: string | null;
    updated_at?: string | null;
    configured_at?: string | null;
    version?: string | number | null;
    tenant_id?: string | null;
    branch_id?: string | null;
};

const normalize = (value: unknown): string | null => {
    if (value === undefined || value === null) return null;

    return String(value).trim();
};

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

export const isCashMethodSnapshot = (method: PaymentMethodLike | null | undefined): boolean => {
    const code = String(method?.code || '').toLowerCase();
    const type = String(method?.type || '').toLowerCase();
    const name = String(method?.name || '').toLowerCase();

    return code === 'cash' || type === 'cash' || name.includes('cash');
};

export const buildPaymentMethodSnapshot = (method: PaymentMethodLike) => {
    const configuredAt = normalize(method.configured_at || method.updated_at) || null;
    const version = normalize(method.version) || configuredAt || 'payment-method-v1';
    const payload = {
        payment_method_id: normalize(method.id),
        payment_method_type: normalize(method.type || method.code),
        payment_method_name: normalize(method.name || method.code),
        payment_method_version: version,
        configured_at: configuredAt,
        tenant_id: normalize(method.tenant_id),
        branch_scope: normalize(method.branch_id),
    };

    return {
        payment_method_id: payload.payment_method_id,
        payment_method_type_snapshot: payload.payment_method_type,
        payment_method_name_snapshot: payload.payment_method_name,
        payment_method_version: payload.payment_method_version,
        payment_method_configured_at: payload.configured_at,
        payment_method_configuration_hash: hashString(stableStringify(payload)),
    };
};
