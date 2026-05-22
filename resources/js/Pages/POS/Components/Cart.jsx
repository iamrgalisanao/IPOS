import React, { useMemo } from 'react';
import { ShoppingCart, Trash2, Plus, Minus, CreditCard, ChevronRight, Calculator, X, Loader2, AlertTriangle, RefreshCw, WifiOff } from 'lucide-react';
import StatusUncertainPanel from './StatusUncertainPanel';

export default function Cart({ items, onUpdateQuantity, onClear, onCheckout, isSubmitting, checkoutError, submissionFailed, onClose, checkoutState = 'draft', isCheckingStatus = false, onCheckStatus, onRetryCheckout, isOffline = false, isStale = false, offlineCaptureAllowed = false, offlineQueueSummary = null, onRetryOfflineSync }) {
    const totals = useMemo(() => {
        const subtotal = items.reduce((sum, item) => sum + (Number(item.selling_price || item.unit_price || 0) * item.quantity), 0);
        // VAT included in price for now
        const tax = subtotal * (12 / 112); // Back-calculated 12% VAT
        const total = subtotal;
        return { subtotal, tax, total };
    }, [items]);

    const queueSummary = offlineQueueSummary || {
        queued: 0,
        syncing: 0,
        accepted: 0,
        duplicate: 0,
        rejected: 0,
        conflict: 0,
        failed: 0,
        cancelled: 0,
        total: 0,
        lastSyncAttemptAt: null,
        lastSuccessfulSyncAt: null,
    };

    const checkoutDisabled = items.length === 0
        || isSubmitting
        || isCheckingStatus
        || checkoutState === 'checking'
        || (isOffline ? !offlineCaptureAllowed : isStale);

    const shouldShowSyncPanel = queueSummary.total > 0;

    return (
        <div className="flex flex-col h-full bg-slate-900 shadow-2xl relative">
            {/* Cart Header */}
            <div className="p-4 border-b border-slate-800 flex items-center justify-between bg-slate-900 sticky top-0 z-10 shrink-0">
                <div className="flex items-center gap-2">
                    <ShoppingCart className="w-5 h-5 text-indigo-400" />
                    <h2 className="font-bold text-lg">Draft Cart</h2>
                </div>
                <div className="flex items-center gap-2">
                    <span className="bg-slate-800 text-slate-400 px-2.5 py-1 rounded-md text-xs font-medium tracking-wide">
                        {items.length} Items &middot; <span className="text-indigo-400 font-bold font-mono">₱{totals.total.toFixed(2)}</span>
                    </span>
                    {onClose && (
                        <button 
                            onClick={onClose}
                            className="md:hidden p-1.5 text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 rounded-lg transition-colors"
                        >
                            <X className="w-4 h-4" />
                        </button>
                    )}
                </div>
            </div>

            <StatusUncertainPanel
                mode={checkoutState}
                isCheckingStatus={isCheckingStatus}
                onCheckStatus={onCheckStatus}
                onRetry={onRetryCheckout}
            />

            {shouldShowSyncPanel && (
                <div className="mx-4 mt-4 rounded-2xl border border-slate-700/70 bg-slate-950/60 p-4 shadow-xl">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h3 className="text-sm font-bold text-slate-100">Offline Sync Status</h3>
                            <p className="mt-1 text-[11px] text-slate-400">Cashier-visible local queue and synchronization state for this terminal.</p>
                        </div>
                        <button
                            type="button"
                            onClick={onRetryOfflineSync}
                            disabled={!onRetryOfflineSync || isOffline || (queueSummary.queued + queueSummary.failed === 0)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-600 px-3 py-2 text-[11px] font-bold uppercase tracking-wider text-slate-200 transition hover:border-indigo-400 hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <RefreshCw className="h-3.5 w-3.5" />
                            Retry Sync
                        </button>
                    </div>

                    <div className="mt-4 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                        {[
                            ['Queued', queueSummary.queued],
                            ['Syncing', queueSummary.syncing],
                            ['Accepted', queueSummary.accepted],
                            ['Duplicate', queueSummary.duplicate],
                            ['Rejected', queueSummary.rejected],
                            ['Conflict', queueSummary.conflict],
                            ['Failed', queueSummary.failed],
                            ['Cancelled', queueSummary.cancelled],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-xl border border-slate-800 bg-slate-900/80 px-3 py-2">
                                <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{label}</div>
                                <div className="mt-1 text-lg font-black text-slate-100">{value}</div>
                            </div>
                        ))}
                    </div>

                    <div className="mt-4 space-y-2 text-[11px] text-slate-400">
                        <div>
                            Last sync attempt: {queueSummary.lastSyncAttemptAt ? new Date(queueSummary.lastSyncAttemptAt).toLocaleString() : 'No sync attempt yet'}
                        </div>
                        <div>
                            Last successful sync: {queueSummary.lastSuccessfulSyncAt ? new Date(queueSummary.lastSuccessfulSyncAt).toLocaleString() : 'No successful sync yet'}
                        </div>
                        {queueSummary.queued > 0 && (
                            <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-amber-100">
                                Pending server synchronization and reconciliation. This is not final ledger posting.
                            </div>
                        )}
                        {queueSummary.failed > 0 && (
                            <div className="rounded-xl border border-rose-500/20 bg-rose-500/10 px-3 py-2 text-rose-100">
                                Sync failed. Transactions remain safely queued on this terminal. Reconnect and retry synchronization.
                            </div>
                        )}
                        {(queueSummary.conflict > 0 || queueSummary.rejected > 0) && (
                            <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-amber-100">
                                Some offline transactions require admin review before posting.
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Error Message */}
            {checkoutError && (
                <div className={`mx-4 mt-4 p-3 border rounded-xl text-xs flex flex-col gap-1 animate-in fade-in slide-in-from-top-2 shadow-lg transition-all duration-300 ${
                    submissionFailed 
                        ? 'bg-rose-600 border-rose-500 text-white' 
                        : 'bg-rose-500/10 border-rose-500/20 text-rose-400'
                }`}>
                    <div className="flex items-center gap-2 font-bold uppercase tracking-wider text-[10px]">
                        <AlertTriangle className={`w-3 h-3 ${submissionFailed ? 'text-white' : 'text-rose-400'}`} />
                        {submissionFailed ? 'Submission Failed' : 'Validation Error'}
                    </div>
                    <div className="text-sm font-medium">
                        {checkoutError}
                    </div>
                    {submissionFailed && (
                        <div className="mt-1 text-[10px] opacity-80 italic">
                            Please check the highlighted issue and try again. Your cart is safe.
                        </div>
                    )}
                </div>
            )}

            {/* Cart Items List */}
            <div className="flex-1 overflow-y-auto p-4 space-y-3">
                {items.length === 0 ? (
                    <div className="h-full flex flex-col items-center justify-center text-slate-600">
                        <Calculator className="w-12 h-12 mb-2 opacity-10" />
                        <p>Cart is currently empty</p>
                    </div>
                ) : (
                    items.map((item, index) => {
                        const itemId = item.id || item.product_id;
                        return (
                        <div key={itemId || `cart-item-${index}`} className="flex gap-3 bg-slate-800/50 p-3 rounded-xl border border-slate-700/50 group">
                            <div className="flex-1">
                                <h4 className="text-sm font-semibold text-slate-200 line-clamp-1">{item.name || item.display_name}</h4>
                                <div className="flex items-center gap-2 mt-1">
                                    <span className="text-xs text-slate-500">₱{Number(item.selling_price || item.unit_price).toFixed(2)}</span>
                                    <span className="text-[10px] bg-slate-700 text-slate-400 px-1 rounded uppercase tracking-wider font-mono">
                                        {item.sku}
                                    </span>
                                </div>
                            </div>
                            
                            <div className="flex items-center gap-1.5 bg-slate-900/50 rounded-xl p-1 border border-slate-700/50 shrink-0">
                                <button 
                                    onClick={() => onUpdateQuantity(itemId, -1)}
                                    disabled={isSubmitting}
                                    className="w-9 h-9 flex items-center justify-center bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-lg transition-all active:scale-90 disabled:opacity-50 disabled:cursor-not-allowed shadow-inner"
                                    type="button"
                                >
                                    <Minus className="w-4 h-4" />
                                </button>
                                <span className="text-sm font-black min-w-[1.75rem] text-center text-slate-200">{item.quantity}</span>
                                <button 
                                    onClick={() => onUpdateQuantity(itemId, 1)}
                                    disabled={isSubmitting}
                                    className="w-9 h-9 flex items-center justify-center bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-lg transition-all active:scale-90 disabled:opacity-50 disabled:cursor-not-allowed shadow-inner"
                                    type="button"
                                >
                                    <Plus className="w-4 h-4" />
                                </button>
                            </div>

                            <div className="text-right min-w-[60px]">
                                <p className="text-sm font-bold text-indigo-400">
                                    ₱{(Number(item.selling_price || item.unit_price) * item.quantity).toFixed(2)}
                                </p>
                            </div>
                        </div>
                        );
                    })
                )}
            </div>

            {/* Cart Footer / Totals */}
            <div className="p-3 border-t border-slate-800 bg-slate-900/80 backdrop-blur-md space-y-3 shrink-0">
                <div className="space-y-1.5 px-1">
                    <div className="flex justify-between text-sm">
                        <span className="text-slate-500">Subtotal</span>
                        <span className="text-slate-300 font-mono">₱{totals.subtotal.toFixed(2)}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                        <span className="text-slate-500">VAT (12%)</span>
                        <span className="text-slate-300 font-mono italic text-xs">Included</span>
                    </div>
                    <div className="flex justify-between items-center pt-2 mt-1 border-t border-slate-800/50">
                        <span className="text-base font-bold text-white">Total</span>
                        <span className="text-xl font-black text-white font-mono tracking-tight">
                            ₱{totals.total.toFixed(2)}
                        </span>
                    </div>
                </div>

                {isOffline && offlineCaptureAllowed && (
                    <div className="mx-1 rounded-xl border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-100">
                        Offline capture is enabled. Transactions will be queued on this terminal and synchronized when connectivity returns.
                    </div>
                )}

                <div className="flex gap-2">
                    <button
                        onClick={onClear}
                        disabled={items.length === 0 || isSubmitting}
                        className="flex-1 flex items-center justify-center gap-1.5 py-3 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl font-semibold transition-all disabled:opacity-50 disabled:cursor-not-allowed text-sm"
                    >
                        <Trash2 className="w-4 h-4 shrink-0" />
                        <span className="hidden sm:inline">Cancel</span>
                        <span className="sm:hidden">Clear</span>
                    </button>
                    <button
                        onClick={onCheckout}
                        disabled={checkoutDisabled}
                        className={`flex-[2] flex items-center justify-center gap-1.5 py-3 rounded-xl font-bold shadow-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed text-sm ${
                            isOffline && !offlineCaptureAllowed
                                ? 'bg-rose-950/40 text-rose-400 border border-rose-500/30'
                                : isOffline && offlineCaptureAllowed
                                    ? 'bg-amber-950/40 text-amber-300 border border-amber-500/30'
                                : isStale
                                    ? 'bg-amber-950/40 text-amber-400 border border-amber-500/30'
                                    : 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-indigo-600/20'
                        }`}
                    >
                        {isSubmitting ? (
                            <>
                                <Loader2 className="w-4 h-4 animate-spin" />
                                <span>Completing Sale...</span>
                            </>
                        ) : isOffline && !offlineCaptureAllowed ? (
                            <>
                                <AlertTriangle className="w-4 h-4 shrink-0 text-rose-400" />
                                <span>Offline Locked</span>
                            </>
                        ) : isOffline && offlineCaptureAllowed ? (
                            <>
                                <WifiOff className="w-4 h-4 shrink-0 text-amber-300" />
                                <span>Capture Offline</span>
                            </>
                        ) : isStale ? (
                            <>
                                <AlertTriangle className="w-4 h-4 shrink-0 text-amber-400" />
                                <span>Sync Required</span>
                            </>
                        ) : (
                            <>
                                <CreditCard className="w-4 h-4 shrink-0" />
                                <span>Ready to Complete</span>
                                <ChevronRight className="w-4 h-4 shrink-0" />
                            </>
                        )}
                    </button>
                </div>
            </div>
        </div>
    );
}
