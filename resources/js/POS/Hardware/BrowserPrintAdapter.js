import { PosHardwareAdapter } from './PosHardwareAdapter.js';

/**
 * An adapter that falls back to the native browser window.print()
 * for receipt printing.
 */
export class BrowserPrintAdapter extends PosHardwareAdapter {
    async printReceipt(receiptData) {
        console.log('[BrowserPrintAdapter] triggering window.print()');
        // In a real implementation, you would format the receipt into a hidden iframe or print window.
        // IPOS handles the actual print CSS via the standard window.print() call in Receipt.jsx.
        try {
            window.print();
            return {
                status: 'browser_print_invoked',
                capability: 'available_limited',
                physically_validated: false,
                status_source: 'browser_api_presence',
            };
        } catch (error) {
            return {
                status: 'hardware_failed',
                capability: 'available_limited',
                physically_validated: false,
                status_source: 'browser_api_presence',
                error_code: 'browser_print_failed',
                error_message: error?.message || String(error),
            };
        }
    }

    async openCashDrawer() {
        console.warn('[BrowserPrintAdapter] cannot open cash drawer directly from browser without a print job or extension.');
        return {
            status: 'hardware_unavailable',
            capability: 'unavailable',
            physically_validated: false,
            status_source: 'browser_adapter_limit',
        };
    }

    async getPrinterStatus() {
        return 'available_limited';
    }

    async getHardwareStatus() {
        return {
            adapter: 'BrowserPrintAdapter',
            printerCapability: 'available_limited',
            drawerCapability: 'unavailable',
            physicallyValidated: false,
            statusSource: 'browser_api_presence',
            validationEvidenceId: null,
        };
    }
}
