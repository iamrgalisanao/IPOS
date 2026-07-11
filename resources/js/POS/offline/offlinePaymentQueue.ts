import axios from 'axios';

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
    status: 'pending' | 'syncing' | 'failed' | 'conflict';
    created_at: string;
    updated_at: string;
    last_error?: string | null;
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
        return readQueue().filter((payment) => payment.status === 'pending' || payment.status === 'failed');
    }

    getAllPayments(): PendingSalePayment[] {
        return readQueue();
    }

    getStatusSummary() {
        const records = readQueue();

        return records.reduce((summary, payment) => {
            summary[payment.status] = (summary[payment.status] || 0) + 1;
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
            id: crypto.randomUUID(),
            status: 'pending',
            created_at: now,
            updated_at: now,
            last_error: null,
        };

        writeQueue([...queue, record]);
        return record;
    }

    async processQueue(): Promise<void> {
        const queue = readQueue();
        const pending = queue.filter((payment) => payment.status === 'pending' || payment.status === 'failed');

        for (const payment of pending) {
            if (this.isInvalidOfflineDraftPayment(payment)) {
                this.updatePayment(payment.id, {
                    status: 'conflict',
                    updated_at: new Date().toISOString(),
                    last_error: 'Offline draft payments cannot sync through the server payment endpoint. Complete the transaction as an offline sale capture.',
                });
                continue;
            }

            await this.syncPayment(payment);
        }
    }

    private isInvalidOfflineDraftPayment(payment: PendingSalePayment): boolean {
        return typeof payment.sale_id === 'string' && payment.sale_id.startsWith('offline-draft-');
    }

    private async syncPayment(payment: PendingSalePayment): Promise<void> {
        this.updatePayment(payment.id, { status: 'syncing', updated_at: new Date().toISOString(), last_error: null });

        try {
            await axios.post(`/pos/sales/${payment.sale_id}/payments/split`, payment.payload, {
                headers: this.buildHeaders(payment),
            });

            writeQueue(readQueue().filter((queued) => queued.id !== payment.id));
        } catch (err: any) {
            const message = err.response?.data?.message
                || err.response?.data?.error
                || err.message
                || 'Payment sync failed. Reconnect and retry.';

            this.updatePayment(payment.id, {
                status: 'failed',
                updated_at: new Date().toISOString(),
                last_error: message,
            });

            throw err;
        }
    }

    private updatePayment(id: string, updates: Partial<PendingSalePayment>) {
        writeQueue(readQueue().map((payment) => (
            payment.id === id ? { ...payment, ...updates } : payment
        )));
    }

    private buildHeaders(payment: PendingSalePayment): Record<string, string> {
        const csrfToken = typeof document !== 'undefined'
            ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            : null;

        return {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            ...(payment.context.tenant_id ? { 'X-Tenant-ID': payment.context.tenant_id } : {}),
            ...(payment.context.branch_id ? { 'X-Branch-ID': payment.context.branch_id } : {}),
            ...(payment.context.terminal_id ? { 'X-Terminal-ID': payment.context.terminal_id } : {}),
        };
    }
}

export const offlinePaymentQueue = new OfflinePaymentQueue();

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        offlinePaymentQueue.processQueue().catch((err) => {
            console.error('Failed to sync queued server-sale payments:', err);
        });
    });
}
