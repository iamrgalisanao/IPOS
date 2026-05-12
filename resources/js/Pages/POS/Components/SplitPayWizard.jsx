import React, { useState, useMemo } from 'react';
import { X, Plus, Trash2, CreditCard, Banknote, AlertCircle, CheckCircle2, Loader2, Wallet } from 'lucide-react';
import { calculatePaymentTotals, calculatePaymentProgress, validatePaymentRows, buildSplitPaymentPayload, requiresReference, isCashPayment, calculateCashChange } from '../helpers/splitPaymentHelper';

export default function SplitPayWizard({ sale, paymentMethods = [], onClose, onPaymentRecorded, tenantId, branchId }) {
    const hasPaymentMethods = paymentMethods.length > 0;
    const [rows, setRows] = useState([
        { id: crypto.randomUUID(), payment_method_id: '', amount: sale.total, amount_tendered: '', reference_number: '' }
    ]);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState(null);

    const totals = useMemo(() => calculatePaymentTotals(rows, sale.total), [rows, sale.total]);
    const progress = useMemo(() => calculatePaymentProgress(rows, paymentMethods, sale.total), [rows, paymentMethods, sale.total]);
    const validation = useMemo(() => validatePaymentRows(rows, paymentMethods, sale.total), [rows, paymentMethods, sale.total]);

    const addRow = () => {
        const remaining = progress.remainingBalance;
        if (remaining <= 0) return;

        const normalizedRows = rows.map(row => {
            const method = paymentMethods.find(m => m.id === row.payment_method_id);
            const amount = Number(row.amount) || 0;
            const tendered = Number(row.amount_tendered) || 0;

            if (isCashPayment(method) && tendered > 0 && tendered < amount) {
                return { ...row, amount: tendered.toFixed(2) };
            }

            return row;
        });

        setRows([...normalizedRows, {
            id: crypto.randomUUID(), 
            payment_method_id: '', 
            amount: remaining.toFixed(2), 
            amount_tendered: '',
            reference_number: '' 
        }]);
    };

    const removeRow = (id) => {
        if (rows.length <= 1) return;
        setRows(rows.filter(row => row.id !== id));
    };

    const updateRow = (id, updates) => {
        setRows(rows.map(row => row.id === id ? { ...row, ...updates } : row));
    };

    const handleSubmit = async () => {
        if (!validation.isValid || isSubmitting) return;

        setIsSubmitting(true);
        setError(null);

        try {
            const payload = buildSplitPaymentPayload(rows);
            const response = await fetch(`/pos/sales/${sale.id}/payments/split`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Tenant-ID': tenantId,
                    'X-Branch-ID': branchId,
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (!response.ok) {
                setError(data.message || 'Payment recording failed.');
                return;
            }

            onPaymentRecorded({ ...data, rows });
        } catch (err) {
            setError('A network error occurred. Please try again.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-in fade-in duration-200">
            <div className="bg-slate-900 border border-slate-800 w-full max-w-3xl rounded-2xl shadow-2xl flex flex-col max-h-[90vh] overflow-hidden">
                {/* Header */}
                <div className="p-6 border-b border-slate-800 flex items-center justify-between bg-slate-900/50">
                    <div>
                        <h2 className="text-xl font-bold text-white flex items-center gap-2">
                            <CreditCard className="w-5 h-5 text-indigo-400" />
                            Split Payment Wizard
                        </h2>
                        <p className="text-slate-400 text-sm mt-1">Sale ID: {sale.id.substring(0, 8)}...</p>
                    </div>
                    <button onClick={onClose} className="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-colors">
                        <X className="w-6 h-6" />
                    </button>
                </div>

                {/* Main Content */}
                <div className="flex-1 overflow-y-auto p-6 space-y-6">
                    {/* Summary Cards */}
                    <div className="grid grid-cols-3 gap-4">
                        <div className="bg-slate-800/50 p-4 rounded-xl border border-slate-700/50">
                            <p className="text-slate-400 text-xs font-medium uppercase tracking-wider">Sale Total</p>
                            <p className="text-lg font-bold text-white mt-1">₱{Number(sale.total).toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                        </div>
                        <div className="bg-slate-800/50 p-4 rounded-xl border border-slate-700/50">
                            <p className="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Paid</p>
                            <p className={`text-lg font-bold mt-1 ${totals.isOverpaid ? 'text-rose-400' : 'text-emerald-400'}`}>
                                ₱{progress.totalPaid.toLocaleString(undefined, {minimumFractionDigits: 2})}
                            </p>
                        </div>
                        <div className={`p-4 rounded-xl border transition-colors ${progress.remainingBalance > 0 ? 'bg-indigo-500/10 border-indigo-500/20' : 'bg-emerald-500/10 border-emerald-500/20'}`}>
                            <p className="text-slate-400 text-xs font-medium uppercase tracking-wider">Remaining</p>
                            <p className={`text-lg font-bold mt-1 ${progress.remainingBalance > 0 ? 'text-indigo-400' : 'text-emerald-400'}`}>
                                ₱{progress.remainingBalance.toLocaleString(undefined, {minimumFractionDigits: 2})}
                            </p>
                        </div>
                    </div>

                    {/* Error Messages */}
                    {error && (
                        <div className="bg-rose-500/10 border border-rose-500/20 p-4 rounded-xl flex items-start gap-3">
                            <AlertCircle className="w-5 h-5 text-rose-400 shrink-0 mt-0.5" />
                            <p className="text-sm text-rose-200">{error}</p>
                        </div>
                    )}

                    {/* Payment Rows */}
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-slate-300 uppercase tracking-widest">Payment Methods</h3>
                            <button 
                                onClick={addRow}
                                disabled={!hasPaymentMethods || progress.remainingBalance <= 0}
                                className="flex items-center gap-2 text-xs font-bold text-indigo-400 hover:text-indigo-300 disabled:opacity-50 disabled:cursor-not-allowed py-1 px-2 rounded-lg hover:bg-indigo-400/10 transition-all"
                            >
                                <Plus className="w-4 h-4" />
                                Add Method
                            </button>
                        </div>

                        {!hasPaymentMethods && (
                            <div className="bg-amber-500/10 border border-amber-500/20 p-4 rounded-xl flex items-start gap-3">
                                <AlertCircle className="w-5 h-5 text-amber-300 shrink-0 mt-0.5" />
                                <div>
                                    <p className="text-sm font-semibold text-amber-100">No active payment methods configured.</p>
                                    <p className="text-xs text-amber-100/70 mt-1">Reload the POS after payment methods are configured for this tenant.</p>
                                </div>
                            </div>
                        )}

                        <div className="space-y-3">
                            {rows.map((row, index) => {
                                const method = paymentMethods.find(m => m.id === row.payment_method_id);
                                const isRefRequired = requiresReference(method);
                                const isRefMissing = isRefRequired && !(row.reference_number || '').trim();
                                const isCash = isCashPayment(method);
                                const changeDue = isCash ? calculateCashChange(row) : 0;

                                return (
                                    <div key={row.id} className="bg-slate-800/30 border border-slate-700/50 p-4 rounded-xl animate-in slide-in-from-left-2 duration-200">
                                        <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
                                            {/* Method Selection */}
                                            <div className="md:col-span-3">
                                                <label className="text-[10px] font-bold text-slate-500 uppercase mb-1.5 block">Method</label>
                                                <select 
                                                    value={row.payment_method_id}
                                                    onChange={(e) => updateRow(row.id, { payment_method_id: e.target.value })}
                                                    disabled={!hasPaymentMethods}
                                                    className="w-full bg-slate-900 border border-slate-700 rounded-lg py-2.5 px-3 text-sm focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 outline-none transition-all text-white"
                                                >
                                                    <option value="">Select Method</option>
                                                    {paymentMethods.map(m => (
                                                        <option key={m.id} value={m.id}>{m.name}</option>
                                                    ))}
                                                </select>
                                            </div>

                                            {/* Amount Input */}
                                            <div className="md:col-span-3">
                                                <label className="text-[10px] font-bold text-slate-500 uppercase mb-1.5 block">Amount Due</label>
                                                <div className="relative">
                                                    <span className="absolute left-3 top-2.5 text-slate-500 text-sm">₱</span>
                                                    <input 
                                                        type="number"
                                                        step="0.01"
                                                        value={row.amount}
                                                        onChange={(e) => updateRow(row.id, { amount: e.target.value })}
                                                        className="w-full bg-slate-900 border border-slate-700 rounded-lg py-2.5 pl-7 pr-3 text-sm focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 outline-none transition-all text-white font-mono"
                                                    />
                                                </div>
                                            </div>

                                            {/* Cash Specific: Tendered */}
                                            {isCash && (
                                                <div className="md:col-span-3 animate-in zoom-in-95 duration-200">
                                                    <label className="text-[10px] font-bold text-emerald-400 uppercase mb-1.5 block">Cash Tendered</label>
                                                    <div className="relative">
                                                        <span className="absolute left-3 top-2.5 text-emerald-500 text-sm">₱</span>
                                                        <input 
                                                            type="number"
                                                            step="0.01"
                                                            placeholder="0.00"
                                                            value={row.amount_tendered}
                                                            onChange={(e) => updateRow(row.id, { amount_tendered: e.target.value })}
                                                            className="w-full bg-slate-900 border border-emerald-500/30 rounded-lg py-2.5 pl-7 pr-3 text-sm focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 outline-none transition-all text-white font-mono"
                                                        />
                                                    </div>
                                                </div>
                                            )}

                                            {/* Reference Input (Guard) */}
                                            {isRefRequired && (
                                                <div className="md:col-span-5 animate-in zoom-in-95 duration-200">
                                                    <label className={`text-[10px] font-bold uppercase mb-1.5 block ${isRefMissing ? 'text-rose-400' : 'text-indigo-300'}`}>Reference #</label>
                                                    <input 
                                                        type="text"
                                                        placeholder="Enter reference..."
                                                        value={row.reference_number}
                                                        onChange={(e) => updateRow(row.id, { reference_number: e.target.value })}
                                                        className={`w-full bg-slate-900 border rounded-lg py-2.5 px-3 text-sm focus:ring-2 outline-none transition-all text-white ${
                                                            isRefMissing
                                                                ? 'border-rose-500/30 focus:ring-rose-500/50 focus:border-rose-500'
                                                                : 'border-slate-700 focus:ring-indigo-500/50 focus:border-indigo-500'
                                                        }`}
                                                    />
                                                </div>
                                            )}

                                            {/* Change Due Display */}
                                            {isCash && (
                                                <div className="md:col-span-2 flex flex-col justify-center animate-in slide-in-from-right-2">
                                                    <label className="text-[10px] font-bold text-indigo-400 uppercase mb-1 block text-right">Change</label>
                                                    <p className={`text-lg font-black font-mono text-right ${changeDue > 0 ? 'text-white' : 'text-slate-600'}`}>
                                                        ₱{changeDue.toLocaleString(undefined, {minimumFractionDigits: 2})}
                                                    </p>
                                                </div>
                                            )}

                                            {/* Actions */}
                                            <div className="md:col-span-1 flex items-center justify-end">
                                                <button 
                                                    onClick={() => removeRow(row.id)}
                                                    disabled={rows.length <= 1}
                                                    className="p-2.5 text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition-all disabled:opacity-20"
                                                >
                                                    <Trash2 className="w-5 h-5" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* Footer */}
                <div className="p-6 border-t border-slate-800 bg-slate-900/80 backdrop-blur-md">
                    <button 
                        onClick={handleSubmit}
                        disabled={!hasPaymentMethods || !validation.isValid || isSubmitting}
                        className={`w-full py-4 rounded-xl font-bold flex items-center justify-center gap-3 transition-all ${
                            hasPaymentMethods && validation.isValid && !isSubmitting
                            ? 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-500/20 active:scale-[0.98]' 
                            : 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50'
                        }`}
                    >
                        {isSubmitting ? (
                            <>
                                <Loader2 className="w-5 h-5 animate-spin" />
                                Processing...
                            </>
                        ) : (
                            <>
                                <CheckCircle2 className="w-5 h-5" />
                                Complete Payment (₱{totals.totalPaid.toLocaleString(undefined, {minimumFractionDigits: 2})})
                            </>
                        )}
                    </button>
                    {!hasPaymentMethods && (
                        <p className="text-[10px] text-center text-amber-300 mt-3 uppercase tracking-widest font-medium">
                            Configure at least one active payment method to record payment.
                        </p>
                    )}
                    {hasPaymentMethods && !validation.isValid && validation.errors.length > 0 && (
                        <p className="text-[10px] text-center text-rose-400 mt-3 uppercase tracking-widest font-medium">
                            {validation.errors[0]}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
