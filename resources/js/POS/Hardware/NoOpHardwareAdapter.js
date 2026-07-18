import { PosHardwareAdapter } from './PosHardwareAdapter.js';

/**
 * A safe default adapter that does nothing but logs its intentions.
 * Used when no hardware is connected or configured.
 */
export class NoOpHardwareAdapter extends PosHardwareAdapter {
    async printReceipt(receiptData) {
        console.log('[NoOpHardwareAdapter] printReceipt called. Data:', receiptData);
        return {
            status: 'hardware_unavailable',
            capability: 'unavailable',
            physically_validated: false,
            status_source: 'noop_adapter',
        };
    }

    async openCashDrawer() {
        console.log('[NoOpHardwareAdapter] openCashDrawer called.');
        return {
            status: 'hardware_unavailable',
            capability: 'unavailable',
            physically_validated: false,
            status_source: 'noop_adapter',
        };
    }

    async getPrinterStatus() {
        return 'unavailable';
    }

    async getHardwareStatus() {
        return {
            adapter: 'NoOpHardwareAdapter',
            printerCapability: 'unavailable',
            drawerCapability: 'unavailable',
            physicallyValidated: false,
            statusSource: 'noop_adapter',
            validationEvidenceId: null,
        };
    }
}
