import React, { useState } from 'react';
import { LayoutGrid, RefreshCw, Loader2, X, AlertTriangle } from 'lucide-react';

/**
 * StaleLayoutBanner
 *
 * Renders a non-blocking amber notification bar when the server reports that the
 * terminal's active layout is out of date (layout_drift === true in a heartbeat response).
 *
 * UX contract:
 *   - Layout reloads without clearing the active cart.
 *   - After a successful reload, onReloaded() is called so the parent can clear drift state.
 *   - If a reload fails, the banner persists and shows a retry message.
 *   - The banner can be dismissed for the current session (it re-appears on the next page
 *     load or if heartbeat continues to report drift after the timer expires).
 *
 * Props:
 *   layoutName      {string|null}  - The server-side layout name (e.g. "Main Grid v2")
 *   onReloaded      {function}     - Called with the new layout response after a successful reload
 *   onDismiss       {function}     - Called when the user explicitly closes the banner
 */
export default function StaleLayoutBanner({ layoutName, onReloaded, onDismiss }) {
    const [isReloading, setIsReloading] = useState(false);
    const [reloadError, setReloadError] = useState(null);

    const handleReload = async () => {
        if (isReloading) return;

        setIsReloading(true);
        setReloadError(null);

        try {
            // Fetch the updated layout from the server.
            // The layout endpoint returns layout_version_hash which we persist into IndexedDB.
            const response = await axios.get(route('pos.layout'));

            if (!response.data || response.data.fallback) {
                setReloadError('Layout could not be loaded from server. Please try again.');
                return;
            }

            // Persist the refreshed layout hash to IndexedDB so the next heartbeat matches.
            try {
                const { catalogCache } = await import('@/POS/offline/catalogCache');
                await catalogCache.updateLayoutVersionHash(response.data.layout_version_hash ?? null);
            } catch (cacheErr) {
                // Non-fatal: hash will re-align on next full sync.
                console.warn('Failed to persist refreshed layout hash to IndexedDB:', cacheErr);
            }

            // Notify parent to update activeLayout state and clear layoutDrift.
            onReloaded?.(response.data);
        } catch (err) {
            console.error('Failed to reload POS layout:', err);
            setReloadError('Layout reload failed. Check your connection and try again.');
        } finally {
            setIsReloading(false);
        }
    };

    return (
        <div
            id="stale-layout-banner"
            role="status"
            aria-live="polite"
            className="w-full shrink-0 z-40"
        >
            {/* Main amber notification strip */}
            <div className="bg-gradient-to-r from-amber-950 via-amber-900/90 to-amber-950 border-b border-amber-700/30 px-4 py-2.5 flex items-center justify-between text-xs font-semibold shadow-lg shadow-amber-950/20">
                <div className="flex items-center gap-2 flex-1 min-w-0">
                    <LayoutGrid className="w-4 h-4 text-amber-400 shrink-0 animate-pulse" />
                    <div className="min-w-0">
                        <span className="text-amber-100">
                            <strong className="text-amber-300 uppercase tracking-wider text-[10px]">
                                Updated POS Layout Available
                            </strong>
                            {layoutName && (
                                <span className="ml-1.5 text-amber-200/80">— {layoutName}</span>
                            )}
                        </span>
                        <span className="block text-amber-300/70 text-[10px] mt-0.5">
                            Your product grid has changed. Reloading updates the layout without clearing the cart.
                        </span>
                    </div>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2 shrink-0 ml-3">
                    <button
                        id="stale-layout-reload-btn"
                        onClick={handleReload}
                        disabled={isReloading}
                        className="px-3 py-1 bg-amber-700 hover:bg-amber-600 disabled:bg-amber-900/60 disabled:text-amber-500 text-white rounded-lg flex items-center gap-1.5 transition-all text-[11px] font-black uppercase tracking-wider"
                        aria-label="Reload POS layout"
                    >
                        {isReloading
                            ? <Loader2 className="w-3 h-3 animate-spin" />
                            : <RefreshCw className="w-3 h-3" />}
                        Reload Layout
                    </button>

                    {/* Dismiss button */}
                    <button
                        id="stale-layout-dismiss-btn"
                        onClick={onDismiss}
                        className="p-1.5 hover:bg-amber-800/60 rounded-lg text-amber-400 hover:text-amber-200 transition"
                        aria-label="Dismiss stale layout notification"
                    >
                        <X className="w-3.5 h-3.5" />
                    </button>
                </div>
            </div>

            {/* Inline retry error message — shown below the amber strip */}
            {reloadError && (
                <div className="bg-rose-950/70 border-b border-rose-700/30 px-4 py-2 flex items-start gap-2 text-[11px] text-rose-200">
                    <AlertTriangle className="w-3.5 h-3.5 text-rose-400 shrink-0 mt-0.5" />
                    <span>{reloadError}</span>
                </div>
            )}
        </div>
    );
}
