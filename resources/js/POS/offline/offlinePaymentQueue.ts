type PendingSalePayment = {
    id: string;
    sale_id: string;
    payload: {
        payments: Array<{
            payment_method_id: string;
            amount: string;
            reference_number: string | null;
        }>;
    };
    rows: any[];
    context: {
        tenant_id?: string | null;
        branch_id?: string | null;
        terminal_id?: string | null;
    };
    status: 'pending' | 'syncing' | 'failed' | 'conflict' | 'legacy_conflict';
    created_at: string;
    updated_at: string;
    last_error?: string | null;
    legacy_quarantine?: {
        quarantined_at: string;
        quarantine_reason: string;
        original_status: string;
        source_record_hash: string;
    } | null;
};

const STORAGE_KEY = 'ipos_pending_server_sale_payments_v1';
const listeners = new Set<() => void>();

const readQueue = (): PendingSalePayment[] => {
    if (typeof localStorage === 'undefined') return [];

    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];

        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
        console.error('Failed to read offline payment queue:', err);
        return [];
    }
};

const writeQueue = (queue: PendingSalePayment[]) => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(queue));
    listeners.forEach((listener) => listener());
};

class OfflinePaymentQueue {
    subscribe(listener: () => void): () => void {
        listeners.add(listener);
        return () => listeners.delete(listener);
    }

    getPendingPayments(): PendingSalePayment[] {
        this.quarantineLegacyRecords();

        return [];
    }

    getAllPayments(): PendingSalePayment[] {
        return readQueue();
    }

    getStatusSummary() {
        const records = this.quarantineLegacyRecords();

        return records.reduce((summary, payment) => {
            const status = payment.status === 'legacy_conflict' ? 'conflict' : payment.status;
            summary[status] = (summary[status] || 0) + 1;
            summary.total += 1;
            return summary;
        }, {
            pending: 0,
            syncing: 0,
            failed: 0,
            conflict: 0,
            total: 0,
        });
    }

    queuePayment(input: Omit<PendingSalePayment, 'id' | 'status' | 'created_at' | 'updated_at'>): PendingSalePayment {
        const now = new Date().toISOString();
        const queue = readQueue().filter((payment) => payment.sale_id !== input.sale_id);
        const record: PendingSalePayment = {
            ...input,
            id: this.randomId(),
            status: 'legacy_conflict',
            created_at: now,
            updated_at: now,
            last_error: 'Offline payment capture for server-created sales is disabled. Use the canonical offline sale capture queue or reconnect before recording payment.',
            legacy_quarantine: {
                quarantined_at: now,
                quarantine_reason: this.quarantineReason(input as PendingSalePayment),
                original_status: 'new',
                source_record_hash: this.hashRecord(input),
            },
        };

        writeQueue([...queue, record]);
        return record;
    }

    async processQueue(): Promise<void> {
        this.quarantineLegacyRecords();
    }

    private quarantineLegacyRecords(): PendingSalePayment[] {
        const now = new Date().toISOString();
        const queue = readQueue();
        let changed = false;

        const quarantined = queue.map((payment) => {
            if (!['pending', 'syncing', 'failed'].includes(payment.status)) {
                return payment;
            }

            changed = true;

            return {
                ...payment,
                status: 'legacy_conflict' as const,
                updated_at: now,
                last_error: 'Offline payment capture for server-created sales is disabled. This legacy record is quarantined and will not be posted automatically.',
                legacy_quarantine: {
                    quarantined_at: now,
                    quarantine_reason: this.quarantineReason(payment),
                    original_status: payment.status,
                    source_record_hash: this.hashRecord(payment),
                },
            };
        });

        if (changed) {
            writeQueue(quarantined);
        }

        return quarantined;
    }

    private quarantineReason(payment: PendingSalePayment): string {
        return typeof payment.sale_id === 'string' && payment.sale_id.startsWith('offline-draft-')
            ? 'offline_draft_payment_legacy_queue'
            : 'offline_server_sale_payment_queue_disabled';
    }

    private randomId(): string {
        return globalThis.crypto?.randomUUID?.() || `legacy-payment-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }

    private hashRecord(record: unknown): string {
        const input = this.stableStringify(record);
        let hash = 0;

        for (let i = 0; i < input.length; i += 1) {
            hash = ((hash << 5) - hash) + input.charCodeAt(i);
            hash |= 0;
        }

        return Math.abs(hash).toString(16).padStart(8, '0');
    }

    private stableStringify(value: unknown): string {
        if (Array.isArray(value)) {
            return `[${value.map((item) => this.stableStringify(item)).join(',')}]`;
        }

        if (value && typeof value === 'object') {
            return `{${Object.keys(value as Record<string, unknown>).sort().map((key) => (
                `${JSON.stringify(key)}:${this.stableStringify((value as Record<string, unknown>)[key])}`
            )).join(',')}}`;
        }

        return JSON.stringify(value);
    }
}

export const offlinePaymentQueue = new OfflinePaymentQueue();

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        offlinePaymentQueue.processQueue().catch((err) => {
            console.error('Failed to quarantine legacy offline payment records:', err);
        });
    });
}
