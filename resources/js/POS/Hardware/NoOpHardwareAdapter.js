import { PosHardwareAdapter } from './PosHardwareAdapter';

/**
 * A safe default adapter that does nothing but logs its intentions.
 * Used when no hardware is connected or configured.
 */
export class NoOpHardwareAdapter extends PosHardwareAdapter {
    async printReceipt(receiptData) {
        console.log('[NoOpHardwareAdapter] printReceipt called. Data:', receiptData);
        return true;
    }

    async openCashDrawer() {
        console.log('[NoOpHardwareAdapter] openCashDrawer called.');
        return true;
    }

    async getPrinterStatus() {
        return 'offline'; // Safe default
    }
}
