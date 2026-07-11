import React, { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { X, ShieldAlert, CheckCircle2, AlertCircle, RefreshCw } from 'lucide-react';
import axios from 'axios';
import { useConnectivityStore } from '@/POS/offline/connectivityStore';
import { offlineSalesQueue } from '@/POS/offline/offlineSalesQueue';

// Simple UUID generator for idempotency keys
const generateUUID = () => {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
};

export default function VoidRefundModal({ isOpen, onClose, sale, mode }) {
    if (!isOpen) return null;

    const { isOffline } = useConnectivityStore();
    const [pendingRefs, setPendingRefs] = useState([]);

    useEffect(() => {
        const checkPendingSales = async () => {
            try {
                const all = await offlineSalesQueue.getAllTransactions();
                const unsynced = all
                    .filter(tx => ['pending', 'syncing', 'conflict'].includes(tx.status))
                    .map(tx => tx.payload?.local_transaction_reference)
                    .filter(Boolean);
                setPendingRefs(unsynced);
            } catch (err) {
                console.error('Failed to load pending offline references:', err);
            }
        };
        checkPendingSales();
    }, [isOpen]);

    const isUnsynced = sale?.local_transaction_reference ? pendingRefs.includes(sale.local_transaction_reference) : false;
    const isBlocked = isOffline || isUnsynced;

    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);

    // Form inputs
    const [reasonCode, setReasonCode] = useState(mode === 'void' ? 'CANCELLATION' : 'RETURN');
    const [reasonNotes, setReasonNotes] = useState('');
    const [supervisorEmail, setSupervisorEmail] = useState('');
    const [supervisorPassword, setSupervisorPassword] = useState('');
    
    // Refund specific states
    const [selectedItems, setSelectedItems] = useState(() => {
        // Initialize with checked: false, quantity: 1
        return (sale.items || []).map(item => ({
            sale_item_id: item.id,
            product_name: item.product_name,
            original_qty: parseFloat(item.quantity),
            quantity: 1,
            selected: false,
            unit_price: parseFloat(item.unit_price)
        }));
    });

    const [payoutMethod, setPayoutMethod] = useState('electronic'); // electronic or cash_exception
    const [customerRefundChannel, setCustomerRefundChannel] = useState('bank_transfer');
    const [customerReferenceDetails, setCustomerReferenceDetails] = useState('');
    const [cashExceptionReason, setCashExceptionReason] = useState('');

    const originalPaymentMethod = sale.payments?.[0]?.payment_method?.name || 'cash';
    const isElectronicOriginal = ['card', 'credit_card', 'gcash', 'maya', 'e-wallet'].includes(originalPaymentMethod.toLowerCase());

    const handleItemToggle = (idx) => {
        setSelectedItems(prev => prev.map((item, i) => 
            i === idx ? { ...item, selected: !item.selected } : item
        ));
    };

    const handleQtyChange = (idx, val) => {
        const qty = parseFloat(val);
        setSelectedItems(prev => prev.map((item, i) => {
            if (i === idx) {
                const safeQty = Math.max(0.1, Math.min(item.original_qty, qty));
                return { ...item, quantity: safeQty };
            }
            return item;
        }));
    };

    const handleVoid = async () => {
        if (isBlocked) return;
        setLoading(true);
        setError(null);

        const idempotencyKey = generateUUID();

        try {
            const response = await axios.post(route('pos.sales.void', sale.id), {
                reason_code: reasonCode,
                reason_notes: reasonNotes,
                supervisor_email: supervisorEmail,
                supervisor_password: supervisorPassword
            }, {
                headers: {
                    'Idempotency-Key': idempotencyKey
                }
            });

            if (response.data.success) {
                setSuccess('Transaction voided successfully!');
                setTimeout(() => {
                    onClose();
                    router.reload();
                }, 1500);
            }
        } catch (err) {
            const resData = err.response?.data;
            if (err.response?.status === 409) {
                setError(resData.message || 'Void is blocked due to conflict.');
            } else if (err.response?.status === 422 || err.response?.status === 403) {
                setError(resData.message || 'Authorization or validation failed.');
            } else {
                setError('An unexpected error occurred while processing the void.');
            }
        } finally {
            setLoading(false);
        }
    };

    const handleRefund = async () => {
        if (isBlocked) return;
        setLoading(true);
        setError(null);

        const itemsPayload = selectedItems
            .filter(item => item.selected)
            .map(item => ({
                sale_item_id: item.sale_item_id,
                quantity: item.quantity
            }));

        if (itemsPayload.length === 0) {
            setError('Please select at least one item to refund.');
            setLoading(false);
            return;
        }

        const idempotencyKey = generateUUID();

        try {
            const response = await axios.post(route('pos.sales.refund', sale.id), {
                items: itemsPayload,
                reason_code: reasonCode,
                reason_notes: reasonNotes,
                payout_method: payoutMethod,
                customer_refund_channel: customerRefundChannel,
                customer_reference_details: customerReferenceDetails,
                cash_exception_reason: cashExceptionReason,
                supervisor_email: supervisorEmail,
                supervisor_password: supervisorPassword
            }, {
                headers: {
                    'Idempotency-Key': idempotencyKey
                }
            });

            if (response.data.success) {
                setSuccess(response.data.message || 'Refund processed successfully!');
                setTimeout(() => {
                    onClose();
                    router.reload();
                }, 1500);
            }
        } catch (err) {
            const resData = err.response?.data;
            if (err.response?.status === 409) {
                setError(resData.message || 'Refund is blocked due to conflict.');
            } else if (err.response?.status === 422 || err.response?.status === 403) {
                setError(resData.message || 'Authorization or validation failed.');
            } else {
                setError('An unexpected error occurred while processing the refund.');
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
            <div className="bg-white rounded-[28px] max-w-lg w-full overflow-hidden shadow-2xl border border-slate-200 flex flex-col max-h-[90vh]">
                
                {/* Header */}
                <div className="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                    <h3 className="text-base font-bold text-slate-900 uppercase tracking-widest">
                        {mode === 'void' ? 'Void Transaction' : 'Refund Items'}
                    </h3>
                    <button onClick={onClose} className="p-1 hover:bg-slate-200 rounded-full transition-colors text-slate-400 hover:text-slate-600">
                        <X size={20} />
                    </button>
                </div>

                {/* Body */}
                <div className="p-6 overflow-y-auto space-y-6 flex-1 text-sm text-slate-600">
                    {isOffline && (
                        <div className="p-4 bg-rose-50 border border-rose-100 text-rose-700 rounded-2xl flex items-start gap-3">
                            <AlertCircle className="w-5 h-5 shrink-0 mt-0.5" />
                            <div>
                                <span className="font-semibold block">Adjustment Disabled (Offline Mode)</span>
                                <span className="text-xs">Voids and refunds are restricted during offline mode to prevent reconciliation conflicts. Please restore connection to proceed.</span>
                            </div>
                        </div>
                    )}

                    {!isOffline && isUnsynced && (
                        <div className="p-4 bg-rose-50 border border-rose-100 text-rose-700 rounded-2xl flex items-start gap-3">
                            <AlertCircle className="w-5 h-5 shrink-0 mt-0.5" />
                            <div>
                                <span className="font-semibold block">Adjustment Disabled (Unsynced Transaction)</span>
                                <span className="text-xs">This sale is still pending sync. Void and refund actions will be available after synchronization is completed.</span>
                            </div>
                        </div>
                    )}

                    {error && (
                        <div className="p-4 bg-rose-50 border border-rose-100 text-rose-700 rounded-2xl flex items-start gap-3">
                            <AlertCircle className="w-5 h-5 shrink-0 mt-0.5" />
                            <div>
                                <span className="font-semibold block">Adjustment Blocked</span>
                                <span className="text-xs">{error}</span>
                            </div>
                        </div>
                    )}

                    {success && (
                        <div className="p-4 bg-emerald-50 border border-emerald-100 text-emerald-700 rounded-2xl flex items-center gap-3">
                            <CheckCircle2 className="w-5 h-5 shrink-0 text-emerald-600" />
                            <span className="font-semibold">{success}</span>
                        </div>
                    )}

                    {/* Mode Specific Section */}
                    {mode === 'void' ? (
                        <div className="space-y-4">
                            <p className="leading-relaxed">
                                You are about to perform a **full void** on transaction <span className="font-bold text-slate-800">{sale.sale_number}</span>. 
                                This will reverse all payments and return items to inventory.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <h4 className="font-bold text-slate-800 uppercase tracking-wider text-xs">Select Items to Return</h4>
                            <div className="space-y-3 max-h-48 overflow-y-auto border border-slate-200 rounded-2xl p-3 bg-slate-50/50">
                                {selectedItems.map((item, idx) => (
                                    <div key={item.sale_item_id} className="flex items-center justify-between gap-4 p-2 hover:bg-white rounded-xl transition-all">
                                        <div className="flex items-center gap-3">
                                            <input 
                                                type="checkbox" 
                                                checked={item.selected} 
                                                onChange={() => handleItemToggle(idx)}
                                                className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 w-4 h-4"
                                            />
                                            <span className="font-medium text-slate-800">{item.product_name}</span>
                                        </div>
                                        {item.selected && (
                                            <div className="flex items-center gap-2">
                                                <span className="text-xs text-slate-400">Qty:</span>
                                                <input 
                                                    type="number" 
                                                    min="0.1" 
                                                    max={item.original_qty}
                                                    step="any"
                                                    value={item.quantity}
                                                    onChange={(e) => handleQtyChange(idx, e.target.value)}
                                                    className="w-16 px-2 py-1 border border-slate-300 rounded-lg text-center font-bold text-slate-700 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                                                />
                                                <span className="text-xs text-slate-400">/ {item.original_qty}</span>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>

                            {/* Electronic Payment closed shift routing rules */}
                            {isElectronicOriginal && (
                                <div className="space-y-3 p-4 bg-indigo-50/50 border border-indigo-100 rounded-2xl">
                                    <span className="font-bold text-indigo-900 block text-xs uppercase tracking-wide">Refund Payout Option</span>
                                    <p className="text-xs text-indigo-700 leading-relaxed mb-3">
                                        This was an electronic payment ({originalPaymentMethod}). If the payment gateway batch is already closed, select how to issue the return.
                                    </p>
                                    <div className="space-y-3">
                                        <label className="flex items-start gap-3 cursor-pointer">
                                            <input 
                                                type="radio" 
                                                name="payout_method" 
                                                value="electronic"
                                                checked={payoutMethod === 'electronic'}
                                                onChange={() => setPayoutMethod('electronic')}
                                                className="mt-1 border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <div>
                                                <span className="font-bold text-slate-800 block text-xs">Route to Manual Electronic Queue (Recommended)</span>
                                                <span className="text-[11px] text-slate-500">Submits to finance approval queue for manual bank transfer or e-wallet payout.</span>
                                            </div>
                                        </label>

                                        {payoutMethod === 'electronic' && (
                                            <div className="ml-6 grid grid-cols-2 gap-3 pt-2">
                                                <div>
                                                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Transfer Channel</label>
                                                    <select 
                                                        value={customerRefundChannel}
                                                        onChange={(e) => setCustomerRefundChannel(e.target.value)}
                                                        className="w-full text-xs border border-slate-300 rounded-xl p-2 bg-white"
                                                    >
                                                        <option value="bank_transfer">Bank Transfer</option>
                                                        <option value="ewallet">E-wallet (GCash/Maya)</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Account details</label>
                                                    <input 
                                                        type="text"
                                                        value={customerReferenceDetails}
                                                        placeholder="Account No. / Reference"
                                                        onChange={(e) => setCustomerReferenceDetails(e.target.value)}
                                                        className="w-full text-xs border border-slate-300 rounded-xl p-2"
                                                    />
                                                </div>
                                            </div>
                                        )}

                                        <label className="flex items-start gap-3 cursor-pointer">
                                            <input 
                                                type="radio" 
                                                name="payout_method" 
                                                value="cash_exception"
                                                checked={payoutMethod === 'cash_exception'}
                                                onChange={() => setPayoutMethod('cash_exception')}
                                                className="mt-1 border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <div>
                                                <span className="font-bold text-slate-800 block text-xs">Process Cash Payout Exception</span>
                                                <span className="text-[11px] text-slate-500">Pay out cash directly from the register. Requires supervisor PIN override.</span>
                                            </div>
                                        </label>

                                        {payoutMethod === 'cash_exception' && (
                                            <div className="ml-6 pt-2">
                                                <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Exception Reason Code</label>
                                                <input 
                                                    type="text"
                                                    required
                                                    value={cashExceptionReason}
                                                    placeholder="e.g. Customer insisted on cash payout"
                                                    onChange={(e) => setCashExceptionReason(e.target.value)}
                                                    className="w-full text-xs border border-slate-300 rounded-xl p-2"
                                                />
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Standard Audit Inputs */}
                    <div className="space-y-4 pt-4 border-t border-slate-100">
                        <div>
                            <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Reason Code</label>
                            <select 
                                value={reasonCode}
                                onChange={(e) => setReasonCode(e.target.value)}
                                className="w-full text-xs border border-slate-300 rounded-xl p-2.5 bg-white"
                            >
                                <option value="CANCELLATION">Customer Cancelled</option>
                                <option value="RETURN">Customer Return / Exchange</option>
                                <option value="WRONG_ITEM">Cashier Error - Incorrect Item</option>
                                <option value="OVERCHARGE">Pricing/Tax Correction</option>
                                <option value="DAMAGED">Damaged Goods</option>
                            </select>
                        </div>
                        <div>
                            <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Reason Notes</label>
                            <textarea 
                                value={reasonNotes}
                                onChange={(e) => setReasonNotes(e.target.value)}
                                placeholder="Explain the adjustment details..."
                                className="w-full text-xs border border-slate-300 rounded-xl p-3 h-20"
                            />
                        </div>
                    </div>

                    {/* Supervisor Credentials Override Block */}
                    <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-3">
                        <div className="flex items-center gap-2 text-slate-500 mb-1">
                            <ShieldAlert size={16} />
                            <span className="font-bold text-xs uppercase tracking-wider">Supervisor Authorization</span>
                        </div>
                        <p className="text-[11px] text-slate-400 leading-normal">
                            Enter supervisor credentials to authorize this adjustment. (Leave blank if you have direct bypass rights).
                        </p>
                        <div className="grid grid-cols-2 gap-3">
                            <input 
                                type="email"
                                value={supervisorEmail}
                                placeholder="Supervisor Email"
                                onChange={(e) => setSupervisorEmail(e.target.value)}
                                className="text-xs border border-slate-300 rounded-xl p-2.5"
                            />
                            <input 
                                type="password"
                                value={supervisorPassword}
                                placeholder="Supervisor Password"
                                onChange={(e) => setSupervisorPassword(e.target.value)}
                                className="text-xs border border-slate-300 rounded-xl p-2.5"
                            />
                        </div>
                    </div>
                </div>

                {/* Footer Actions */}
                <div className="px-6 py-5 border-t border-slate-100 flex items-center justify-end gap-3 bg-slate-50/50">
                    <button 
                        onClick={onClose}
                        disabled={loading}
                        className="px-4 py-2 text-xs font-bold text-slate-500 hover:bg-slate-200 rounded-xl transition-all disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button 
                        onClick={mode === 'void' ? handleVoid : handleRefund}
                        disabled={loading || success || isBlocked}
                        className="px-6 py-2.5 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 active:scale-95 rounded-xl transition-all shadow-md shadow-indigo-600/10 flex items-center gap-2 disabled:opacity-50 disabled:pointer-events-none"
                    >
                        {loading && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                        {mode === 'void' ? 'Void Transaction' : 'Process Refund'}
                    </button>
                </div>
            </div>
        </div>
    );
}
