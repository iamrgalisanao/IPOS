import axios from 'axios';
import { offlineSalesQueue } from './offlineSalesQueue.ts';
import type { OfflineTransactionEnvelope, OfflineSyncStatus } from './offlineSalesQueue.ts';
import { isOffline } from './offlineGuards.ts';

export class OfflineSyncManager {
    
    /**
     * Batch pending offline transactions and send them to the server.
     * Only runs when online.
     */
    public async processQueue(): Promise<void> {
        if (isOffline()) {
            return;
        }

        const pendingTransactions = await offlineSalesQueue.getQueuedTransactions();
        if (pendingTransactions.length === 0) {
            return;
        }

        await offlineSalesQueue.recordSyncAttempt(new Date().toISOString());

        // Group by batch_reference (since envelopes share batch_reference if they were part of the same cart, but here each checkout is one envelope)
        const groupedByBatch = this.groupByBatch(pendingTransactions);

        for (const [batchRef, envelopes] of Object.entries(groupedByBatch)) {
            await this.syncBatch(batchRef, envelopes);
        }
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
                    submitted_at: env.payload.submitted_at,
                    items: env.payload.items,
                    client_subtotal: env.payload.client_subtotal,
                    client_tax_total: env.payload.client_tax_total,
                    client_total: env.payload.client_total,
                }))
            };

            const response = await axios.post('/api/pos/offline-sync', payload);

            if (response.status === 202 || response.status === 200) {
                const results = response.data.imports || [];
                for (const env of envelopes) {
                    const result = results.find((r: any) => r.offline_sequence_number === env.offline_sequence);
                    if (result) {
                        const newStatus = this.mapServerStatus(result.status);
                        await offlineSalesQueue.updateTransactionStatus(env.id, newStatus, result.reason || result.rejection_reason || undefined);
                    } else {
                        await offlineSalesQueue.updateTransactionStatus(env.id, 'accepted');
                    }
                }

                await offlineSalesQueue.recordSyncSuccess(new Date().toISOString());
            }
        } catch (error: any) {
            console.error('Batch sync failed:', error);

            let newStatus: OfflineSyncStatus = 'failed';
            let errorMessage = error.message;

            if (error.response) {
                const status = error.response.status;
                
                if (status === 422) {
                    newStatus = 'failed';
                    errorMessage = error.response.data?.message || 'Sync failed. Transactions remain safely queued on this terminal. Reconnect and retry synchronization.';
                }
                else if (status === 401 || status === 403) {
                    newStatus = 'failed';
                    errorMessage = 'Authentication failed. Please log in again.';
                }
                else if (status === 409) {
                    newStatus = 'conflict';
                    errorMessage = error.response.data.message || 'Server recalculation conflict.';
                }
                else {
                    errorMessage = error.response.data?.message || errorMessage;
                }
            } else {
                errorMessage = 'Sync failed. Transactions remain safely queued on this terminal. Reconnect and retry synchronization.';
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
                return 'accepted';
            case 'duplicate':
                return 'duplicate';
            case 'rejected':
                return 'rejected';
            case 'conflict':
                return 'conflict';
            default:
                return 'failed';
        }
    }

    public async retryFailed(): Promise<void> {
        if (isOffline()) {
            return;
        }

        await this.processQueue();
    }
}

export const offlineSyncManager = new OfflineSyncManager();

// Automatically attempt sync when coming online
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        offlineSyncManager.processQueue();
    });
}
