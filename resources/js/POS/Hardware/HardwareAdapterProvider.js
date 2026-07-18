import { NoOpHardwareAdapter } from './NoOpHardwareAdapter.js';
import { BrowserPrintAdapter } from './BrowserPrintAdapter.js';

/**
 * Service locator / provider for the POS Hardware Adapter.
 * In a future iteration, this could read from local settings to determine
 * which adapter to instantiate.
 */
class HardwareAdapterProvider {
    constructor() {
        this.activeAdapter = null;
    }

    /**
     * Initializes the correct hardware adapter based on settings.
     * @param {string} type - 'noop' or 'browser'
     */
    initialize(type = 'noop') {
        if (type === 'browser') {
            this.activeAdapter = new BrowserPrintAdapter();
        } else {
            this.activeAdapter = new NoOpHardwareAdapter();
        }
        return this.activeAdapter;
    }

    /**
     * Returns the currently active hardware adapter.
     */
    getAdapter() {
        if (!this.activeAdapter) {
            // Default to NoOp if not explicitly initialized
            this.initialize('noop');
        }
        return this.activeAdapter;
    }
}

// Export a singleton instance
export const hardwareAdapter = new HardwareAdapterProvider();
