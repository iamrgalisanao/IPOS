import axios from 'axios';
import { offlineSalesQueue } from './offlineSalesQueue.ts';
import type { OfflineTransactionEnvelope, OfflineSyncStatus } from './offlineSalesQueue.ts';
import { isOffline } from './offlineGuards.ts';

export class OfflineSyncManager {

    /**
     * Batch pending offline transactions and send them to the server.
     * Only runs when online.
     */
    public async processQueue(options: { force?: boolean } = {}): Promise<void> {
        if (isOffline()) {
            return;
        }

        const pendingTransactions = await offlineSalesQueue.getQueuedTransactions();
        if (pendingTransactions.length === 0) {
            return;
        }

        const retryableTransactions = await this.quarantineReviewOnlyFailures(pendingTransactions);
        if (retryableTransactions.length === 0) {
            return;
        }

        // Apply exponential backoff filter
        const now = new Date();
        const eligible = retryableTransactions.filter(env => {
            if (options.force) return true;
            if (env.status !== 'failed') return true;
            if (!env.last_sync_attempt_at) return true;

            const attempts = env.payload?.sync_attempt_count || 0;
            if (attempts === 0) return true;

            // Backoff: 2^attempts * 5 seconds (e.g. 10s, 20s, 40s, 80s, 160s, max 5 minutes)
            const backoffSeconds = Math.min(300, Math.pow(2, attempts) * 5);
            const nextAllowedTime = new Date(new Date(env.last_sync_attempt_at).getTime() + backoffSeconds * 1000);

            return now >= nextAllowedTime;
        });

        if (eligible.length === 0) {
            return;
        }

        await offlineSalesQueue.recordSyncAttempt(new Date().toISOString());

        // Group by batch_reference
        const groupedByBatch = this.groupByBatch(eligible);

        for (const [batchRef, envelopes] of Object.entries(groupedByBatch)) {
            await this.syncBatch(batchRef, envelopes);
        }
    }

    private async quarantineReviewOnlyFailures(envelopes: OfflineTransactionEnvelope[]): Promise<OfflineTransactionEnvelope[]> {
        const retryable: OfflineTransactionEnvelope[] = [];

        for (const env of envelopes) {
            if (this.isReviewOnlyFailure(env)) {
                await offlineSalesQueue.updateTransactionStatus(
                    env.id,
                    'conflict',
                    env.error_message || 'Offline sync record requires review. It was rejected by server validation.'
                );
            } else {
                retryable.push(env);
            }
        }

        return retryable;
    }

    private isReviewOnlyFailure(env: OfflineTransactionEnvelope): boolean {
        if (env.status !== 'failed') {
            return false;
        }

        const message = String(env.error_message || env.payload?.last_error || '').toLowerCase();
        const reviewTokens = [
            '422',
            'unprocessable',
            'validation',
            'rejected',
            'requires review',
            'sync failed. check validation details',
            'offline sync batch rejected',
            'sequence_out_of_order',
            'hash_chain_broken',
            'prefix_mismatch',
        ];

        return reviewTokens.some((token) => message.includes(token));
    }

    private groupByBatch(envelopes: OfflineTransactionEnvelope[]): Record<string, OfflineTransactionEnvelope[]> {
        return envelopes.reduce((acc, env) => {
            acc[env.batch_reference] = acc[env.batch_reference] || [];
            acc[env.batch_reference].push(env);
            return acc;
        }, {} as Record<string, OfflineTransactionEnvelope[]>);
    }

