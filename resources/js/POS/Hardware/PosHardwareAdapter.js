/**
 * Defines the contract for POS hardware operations.
 * Implementations should handle printing receipts, opening cash drawers, etc.
 */
export class PosHardwareAdapter {
    /**
     * Print a receipt.
     * @param {Object} receiptData - The receipt data to print.
     * @returns {Promise<boolean>} True if successful.
     */
    async printReceipt(receiptData) {
        throw new Error("Not implemented");
    }

    /**
     * Open the connected cash drawer.
     * @returns {Promise<boolean>} True if successful.
     */
    async openCashDrawer() {
        throw new Error("Not implemented");
    }

    /**
     * Check the status of the connected printer.
     * @returns {Promise<string>} e.g., 'online', 'offline', 'out_of_paper'
     */
    async getPrinterStatus() {
        throw new Error("Not implemented");
    }
}
