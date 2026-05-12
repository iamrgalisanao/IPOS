import React, { useMemo } from 'react';
import { ShoppingCart, Trash2, Plus, Minus, CreditCard, ChevronRight, Calculator, X, Loader2, AlertTriangle } from 'lucide-react';

export default function Cart({ items, onUpdateQuantity, onClear, onCheckout, isSubmitting, checkoutError, submissionFailed, onClose }) {
    const totals = useMemo(() => {
        const subtotal = items.reduce((sum, item) => sum + (Number(item.selling_price || item.unit_price || 0) * item.quantity), 0);
        // VAT included in price for now
        const tax = subtotal * (12 / 112); // Back-calculated 12% VAT
        const total = subtotal;
        return { subtotal, tax, total };
    }, [items]);

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
                            
                            <div className="flex items-center gap-2 bg-slate-900/50 rounded-lg p-1 border border-slate-700/50 shrink-0">
                                <button 
                                    onClick={() => onUpdateQuantity(itemId, -1)}
                                    disabled={isSubmitting}
                                    className="p-1.5 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-md transition-colors active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <Minus className="w-3.5 h-3.5" />
                                </button>
                                <span className="text-sm font-bold min-w-[1.5rem] text-center">{item.quantity}</span>
                                <button 
                                    onClick={() => onUpdateQuantity(itemId, 1)}
                                    disabled={isSubmitting}
                                    className="p-1.5 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-md transition-colors active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <Plus className="w-3.5 h-3.5" />
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
                        disabled={items.length === 0 || isSubmitting}
                        className="flex-[2] flex items-center justify-center gap-1.5 py-3 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold shadow-lg shadow-indigo-600/20 transition-all disabled:opacity-50 disabled:cursor-not-allowed text-sm"
                    >
                        {isSubmitting ? (
                            <>
                                <Loader2 className="w-4 h-4 animate-spin" />
                                <span>Completing Sale...</span>
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
