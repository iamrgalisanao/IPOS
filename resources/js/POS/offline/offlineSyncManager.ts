import axios from 'axios';
import { offlineSalesQueue } from './offlineSalesQueue.ts';
import type { OfflineTransactionEnvelope, OfflineSyncStatus } from './offlineSalesQueue.ts';
import { isOffline } from './offlineGuards.ts';

export class OfflineSyncManager {
    private readonly ownerInstanceId = `browser:${typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36)}`;

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
            if (env.next_retry_at) {
                const nextRetryAt = Date.parse(env.next_retry_at);
                return !Number.isFinite(nextRetryAt) || now.getTime() >= nextRetryAt;
            }
            if (!env.last_sync_attempt_at) return true;

            const attempts = env.retry_count || 0;
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
        const leasedEnvelopes: OfflineTransactionEnvelope[] = [];

        try {
            for (const env of envelopes) {
                try {
                    leasedEnvelopes.push(await offlineSalesQueue.acquireLease(
                        env.id,
                        this.ownerInstanceId,
                        'offline-sales-sync',
                        'story-41.2'
                    ));
                } catch (leaseError: any) {
                    console.warn('Skipping offline transaction already owned by another sync worker:', {
                        id: env.id,
                        offline_sequence: env.offline_sequence,
                        message: leaseError?.message || String(leaseError),
                    });
                }
            }

            if (leasedEnvelopes.length === 0) {
                return;
            }

            // Prepare the payload according to backend validation rules (SyncBatchRequest)
            const imports = await Promise.all(leasedEnvelopes.map(async env => {
                const importPayload = {
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
                    user_id: env.payload.user_id,
                    cashier_id: env.payload.cashier_id,
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
                    sync_status: 'pending',
                    sync_attempt_count: env.retry_count || 0,
                    last_sync_attempt_at: env.last_sync_attempt_at,

                    // Hashing chain properties
                    previous_hash: env.previous_hash,
                    row_hash: env.row_hash,
                    offline_transaction_uuid: env.offline_transaction_uuid || env.id,
                    terminal_binding_epoch: env.terminal_binding_epoch,
                    queue_state_revision: env.queue_state_revision,
                    sync_attempt_id: env.last_sync_attempt_id,
                    lease_id: env.lease?.lease_id,
                    attempt_generation: env.last_attempt_generation || 1,
                };
                const businessFingerprint = await this.computeBusinessPayloadFingerprint(importPayload);

                return {
                    ...importPayload,
                    payload_hash: businessFingerprint,
                    business_payload_fingerprint: businessFingerprint,
                };
            }));

            const payload = {
                batch_reference: batchRef,
                imports,
            };

            const response = await axios.post('/api/v1/pos/offline-sales/sync', payload, {
                headers: this.buildContextHeaders(leasedEnvelopes),
            });

            if (response.status === 202 || response.status === 200) {
                const results = response.data.imports || [];
                const resultsByUuid = new Map<string, any>();

                for (const result of results) {
                    const uuid = result?.offline_transaction_uuid;
                    if (!uuid || resultsByUuid.has(uuid)) {
                        console.warn('Offline sync response contained an invalid or duplicate envelope result:', result);
                        continue;
                    }
                    resultsByUuid.set(uuid, result);
                }

                for (const env of leasedEnvelopes) {
                    const offlineUuid = env.offline_transaction_uuid || env.id;
                    const result = resultsByUuid.get(offlineUuid);
                    const guard = {
                        leaseId: env.lease?.lease_id || '',
                        syncAttemptId: env.last_sync_attempt_id || '',
                        attemptGeneration: env.last_attempt_generation || 1,
                        ownerInstanceId: this.ownerInstanceId,
                    };
                    if (result) {
                        const newStatus = this.mapServerStatus(result.status);
                        await offlineSalesQueue.updateTransactionStatus(
                            env.id,
                            newStatus,
                            result.reason || result.rejection_reason || result.conflict_notes || undefined,
                            guard
                        );
                    } else {
                        await offlineSalesQueue.updateTransactionStatus(
                            env.id,
                            'failed',
                            'Server response did not include a result for this offline transaction. It will be retried safely.',
                            guard
                        );
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
                offline_sequences: (leasedEnvelopes.length > 0 ? leasedEnvelopes : envelopes).map((env) => env.offline_sequence),
                local_status: newStatus,
            };

            if (newStatus === 'conflict') {
                console.warn('Batch sync requires review:', logPayload);
            } else {
                console.error('Batch sync failed:', logPayload);
            }

            // Apply the failure status
            for (const env of leasedEnvelopes.length > 0 ? leasedEnvelopes : envelopes) {
                try {
                    const guard = env.lease?.lease_id && env.last_sync_attempt_id
                        ? {
                            leaseId: env.lease.lease_id,
                            syncAttemptId: env.last_sync_attempt_id,
                            attemptGeneration: env.last_attempt_generation || 1,
                            ownerInstanceId: this.ownerInstanceId,
                        }
                        : undefined;
                    await offlineSalesQueue.updateTransactionStatus(env.id, newStatus, errorMessage, guard);
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
            case 'accepted':
            case 'synced':
            case 'posted':
                return 'synced';
            case 'duplicate':
            case 'replayed':
                return 'synced';
            case 'rejected':
                return 'conflict';
            case 'conflict':
            case 'review_required':
                return 'conflict';
            case 'accepted_with_warning':
                return 'accepted_with_warning';
            case 'retryable_failed':
                return 'failed';
            default:
                return 'failed';
        }
    }

    private async computeBusinessPayloadFingerprint(importPayload: Record<string, any>): Promise<string> {
        const materialKeys = [
            'tenant_id',
            'branch_id',
            'terminal_id',
            'sales_machine_profile_id',
            'terminal_binding_epoch',
            'offline_transaction_uuid',
            'offline_sequence_number',
            'local_sequence',
            'user_id',
            'cashier_id',
            'cashier_shift_id',
            'drawer_session_id',
            'items',
            'client_subtotal',
            'client_tax_total',
            'client_total',
            'payment_method',
            'payments',
            'catalog_version_hash',
            'tax_configuration_version_hash',
            'payment_methods_version_hash',
            'terminal_policy_version_hash',
            'submitted_at',
            'terminal_timestamp',
            'timezone',
        ];
        const material: Record<string, any> = {};

        for (const key of materialKeys) {
            if (importPayload[key] !== undefined) {
                material[key] = importPayload[key];
            }
        }

        return this.computeSHA256(JSON.stringify(this.canonicalize(material)));
    }

    private canonicalize(value: any): any {
        if (Array.isArray(value)) {
            return value.map((item) => this.canonicalize(item));
        }

        if (value && typeof value === 'object') {
            return Object.keys(value)
                .sort()
                .reduce((acc, key) => {
                    acc[key] = this.canonicalize(value[key]);
                    return acc;
                }, {} as Record<string, any>);
        }

        return value;
    }

    private async computeSHA256(data: string): Promise<string> {
        const encoder = new TextEncoder();
        const dataBuffer = encoder.encode(data);
        const hashBuffer = await crypto.subtle.digest('SHA-256', dataBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
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
