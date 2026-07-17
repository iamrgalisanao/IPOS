export const OFFLINE_SHIFT_AUTHORITY_POLICY_VERSION = 'offline-shift-authority-v1';
export const DEFAULT_SHIFT_AUTHORITY_TTL_MINUTES = 720;

type ShiftLike = {
    id?: string | null;
    status?: string | null;
    opened_at?: string | null;
    created_at?: string | null;
    started_at?: string | null;
};

const addMinutes = (isoTimestamp: string, minutes: number): string => {
    const date = new Date(isoTimestamp);
    date.setMinutes(date.getMinutes() + minutes);

    return date.toISOString();
};

export const buildShiftAuthoritySnapshot = (
    shift: ShiftLike | null | undefined,
    nowIso: string = new Date().toISOString(),
) => {
    const openedAt = shift?.opened_at || shift?.started_at || shift?.created_at || nowIso;
    const shiftStatus = String(shift?.status || 'open').toLowerCase();

    return {
        cashier_shift_id: shift?.id || null,
        shift_authorization_id: shift?.id ? `shift-auth-${shift.id}` : null,
        shift_authorization_policy_version: OFFLINE_SHIFT_AUTHORITY_POLICY_VERSION,
        shift_authorization_issued_at: nowIso,
        authorized_offline_until: addMinutes(nowIso, DEFAULT_SHIFT_AUTHORITY_TTL_MINUTES),
        shift_status_snapshot: shiftStatus,
        shift_opened_at: openedAt,
        shift_cached_at: nowIso,
    };
};

export const validateShiftAuthoritySnapshot = (
    snapshot: ReturnType<typeof buildShiftAuthoritySnapshot>,
    now: Date = new Date(),
): { allowed: boolean; reason: string | null } => {
    if (!snapshot.cashier_shift_id || !snapshot.shift_authorization_id) {
        return { allowed: false, reason: 'missing_shift_authority' };
    }

    if (snapshot.shift_status_snapshot !== 'open') {
        return { allowed: false, reason: 'shift_not_open' };
    }

    if (new Date(snapshot.authorized_offline_until).getTime() < now.getTime()) {
        return { allowed: false, reason: 'shift_authority_expired' };
    }

    return { allowed: true, reason: null };
};
