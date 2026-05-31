import { PosHardwareAdapter } from './PosHardwareAdapter';

/**
 * An adapter that falls back to the native browser window.print()
 * for receipt printing.
 */
export class BrowserPrintAdapter extends PosHardwareAdapter {
    async printReceipt(receiptData) {
        console.log('[BrowserPrintAdapter] triggering window.print()');
        // In a real implementation, you would format the receipt into a hidden iframe or print window.
        // IPOS handles the actual print CSS via the standard window.print() call in Receipt.jsx.
        window.print();
        return true;
    }

    async openCashDrawer() {
        console.warn('[BrowserPrintAdapter] cannot open cash drawer directly from browser without a print job or extension.');
        return false;
    }

    async getPrinterStatus() {
        // Browser cannot natively report printer status, assume online
        return 'online';
    }
}