    private async syncBatch(batchRef: string, envelopes: OfflineTransactionEnvelope[]): Promise<void> {
        try {
            // Update local status to syncing
            for (const env of envelopes) {
                await offlineSalesQueue.updateTransactionStatus(env.id, 'syncing');
            }

            // Prepare the payload according to backend validation rules (SyncBatchRequest)
            const payload = {
                batch_reference: batchRef,
                imports: envelopes.map(env => ({
                    offline_sequence_number: env.offline_sequence,
                    submitted_at: env.payload.submitted_at || env.created_at,
                    items: env.payload.items,
                    client_subtotal: env.payload.client_subtotal || env.client_totals.subtotal,
                    client_tax_total: env.payload.client_tax_total || env.client_totals.tax,
                    client_total: env.payload.client_total || env.client_totals.total,

                    // 25 expanded audit metadata fields
                    tenant_id: env.payload.tenant_id,
                    branch_id: env.payload.branch_id,
                    terminal_id: env.payload.terminal_id,
                    device_id: env.payload.device_id,
                    cashier_shift_id: env.payload.cashier_shift_id,
                    timecard_id: env.payload.timecard_id,
                    local_transaction_reference: env.payload.local_transaction_reference,
                    local_receipt_number: env.payload.local_receipt_number,
                    business_date: env.payload.business_date,
                    terminal_timestamp: env.payload.terminal_timestamp,
                    timezone: env.payload.timezone,
                    sales_machine_profile_id: env.payload.sales_machine_profile_id,
                    config_snapshot_hash: env.payload.config_snapshot_hash,
                    layout_version_hash: env.payload.layout_version_hash,
                    catalog_version_hash: env.payload.catalog_version_hash,
                    tax_configuration_version_hash: env.payload.tax_configuration_version_hash,
                    discount_rules_version_hash: env.payload.discount_rules_version_hash,
                    payment_methods_version_hash: env.payload.payment_methods_version_hash,
                    terminal_policy_version_hash: env.payload.terminal_policy_version_hash,
                    printer_profile_version_hash: env.payload.printer_profile_version_hash,
                    config_snapshot: env.payload.config_snapshot,
                    cart_snapshot: env.payload.cart_snapshot,
                    payment_method: env.payload.payment_method,
                    payments: env.payload.payments,
                    gross_amount_centavos: env.payload.gross_amount_centavos,
                    discount_total_centavos: env.payload.discount_total_centavos,
                    taxable_amount_centavos: env.payload.taxable_amount_centavos,
                    tax_amount_centavos: env.payload.tax_amount_centavos,
                    net_amount_centavos: env.payload.net_amount_centavos,
                    payload_hash: env.payload_hash,
                    sync_status: 'pending',
                    sync_attempt_count: env.payload.sync_attempt_count || 0,
                    last_sync_attempt_at: env.payload.last_sync_attempt_at,

                    // Hashing chain properties
                    previous_hash: env.previous_hash,
                    row_hash: env.row_hash,
                }))
            };

            const response = await axios.post('/pos/offline-sync', payload, {
                headers: this.buildContextHeaders(envelopes),
            });

            if (response.status === 202 || response.status === 200) {
                const results = response.data.imports || [];
                for (const env of envelopes) {
                    const result = results.find((r: any) => r.offline_sequence_number === env.offline_sequence);
                    if (result) {
                        const newStatus = this.mapServerStatus(result.status);
                        await offlineSalesQueue.updateTransactionStatus(env.id, newStatus, result.reason || result.rejection_reason || result.conflict_notes || undefined);
                    } else {
                        await offlineSalesQueue.updateTransactionStatus(env.id, 'synced');
                    }
                }

                await offlineSalesQueue.recordSyncSuccess(new Date().toISOString());
            }
        } catch (error: any) {
            let newStatus: OfflineSyncStatus = 'failed';
            let errorMessage = error.message;

            if (error.response) {
                const status = error.response.status;

                if (status === 422) {
                    newStatus = 'conflict';
                    errorMessage = this.extractServerErrorMessage(error.response.data)
                        || 'Offline sync record requires review. It was rejected by server validation.';
                }
                else if (status === 401 || status === 403) {
                    newStatus = 'failed';
                    errorMessage = this.extractServerErrorMessage(error.response.data) || 'Authentication failed. Please log in again.';
                }
                else if (status === 409) {
                    newStatus = 'conflict';
                    errorMessage = this.extractServerErrorMessage(error.response.data) || 'Server recalculation conflict.';
                }
                else {
                    errorMessage = this.extractServerErrorMessage(error.response.data) || errorMessage;
                }
            } else {
                errorMessage = 'Sync failed. Transactions remain safely queued on this terminal. Reconnect and retry synchronization.';
            }

            const logPayload = {
                status: error.response?.status,
                response: error.response?.data,
                message: error.message,
                batch_reference: batchRef,
                offline_sequences: envelopes.map((env) => env.offline_sequence),
                local_status: newStatus,
            };

            if (newStatus === 'conflict') {
                console.warn('Batch sync requires review:', logPayload);
            } else {
                console.error('Batch sync failed:', logPayload);
            }

            // Apply the failure status
            for (const env of envelopes) {
                try {
                    await offlineSalesQueue.updateTransactionStatus(env.id, newStatus, errorMessage);
                } catch (updateError) {
                    console.error('Failed to update offline sync status after sync error:', updateError);
                }
            }
        }
    }

    private mapServerStatus(serverStatus: string): OfflineSyncStatus {
        switch (serverStatus) {
            case 'pending':
            case 'server_verified':
            case 'synced':
                return 'synced';
            case 'duplicate':
                return 'synced';
            case 'rejected':
                return 'conflict';
            case 'conflict':
                return 'conflict';
            case 'accepted_with_warning':
                return 'accepted_with_warning';
            default:
                return 'failed';
        }
    }

    public async retryFailed(): Promise<void> {
        if (isOffline()) {
            return;
        }

        await this.processQueue({ force: true });
    }

    private buildContextHeaders(envelopes: OfflineTransactionEnvelope[]): Record<string, string> {
        const payload = envelopes.find((env) => env.payload)?.payload || {};
        const headers: Record<string, string> = {
            'Accept': 'application/json',
        };
        const csrfToken = typeof document !== 'undefined'
            ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            : null;

        if (csrfToken) {
            headers['X-CSRF-TOKEN'] = csrfToken;
        }

        if (payload.tenant_id) {
            headers['X-Tenant-ID'] = payload.tenant_id;
        }

        if (payload.branch_id) {
            headers['X-Branch-ID'] = payload.branch_id;
        }

        if (payload.terminal_id || payload.sales_machine_profile_id) {
            headers['X-Terminal-ID'] = payload.terminal_id || payload.sales_machine_profile_id;
        }

        return headers;
    }

    private extractServerErrorMessage(data: any): string | null {
        if (!data) {
            return null;
        }

        const baseMessage = data.message || data.detail || data.error || null;
        const errors = data.errors && typeof data.errors === 'object'
            ? Object.entries(data.errors)
                .flatMap(([field, messages]) => {
                    const list = Array.isArray(messages) ? messages : [messages];
                    return list.map((message) => `${field}: ${message}`);
                })
            : [];

        if (errors.length > 0) {
            return [baseMessage, errors.slice(0, 3).join(' ')].filter(Boolean).join(' ');
        }

        return baseMessage;
    }
}

export const offlineSyncManager = new OfflineSyncManager();

// Automatically attempt sync when coming online
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        offlineSyncManager.processQueue();
    });
}
