import React, { useMemo } from 'react';
import { ShoppingCart, Trash2, Plus, Minus, CreditCard, ChevronRight, Calculator, X, Loader2, AlertTriangle, RefreshCw, WifiOff, ShieldCheck, AlertCircle } from 'lucide-react';
import { Link } from '@inertiajs/react';
import StatusUncertainPanel from './StatusUncertainPanel';

export default function Cart({ items, onUpdateQuantity, onClear, onCheckout, isSubmitting, checkoutError, submissionFailed, onClose, checkoutState = 'draft', isCheckingStatus = false, onCheckStatus, onRetryCheckout, isOffline = false, isStale = false, offlineCaptureAllowed = false, offlineQueueSummary = null, onRetryOfflineSync, onOpenSpecialDiscount, appliedStatutoryDiscount, hasActiveShift = true }) {
    const totals = useMemo(() => {
        const subtotal = items.reduce((sum, item) => sum + (Number(item.selling_price || item.unit_price || 0) * item.quantity), 0);
        // VAT included in price for now
        const tax = subtotal * (12 / 112); // Back-calculated 12% VAT
        const total = subtotal;
        return { subtotal, tax, total };
    }, [items]);

    // Compute adjusted totals when a statutory discount is applied
    const adjustedTotals = useMemo(() => {
        if (!appliedStatutoryDiscount?.result?.is_valid) {
            return { discountAmount: 0, netPayable: totals.total };
        }
        const result = appliedStatutoryDiscount.result;
        return {
            discountAmount: Number(result.discount_amount || 0),
            vatExemptAmount: Number(result.vat_exempt_amount || 0),
            vatRemoved: Number(result.vat_amount_removed || 0),
            netPayable: Number(result.net_payable || totals.total),
        };
    }, [appliedStatutoryDiscount, totals.total]);

    const queueSummary = offlineQueueSummary || {
        pending: 0,
        syncing: 0,
        synced: 0,
        conflict: 0,
        accepted_with_warning: 0,
        failed: 0,
        cancelled: 0,
        total: 0,
        lastSyncAttemptAt: null,
        lastSuccessfulSyncAt: null,
    };
    const queueCounts = {
        pending: Number(queueSummary.pending ?? queueSummary.queued ?? 0),
        syncing: Number(queueSummary.syncing ?? 0),
        synced: Number(queueSummary.synced ?? queueSummary.accepted ?? 0),
        failed: Number(queueSummary.failed ?? 0),
        conflict: Number(queueSummary.conflict ?? 0),
        acceptedWithWarning: Number(queueSummary.accepted_with_warning ?? 0),
        cancelled: Number(queueSummary.cancelled ?? 0),
    };
    const reviewCount = queueCounts.conflict + queueCounts.acceptedWithWarning;
    const failedCount = queueCounts.failed;
    const actionableSyncCount = queueCounts.pending + queueCounts.syncing + queueCounts.failed + reviewCount;
    const activeItems = items.filter((item) => Number(item.quantity || 0) > 0);
    const activeItemCount = activeItems.length;
    const isActivelySubmitting = isSubmitting && activeItemCount > 0;

    const hasBlockingStockIssue = items.some((item) => {
        if (!item?.is_inventory_tracked) return false;

        const currentStock = Number(item.current_stock ?? 0);
        const availableToSell = Number(item.available_to_sell ?? currentStock);

        return item.stock_state === 'expired'
            || item.stock_state === 'out_of_stock'
            || item.stock_available === false
            || availableToSell <= 0
            || Number(item.quantity || 0) > availableToSell;
    });

    const checkoutDisabled = activeItemCount === 0
        || isActivelySubmitting
        || isCheckingStatus
        || checkoutState === 'checking'
        || hasBlockingStockIssue
        || (isOffline ? !offlineCaptureAllowed : isStale)
        || !hasActiveShift;



    const formatQuantity = (value) => {
        const quantity = Number(value);
        if (!Number.isFinite(quantity)) return '0';
        return Number.isInteger(quantity) ? String(quantity) : quantity.toFixed(2).replace(/\.?0+$/, '');
    };

    const getItemStockWarning = (item) => {
        if (!item?.is_inventory_tracked) return null;

        const currentStock = Number(item.current_stock ?? 0);
        const availableToSell = Number(item.available_to_sell ?? currentStock);

        if (item.stock_state === 'expired') {
            return 'Expired item. Remove before completing sale.';
        }

        if (item.stock_state === 'out_of_stock' || availableToSell <= 0 || item.stock_available === false) {
            return 'Out of stock. Remove before completing sale.';
        }

        if (item.quantity > availableToSell) {
            return `Only ${formatQuantity(availableToSell)} available to sell.`;
        }

        if (item.stock_state === 'near_expiry') {
            return item.next_expiry_date ? `Near expiry: ${item.next_expiry_date}` : 'Near expiry.';
        }

        if (item.stock_state === 'critical_stock') {
            return `Last ${formatQuantity(availableToSell)} available.`;
        }

        return null;
    };

    return (
        <div className="flex min-h-0 h-full flex-col overflow-hidden bg-slate-900 shadow-2xl relative">
            {/* Cart Header */}
            <div className="p-4 border-b border-slate-800 flex items-center justify-between bg-slate-900 sticky top-0 z-10 shrink-0">
                <div className="flex items-center gap-2">
                    <ShoppingCart className="w-5 h-5 text-indigo-400" />
                    <h2 className="font-bold text-lg">Draft Cart</h2>
                </div>
                <div className="flex items-center gap-2">
                    <span className="bg-slate-800 text-slate-400 px-2.5 py-1 rounded-md text-xs font-medium tracking-wide">
                        {activeItemCount} Items &middot; <span className="text-indigo-400 font-bold font-mono">₱{totals.total.toFixed(2)}</span>
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

            <div className="min-h-0 flex-1 overflow-y-auto">
                <StatusUncertainPanel
                    mode={checkoutState}
                    isCheckingStatus={isCheckingStatus}
                    onCheckStatus={onCheckStatus}
                    onRetry={onRetryCheckout}
                />




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
                <div className="p-4 space-y-3">
                    {activeItemCount === 0 ? (
                    <div className="flex min-h-48 flex-col items-center justify-center text-slate-650 px-4 text-center">
                        <ShoppingCart className="w-12 h-12 mb-3 text-slate-800" />
                        <p className="text-sm font-bold text-slate-500">Cart is currently empty</p>
                        {!hasActiveShift && (
                            <p className="text-xs text-slate-600 max-w-[200px] mt-1.5 leading-relaxed font-medium">Open shift to start checkout.</p>
                        )}
                    </div>
                ) : (
                    items.map((item, index) => {
                        const itemId = item.id || item.product_id;
                        const stockWarning = getItemStockWarning(item);
                        const canIncrease = !isActivelySubmitting
                            && (!item.is_inventory_tracked
                                || Number(item.available_to_sell ?? item.current_stock ?? 0) > Number(item.quantity || 0));
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
                                {stockWarning && (
                                    <div className="mt-2 inline-flex rounded-md border border-amber-500/20 bg-amber-500/10 px-2 py-1 text-[10px] font-semibold text-amber-200">
                                        {stockWarning}
                                    </div>
                                )}
                            </div>
                            
                            <div className="flex items-center gap-1.5 bg-slate-900/50 rounded-xl p-1 border border-slate-700/50 shrink-0">
                                <button 
                                    onClick={() => onUpdateQuantity(itemId, -1)}
                                    disabled={isActivelySubmitting || !hasActiveShift}
                                    className="w-9 h-9 flex items-center justify-center bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-lg transition-all active:scale-90 disabled:opacity-50 disabled:cursor-not-allowed shadow-inner"
                                    type="button"
                                >
                                    <Minus className="w-4 h-4" />
                                </button>
                                <span className="text-sm font-black min-w-[1.75rem] text-center text-slate-200">{item.quantity}</span>
                                <button 
                                    onClick={() => onUpdateQuantity(itemId, 1)}
                                    disabled={!canIncrease || !hasActiveShift}
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
                                <button
                                    onClick={() => onUpdateQuantity(itemId, -Number(item.quantity || 0))}
                                    disabled={isActivelySubmitting || !hasActiveShift}
                                    className="mt-2 inline-flex h-8 w-8 items-center justify-center rounded-lg border border-rose-500/20 bg-rose-500/10 text-rose-300 transition-all hover:border-rose-400/40 hover:bg-rose-500/20 hover:text-rose-100 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                                    type="button"
                                    title={`Remove ${item.name || item.display_name || 'item'}`}
                                    aria-label={`Remove ${item.name || item.display_name || 'item'} from cart`}
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                        );
                    })
                )}
                </div>
            </div>

            {/* Cart Footer / Totals */}
            <div className="shrink-0 border-t border-slate-800 bg-slate-900/95 p-3 space-y-3 shadow-[0_-10px_30px_-18px_rgba(0,0,0,0.9)]">
                <CartQueueNotice failedCount={failedCount} reviewCount={reviewCount} />

                <div className="space-y-1.5 px-1">
                    <div className="flex justify-between text-sm">
                        <span className="text-slate-500">Subtotal</span>
                        <span className="text-slate-300 font-mono">₱{totals.subtotal.toFixed(2)}</span>
                    </div>
                    <div className="flex justify-between text-sm">
                        <span className="text-slate-500">VAT (12%)</span>
                        <span className="text-slate-300 font-mono italic text-xs">Included</span>
                    </div>
                    {appliedStatutoryDiscount?.result?.is_valid && (
                        <>
                            <div className="flex justify-between text-sm">
                                <span className="text-amber-400">Less: VAT Exempt</span>
                                <span className="text-amber-400 font-mono">-₱{adjustedTotals.vatRemoved.toFixed(2)}</span>
                            </div>
                            <div className="flex justify-between text-sm">
                                <span className="text-emerald-400">Less: Statutory Discount</span>
                                <span className="text-emerald-400 font-mono">-₱{adjustedTotals.discountAmount.toFixed(2)}</span>
                            </div>
                        </>
                    )}
                    <div className="flex justify-between items-center pt-2 mt-1 border-t border-slate-800/50">
                        <span className="text-base font-bold text-white">Total</span>
                        <span className="text-xl font-black text-white font-mono tracking-tight">
                            ₱{adjustedTotals.netPayable.toFixed(2)}
                        </span>
                    </div>
                </div>

                <div className="space-y-2">
                    {/* Special Discount Trigger */}
                    {onOpenSpecialDiscount && (
                        <button
                            onClick={onOpenSpecialDiscount}
                            disabled={activeItemCount === 0 || isActivelySubmitting || !hasActiveShift}
                            className={`w-full flex items-center justify-center gap-2 py-2.5 rounded-xl font-bold text-sm transition-all disabled:opacity-50 disabled:cursor-not-allowed ${
                                appliedStatutoryDiscount
                                    ? 'border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20'
                                    : 'border border-slate-700 bg-slate-800/40 text-slate-300 hover:bg-slate-800 hover:text-white'
                            }`}
                        >
                            <ShieldCheck className="w-4 h-4 shrink-0" />
                            {appliedStatutoryDiscount ? (
                                <span>{appliedStatutoryDiscount.discountType?.name || 'Statutory discount applied'}</span>
                            ) : (
                                <span>Apply special discount</span>
                            )}
                        </button>
                    )}

                    <button
                        onClick={onCheckout}
                        disabled={checkoutDisabled}
                        className={`w-full flex items-center justify-center gap-1.5 py-3 rounded-xl font-bold shadow-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed text-sm ${
                            checkoutDisabled
                                ? 'bg-slate-800/50 text-slate-500 border border-slate-800/50'
                                : isOffline && !offlineCaptureAllowed
                                    ? 'bg-rose-950/40 text-rose-400 border border-rose-500/30'
                                : isOffline && offlineCaptureAllowed
                                    ? 'bg-amber-950/40 text-amber-300 border border-amber-500/30 shadow-amber-500/10'
                                : isStale
                                    ? 'bg-amber-950/40 text-amber-400 border border-amber-500/30 shadow-amber-500/10'
                                    : 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-indigo-600/20'
                        }`}
                    >
                        {!hasActiveShift ? (
                            <span>Open shift to start checkout</span>
                        ) : isActivelySubmitting ? (
                            <>
                                <Loader2 className="w-4 h-4 animate-spin" />
                                <span>{isOffline ? 'Capturing offline...' : 'Completing sale...'}</span>
                            </>
                        ) : hasBlockingStockIssue ? (
                            <>
                                <AlertTriangle className="w-4 h-4 shrink-0 text-rose-400" />
                                <span>Fix stock issue</span>
                            </>
                        ) : isOffline && !offlineCaptureAllowed ? (
                            <>
                                <AlertTriangle className="w-4 h-4 shrink-0 text-rose-400" />
                                <span>Offline locked</span>
                            </>
                        ) : isOffline && offlineCaptureAllowed ? (
                            <>
                                <WifiOff className="w-4 h-4 shrink-0 text-amber-300" />
                                <span>Ready to complete</span>
                                <ChevronRight className="w-4 h-4 shrink-0" />
                            </>
                        ) : isStale ? (
                            <>
                                <AlertTriangle className="w-4 h-4 shrink-0 text-amber-400" />
                                <span>Sync required</span>
                            </>
                        ) : (
                            <span>Checkout</span>
                        )}
                    </button>

                    {activeItemCount > 0 && (
                        <button
                            onClick={onClear}
                            disabled={isActivelySubmitting || !hasActiveShift}
                            className="w-full flex items-center justify-center gap-1.5 py-2.5 bg-slate-800/40 hover:bg-slate-800 text-slate-400 hover:text-slate-200 rounded-xl font-medium transition-all text-xs border border-slate-800"
                        >
                            <Trash2 className="w-3.5 h-3.5 shrink-0" />
                            <span>Clear cart</span>
                        </button>
                    )}
                </div>

                {/* Removed bottom offline info banner to reduce visual clutter on Checkout */}
            </div>
        </div>
    );
}

function CartQueueNotice({ failedCount, reviewCount }) {
    const totalIssues = failedCount + reviewCount;
    if (totalIssues === 0) return null;

    return (
        <div className="rounded-xl border border-rose-500/20 bg-rose-500/5 p-3 flex items-center justify-between text-xs text-rose-300 gap-3 animate-in fade-in duration-200">
            <div className="flex items-start gap-2.5 min-w-0">
                <AlertCircle className="w-4.5 h-4.5 text-rose-400 shrink-0 mt-0.5" />
                <div className="min-w-0">
                    <strong className="font-bold text-slate-200 block truncate">
                        {totalIssues} offline sale{totalIssues === 1 ? '' : 's'} need admin review
                    </strong>
                    <span className="text-[10px] text-slate-500 font-medium">Review before posting to server.</span>
                </div>
            </div>
            <Link
                href={route('pos.terminal.sync-status')}
                className="shrink-0 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-rose-300 transition-all border border-rose-500/20 active:scale-95"
            >
                Review Queue
            </Link>
        </div>
    );
}
