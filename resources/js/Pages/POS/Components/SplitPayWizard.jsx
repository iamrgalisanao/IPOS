import React, { useEffect, useState, useMemo } from 'react';
import { X, Plus, Trash2, CreditCard, Banknote, AlertCircle, CheckCircle2, Loader2, Wallet, Landmark, Layers } from 'lucide-react';
import { calculatePaymentTotals, calculatePaymentProgress, validatePaymentRows, buildSplitPaymentPayload, requiresReference, isCashPayment, calculateCashChange } from '../helpers/splitPaymentHelper';
import { isOffline } from '@/POS/offline/offlineGuards';

// Helper to map DB payment methods to one of the 5 standard categories
const getMappedMethod = (methodNameOrCode) => {
    const text = String(methodNameOrCode || '').toLowerCase();
    if (text.includes('cash')) return 'cash';
    if (text.includes('gcash') || text.includes('wallet') || text.includes('paymaya') || text.includes('maya')) return 'gcash';
    if (text.includes('card') || text.includes('credit') || text.includes('debit') || text.includes('visa') || text.includes('mastercard')) return 'card';
    if (text.includes('bank') || text.includes('transfer') || text.includes('wire') || text.includes('bdo') || text.includes('bpi')) return 'bank';
    return 'other';
};

const BUTTONS = [
    { key: 'cash', icon: Banknote, label: 'Cash', color: 'text-emerald-400' },
    { key: 'gcash', icon: Wallet, label: 'GCash', color: 'text-sky-400' },
    { key: 'card', icon: CreditCard, label: 'Card', color: 'text-indigo-400' },
    { key: 'bank', icon: Landmark, label: 'Bank Transfer', color: 'text-amber-400' },
    { key: 'other', icon: Layers, label: 'Other', color: 'text-slate-400' }
];

export default function SplitPayWizard({ sale, paymentMethods = [], onClose, onPaymentRecorded, tenantId, branchId, initialRows = null, onRowsChange = null, shift = null, isAdmin = false }) {
    const hasPaymentMethods = paymentMethods.length > 0;
    const hasNoActiveShift = !isAdmin && !shift;
    const [rows, setRows] = useState(() => initialRows && initialRows.length > 0
        ? initialRows
        : [{ id: crypto.randomUUID(), payment_method_id: '', amount: sale.total, amount_tendered: '', reference_number: '' }]);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [activeField, setActiveField] = useState({ rowId: null, fieldName: null });

    // Calculate interactive payment progress using refined cashier rules
    const getRowProgressDetails = (row) => {
        if (!row.payment_method_id) return { isComplete: false, paidAmount: 0 };
        
        const method = paymentMethods.find(m => m.id === row.payment_method_id);
        const amount = Number(row.amount) || 0;
        
        if (amount <= 0) return { isComplete: false, paidAmount: 0 };

        if (isCashPayment(method)) {
            const tendered = Number(row.amount_tendered) || 0;
            if (tendered <= 0) {
                return { isComplete: false, paidAmount: 0 };
            }
            if (tendered < amount) {
                return { isComplete: false, paidAmount: tendered }; // partial paid
            }
            return { isComplete: true, paidAmount: amount };
        }

        if (requiresReference(method)) {
            const ref = (row.reference_number || '').trim();
            if (!ref) {
                return { isComplete: false, paidAmount: 0 };
            }
        }

        return { isComplete: true, paidAmount: amount };
    };

    const { totalPaid, remainingBalance } = useMemo(() => {
        let sum = 0;
        rows.forEach(row => {
            const details = getRowProgressDetails(row);
            sum += details.paidAmount;
        });
        const remaining = Math.max(0, Number(sale.total) - sum);
        return {
            totalPaid: Number(sum.toFixed(4)),
            remainingBalance: Number(remaining.toFixed(4))
        };
    }, [rows, paymentMethods, sale.total]);

    const totals = useMemo(() => calculatePaymentTotals(rows, sale.total), [rows, sale.total]);
    
    // Validate rows in compliance with backend expectations
    const validation = useMemo(() => validatePaymentRows(rows, paymentMethods, sale.total), [rows, paymentMethods, sale.total]);

    useEffect(() => {
        if (initialRows && initialRows.length > 0) {
            setRows(initialRows);
        }
    }, [initialRows]);

    useEffect(() => {
        onRowsChange?.(rows);
    }, [rows, onRowsChange]);

    const addRow = () => {
        const remaining = remainingBalance;
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

        if (isOffline()) {
            setError('Payment cannot be recorded while offline. Reconnect to finalize official sale.');
            return;
        }

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

    // Real-time field level validation (Requirement 5)
    const getFieldErrors = (row) => {
        const errors = {};
        if (!row.payment_method_id) return errors;

        const method = paymentMethods.find(m => m.id === row.payment_method_id);
        const amount = Number(row.amount) || 0;

        if (row.amount !== '' && amount <= 0) {
            errors.amount = "Amount must be a positive number.";
        }

        if (isCashPayment(method)) {
            const tendered = Number(row.amount_tendered) || 0;
            if (row.amount_tendered !== '' && tendered <= 0) {
                errors.amount_tendered = "Please enter cash tendered.";
            } else if (row.amount_tendered !== '' && tendered < amount) {
                errors.amount_tendered = "Cash tendered is less than the required amount.";
            }
        }

        if (requiresReference(method)) {
            const ref = (row.reference_number || '').trim();
            if (row.reference_number !== '' && !ref) {
                errors.reference_number = `Reference number is required for ${method.name}.`;
            }
        }

        return errors;
    };

    const getCategoryMethods = (key) => {
        return paymentMethods.filter(m => getMappedMethod(m.code || m.name) === key);
    };

    // Dynamic Quick Cash Bill Denominations based on current amount (Requirement 6)
    const getQuickCashOptions = (amount) => {
        const options = [];
        options.push({ label: 'Exact', value: amount });
        
        const standardBills = [50, 100, 200, 300, 500, 1000];
        standardBills.forEach(bill => {
            if (bill > amount && bill - amount < 1000) {
                options.push({ label: `₱${bill}`, value: bill });
            }
        });

        // Fallbacks if not already appended
        if (!options.some(o => o.value === 500) && amount < 500) {
            options.push({ label: '₱500', value: 500 });
        }
        if (!options.some(o => o.value === 1000) && amount < 1000) {
            options.push({ label: '₱1,000', value: 1000 });
        }

        return options;
    };

    const getRemainingAfterCurrent = (row) => {
        const otherPaid = rows.filter(r => r.id !== row.id).reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
        return Math.max(0, Number(sale.total) - otherPaid - (Number(row.amount) || 0));
    };

    const handleKeypadPress = (key) => {
        if (!activeField.rowId || !activeField.fieldName) return;
        
        const row = rows.find(r => r.id === activeField.rowId);
        if (!row) return;

        let currentValue = String(row[activeField.fieldName] || '');

        if (key === 'clear') {
            currentValue = '';
        } else if (key === 'backspace') {
            currentValue = currentValue.slice(0, -1);
        } else if (key === '00') {
            if (currentValue && !currentValue.includes('.')) {
                currentValue += '00';
            } else if (currentValue.includes('.')) {
                currentValue += '0';
            }
        } else if (key === '.') {
            if (!currentValue.includes('.')) {
                if (currentValue === '') {
                    currentValue = '0.';
                } else {
                    currentValue += '.';
                }
            }
        } else if (key === 'exact') {
            if (activeField.fieldName === 'amount') {
                const otherPaid = rows.filter(r => r.id !== row.id).reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
                const trueRemaining = Math.max(0, Number(sale.total) - otherPaid);
                currentValue = trueRemaining.toFixed(2);
            } else if (activeField.fieldName === 'amount_tendered') {
                currentValue = Number(row.amount || 0).toFixed(2);
            }
        } else {
            if (currentValue === '0') {
                currentValue = String(key);
            } else {
                currentValue += String(key);
            }
        }

        updateRow(row.id, { [activeField.fieldName]: currentValue });
    };

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-3 sm:p-4 bg-slate-950/85 backdrop-blur-sm animate-in fade-in duration-150">
            <style>{`
                .no-spinner::-webkit-outer-spin-button,
                .no-spinner::-webkit-inner-spin-button {
                    -webkit-appearance: none;
                    margin: 0;
                }
                .no-spinner {
                    -moz-appearance: textfield;
                }
            `}</style>
            <div className={`bg-slate-900 border border-slate-800/80 w-full rounded-2xl shadow-2xl flex flex-col max-h-[96vh] sm:max-h-[92vh] overflow-hidden transition-all duration-300 ${
                activeField.rowId ? 'max-w-4xl' : 'max-w-2xl'
            }`}>
                
                {/* Denser Header (Requirement 7) */}
                <div className="py-3 px-5 border-b border-slate-800/80 flex items-center justify-between bg-slate-900/50">
                    <div>
                        <h2 className="text-base font-black text-white flex items-center gap-2 tracking-wide">
                            <CreditCard className="w-4.5 h-4.5 text-indigo-400" />
                            SPLIT PAYMENT WIZARD
                        </h2>
                        <p className="text-[10px] text-slate-500 font-bold uppercase mt-0.5 tracking-wider">Sale ID: {sale.id.substring(0, 8)}...</p>
                    </div>
                    <button onClick={onClose} className="p-1.5 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <X className="w-4.5 h-4.5" />
                    </button>
                </div>

                {/* Main Content Body */}
                <div className="flex-1 overflow-y-auto p-4 space-y-4">
                    
                    {/* Primary Visual Focus Hierarchy: Remaining on top (Requirement 1, 8) */}
                    <div className="space-y-2">
                        {/* Massive Remaining Card */}
                        <div className={`p-4.5 rounded-2xl border transition-all duration-300 ${
                            remainingBalance > 0.001 
                                ? 'bg-indigo-500/10 border-indigo-500/30 shadow-[0_0_25px_rgba(99,102,241,0.06)]' 
                                : 'bg-emerald-500/10 border-emerald-500/30 shadow-[0_0_25px_rgba(16,185,129,0.06)]'
                        }`}>
                            <p className="text-slate-400 text-[9px] font-black uppercase tracking-widest text-center">Remaining Balance to Pay</p>
                            <p className={`text-3xl font-black mt-1 text-center tracking-tight ${remainingBalance > 0.001 ? 'text-indigo-400' : 'text-emerald-400'}`}>
                                ₱{remainingBalance.toLocaleString(undefined, {minimumFractionDigits: 2})}
                            </p>
                        </div>

                        {/* Secondary Summary Cards */}
                        <div className="grid grid-cols-2 gap-2.5">
                            <div className="p-2.5 rounded-xl bg-slate-800/30 border border-slate-800/80 text-center">
                                <p className="text-slate-500 text-[9px] font-bold uppercase tracking-wider">Sale Total</p>
                                <p className="text-sm font-bold text-white mt-0.5">₱{Number(sale.total).toLocaleString(undefined, {minimumFractionDigits: 2})}</p>
                            </div>
                            <div className="p-2.5 rounded-xl bg-slate-800/30 border border-slate-800/80 text-center">
                                <p className="text-slate-500 text-[9px] font-bold uppercase tracking-wider">Total Paid</p>
                                <p className={`text-sm font-bold mt-0.5 ${totals.isOverpaid ? 'text-rose-400' : 'text-emerald-400'}`}>
                                    ₱{totalPaid.toLocaleString(undefined, {minimumFractionDigits: 2})}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Global Error Banner */}
                    {error && (
                        <div className="bg-rose-500/10 border border-rose-500/20 py-2.5 px-4 rounded-xl flex items-start gap-2.5">
                            <AlertCircle className="w-4 h-4 text-rose-400 shrink-0 mt-0.5" />
                            <p className="text-xs text-rose-200">{error}</p>
                        </div>
                    )}
                    
                    {/* Split Payments Section (Requirement 6) */}
                    <div className="space-y-3.5">
                        <div className="flex items-center justify-between border-b border-slate-800/60 pb-1.5">
                            <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Split Payments</h3>
                        </div>

                        {!hasPaymentMethods && (
                            <div className="bg-amber-500/10 border border-amber-500/20 p-4 rounded-xl flex items-start gap-3">
                                <AlertCircle className="w-5 h-5 text-amber-300 shrink-0 mt-0.5" />
                                <div>
                                    <p className="text-sm font-semibold text-amber-100">No active payment methods configured.</p>
                                    <p className="text-xs text-amber-100/70 mt-1">Configure payment methods in tenant settings.</p>
                                </div>
                            </div>
                        )}

                        <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
                            {/* Left Column: Payments List */}
                            <div className={`space-y-3.5 transition-all duration-300 ${activeField.rowId ? 'md:col-span-7' : 'md:col-span-12'}`}>
                                {rows.map((row, index) => {
                                    const method = paymentMethods.find(m => m.id === row.payment_method_id);
                                    const isRefRequired = requiresReference(method);
                                    const isCash = isCashPayment(method);
                                    const changeDue = isCash ? calculateCashChange(row) : 0;
                                    const fieldErrors = getFieldErrors(row);

                                    const matchedKey = method ? getMappedMethod(method.code || method.name) : '';
                                    const selectedBtn = BUTTONS.find(b => b.key === matchedKey);
                                    const categoryMatches = method ? getCategoryMethods(matchedKey) : [];

                                    return (
                                        <div key={row.id} className="animate-in slide-in-from-left-2 duration-150">
                                            
                                            {/* State A: Method Selection Grid with Clearer States (Requirement 2, 3, 4) */}
                                            {!row.payment_method_id ? (
                                                <div className="bg-slate-850/30 border border-slate-800/80 p-4 rounded-2xl">
                                                    <div className="flex items-center justify-between mb-2.5">
                                                        <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                                            Payment #{index + 1} — Choose payment method
                                                        </label>
                                                        {rows.length > 1 && (
                                                            <button 
                                                                type="button"
                                                                onClick={() => removeRow(row.id)}
                                                                className="text-slate-500 hover:text-rose-400 transition-all p-1"
                                                            >
                                                                <Trash2 className="w-3.5 h-3.5" />
                                                            </button>
                                                        )}
                                                    </div>

                                                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                                        {BUTTONS.map(btn => {
                                                            const isAvailable = paymentMethods.some(m => getMappedMethod(m.code || m.name) === btn.key);
                                                            const Icon = btn.icon;

                                                            return (
                                                                <button
                                                                    key={btn.key}
                                                                    type="button"
                                                                    disabled={!isAvailable}
                                                                    onClick={() => {
                                                                        const matchingMethods = getCategoryMethods(btn.key);
                                                                        if (matchingMethods.length > 0) {
                                                                            updateRow(row.id, { 
                                                                                payment_method_id: matchingMethods[0].id,
                                                                                amount: rows.length === 1 ? sale.total : getRemainingAfterCurrent(row).toFixed(2)
                                                                            });
                                                                        }
                                                                    }}
                                                                    className={`flex flex-col items-center justify-center p-3.5 rounded-xl border transition-all relative ${
                                                                        isAvailable
                                                                            ? 'bg-slate-800/40 hover:bg-slate-800 border-slate-700/60 hover:border-indigo-500/40 text-slate-200 active:scale-95 cursor-pointer'
                                                                            : 'bg-slate-900/40 border-slate-800/50 text-slate-600 cursor-not-allowed opacity-40'
                                                                    }`}
                                                                >
                                                                    <Icon className={`w-5 h-5 mb-1.5 ${isAvailable ? 'text-indigo-400' : 'text-slate-600'}`} />
                                                                    <span className="text-[10px] font-black uppercase tracking-wider">{btn.label}</span>
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            ) : (
                                                /* State B: Active Payment Card (Requirement 5) */
                                                <div className="bg-slate-800/35 border border-white/5 p-3.5 rounded-2xl relative shadow-lg">
                                                    
                                                    {/* Card Header & Details */}
                                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800/80 pb-2.5 mb-3">
                                                        <div className="flex items-center gap-2">
                                                            <div className="w-7 h-7 rounded-lg bg-indigo-500/10 flex items-center justify-center border border-indigo-500/20 shrink-0">
                                                                {selectedBtn ? (
                                                                    React.createElement(selectedBtn.icon, { className: "w-4 h-4 text-indigo-400" })
                                                                ) : (
                                                                    <CreditCard className="w-4 h-4 text-indigo-400" />
                                                                )}
                                                            </div>
                                                            <div>
                                                                <h4 className="text-[10px] font-black text-white uppercase tracking-wider">
                                                                    Payment #{index + 1} &mdash; {method ? method.name : 'Unknown'}
                                                                </h4>
                                                                <p className="text-[9px] font-bold text-slate-500 uppercase tracking-widest">
                                                                    Category: {selectedBtn ? selectedBtn.label : 'Other'}
                                                                </p>
                                                            </div>
                                                        </div>

                                                        {/* Sub-method Toggles for Grouped Channels (Requirement 5) */}
                                                        <div className="flex items-center gap-2 shrink-0">
                                                            {categoryMatches.length > 1 && (
                                                                <div className="flex bg-slate-900/60 p-0.5 rounded-lg border border-slate-800">
                                                                    {categoryMatches.map(m => (
                                                                        <button
                                                                            key={m.id}
                                                                            type="button"
                                                                            onClick={() => updateRow(row.id, { payment_method_id: m.id })}
                                                                            className={`px-2 py-0.5 text-[8px] font-black rounded uppercase tracking-wider transition-all ${
                                                                                row.payment_method_id === m.id
                                                                                    ? 'bg-indigo-600 text-white shadow shadow-indigo-600/30'
                                                                                    : 'text-slate-400 hover:text-slate-200'
                                                                            }`}
                                                                        >
                                                                            {m.name}
                                                                        </button>
                                                                    ))}
                                                                </div>
                                                            )}
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    updateRow(row.id, { payment_method_id: '', amount_tendered: '', reference_number: '' });
                                                                    if (activeField.rowId === row.id) {
                                                                        setActiveField({ rowId: null, fieldName: null });
                                                                    }
                                                                }}
                                                                className="px-2 py-1 text-[9px] font-bold text-indigo-400 hover:text-indigo-300 hover:bg-indigo-500/10 rounded-md border border-indigo-500/20 transition-all active:scale-95"
                                                            >
                                                                Change Method
                                                            </button>
                                                        </div>
                                                    </div>

                                                    {/* Inputs layout stack */}
                                                    <div className="space-y-3">
                                                        <div className="grid grid-cols-1 sm:grid-cols-12 gap-3.5 items-start">
                                                            
                                                            {/* Payment Amount Input */}
                                                            <div className="sm:col-span-6">
                                                                <label className="text-[9px] font-black text-slate-400 uppercase mb-1 block">Payment Amount</label>
                                                                <div className="relative flex items-center">
                                                                    <span className="absolute left-3 text-slate-400 text-xs font-bold">₱</span>
                                                                    <input 
                                                                        type="number"
                                                                        step="0.01"
                                                                        placeholder="0.00"
                                                                        value={row.amount}
                                                                        onFocus={() => setActiveField({ rowId: row.id, fieldName: 'amount' })}
                                                                        onClick={() => setActiveField({ rowId: row.id, fieldName: 'amount' })}
                                                                        onChange={(e) => updateRow(row.id, { amount: e.target.value })}
                                                                        className={`w-full bg-slate-900 border rounded-xl py-2.5 pl-7 pr-24 text-xs focus:ring-2 outline-none transition-all text-white font-mono no-spinner ${
                                                                            activeField.rowId === row.id && activeField.fieldName === 'amount'
                                                                                ? 'border-indigo-500 ring-2 ring-indigo-500/30 bg-indigo-950/20 shadow-md shadow-indigo-500/5'
                                                                                : fieldErrors.amount 
                                                                                    ? 'border-rose-500/40 focus:ring-rose-500/30' 
                                                                                    : 'border-slate-800 focus:ring-indigo-500/50'
                                                                        }`}
                                                                    />
                                                                    {/* Pay Remaining Shortcut (Requirement 7) */}
                                                                    {remainingBalance > 0.001 && Math.abs(Number(row.amount) - (remainingBalance + (Number(row.amount) || 0))) > 0.01 && (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => {
                                                                                const otherPaid = rows.filter(r => r.id !== row.id).reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
                                                                                const trueRemaining = Math.max(0, Number(sale.total) - otherPaid);
                                                                                updateRow(row.id, { amount: trueRemaining.toFixed(2) });
                                                                            }}
                                                                            className="absolute right-1.5 px-2 py-1 bg-indigo-600/15 hover:bg-indigo-600 text-indigo-400 hover:text-white text-[9px] font-black rounded-lg transition-all"
                                                                        >
                                                                            Pay Remaining
                                                                        </button>
                                                                    )}
                                                                </div>
                                                                <p className="text-[10px] text-indigo-400 font-medium mt-1.5 flex items-center gap-1.5">
                                                                    <span>Remaining after this payment:</span>
                                                                    <span className="font-bold font-mono">₱{getRemainingAfterCurrent(row).toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                                                                </p>
                                                                {fieldErrors.amount && (
                                                                    <span className="text-[9px] text-rose-400 font-bold mt-1 flex items-center gap-1">
                                                                        <AlertCircle className="w-3 h-3 shrink-0" />
                                                                        {fieldErrors.amount}
                                                                    </span>
                                                                )}
                                                            </div>

                                                            {/* Cash Tendered & Change Area for Cash (Requirement 6) */}
                                                            {isCash && (
                                                                <div className="sm:col-span-6 space-y-2.5 animate-in zoom-in-95 duration-100">
                                                                    <div>
                                                                        <label className="text-[9px] font-black text-emerald-400 uppercase mb-1 block">Cash Tendered</label>
                                                                        <div className="relative">
                                                                            <span className="absolute left-3 text-emerald-500 text-xs">₱</span>
                                                                            <input 
                                                                                type="number"
                                                                                step="0.01"
                                                                                placeholder="0.00"
                                                                                value={row.amount_tendered}
                                                                                onFocus={() => setActiveField({ rowId: row.id, fieldName: 'amount_tendered' })}
                                                                                onClick={() => setActiveField({ rowId: row.id, fieldName: 'amount_tendered' })}
                                                                                onChange={(e) => updateRow(row.id, { amount_tendered: e.target.value })}
                                                                                className={`w-full bg-slate-900 border rounded-xl py-2.5 pl-7 pr-3 text-xs focus:ring-2 outline-none transition-all text-white font-mono no-spinner ${
                                                                                    activeField.rowId === row.id && activeField.fieldName === 'amount_tendered'
                                                                                        ? 'border-emerald-500 ring-2 ring-emerald-500/30 bg-emerald-950/20 shadow-md shadow-emerald-500/5'
                                                                                        : fieldErrors.amount_tendered 
                                                                                            ? 'border-rose-500/40 focus:ring-rose-500/30' 
                                                                                            : 'border-emerald-500/20 focus:ring-emerald-500/50'
                                                                                }`}
                                                                            />
                                                                        </div>
                                                                        {fieldErrors.amount_tendered && (
                                                                            <span className="text-[9px] text-rose-400 font-bold mt-1 flex items-center gap-1">
                                                                                <AlertCircle className="w-3 h-3 shrink-0" />
                                                                                {fieldErrors.amount_tendered}
                                                                            </span>
                                                                        )}
                                                                    </div>

                                                                    {/* Quick Cash Touch Targets (Requirement 6) */}
                                                                    <div className="grid grid-cols-4 gap-1.5">
                                                                        {getQuickCashOptions(Number(row.amount) || 0).map(opt => (
                                                                            <button
                                                                                key={opt.label}
                                                                                type="button"
                                                                                onClick={() => updateRow(row.id, { amount_tendered: opt.value.toFixed(2) })}
                                                                                className="h-12 flex items-center justify-center bg-slate-900 hover:bg-slate-800 border border-slate-800 hover:border-slate-600 rounded-xl text-[9px] font-black uppercase text-slate-300 hover:text-white transition-all active:scale-95"
                                                                            >
                                                                                {opt.label}
                                                                            </button>
                                                                        ))}
                                                                    </div>

                                                                    {/* Change Display */}
                                                                    <div className="pt-1.5 border-t border-slate-800/80 flex justify-between items-center">
                                                                        <span className="text-[9px] font-black text-slate-500 uppercase">Change Due</span>
                                                                        <span className={`text-sm font-black font-mono ${changeDue > 0 ? 'text-emerald-400' : 'text-slate-400'}`}>
                                                                            ₱{changeDue.toLocaleString(undefined, {minimumFractionDigits: 2})}
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            )}

                                                            {/* Reference Input for Digital Payments (Requirement 7) */}
                                                            {isRefRequired && (
                                                                <div className="sm:col-span-6 animate-in zoom-in-95 duration-100">
                                                                        <label className="text-[9px] font-black text-slate-400 uppercase mb-1 block">Reference #</label>
                                                                        <input 
                                                                            type="text"
                                                                            placeholder="Enter payment reference..."
                                                                            value={row.reference_number}
                                                                            onChange={(e) => updateRow(row.id, { reference_number: e.target.value })}
                                                                            className={`w-full bg-slate-900 border rounded-xl py-2.5 px-3 text-xs focus:ring-2 outline-none transition-all text-white ${
                                                                                fieldErrors.reference_number 
                                                                                    ? 'border-rose-500/40 focus:ring-rose-500/30' 
                                                                                    : 'border-slate-800 focus:ring-indigo-500/50'
                                                                            }`}
                                                                        />
                                                                        {fieldErrors.reference_number && (
                                                                            <span className="text-[9px] text-rose-400 font-bold mt-1 flex items-center gap-1">
                                                                                <AlertCircle className="w-3 h-3 shrink-0" />
                                                                                {fieldErrors.reference_number}
                                                                            </span>
                                                                        )}
                                                                </div>
                                                            )}
                                                        </div>

                                                        {/* Trash Action Pin */}
                                                        {rows.length > 1 && (
                                                            <div className="flex justify-end pt-1 border-t border-slate-800/20">
                                                                <button 
                                                                    type="button"
                                                                    onClick={() => {
                                                                        removeRow(row.id);
                                                                        if (activeField.rowId === row.id) {
                                                                            setActiveField({ rowId: null, fieldName: null });
                                                                        }
                                                                    }}
                                                                    className="flex items-center gap-1 text-[10px] font-bold text-slate-500 hover:text-rose-400 py-1 transition-all"
                                                                >
                                                                    <Trash2 className="w-3.5 h-3.5" />
                                                                    Remove payment
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>

                            {/* Right Column: Numeric Keypad Panel */}
                            {activeField.rowId && (
                                <div className="md:col-span-5 bg-slate-950/50 border border-slate-800/80 p-4 rounded-2xl flex flex-col gap-4 animate-in slide-in-from-right duration-200 md:sticky md:top-2">
                                    {/* Keypad Title & Preview */}
                                    <div className="bg-slate-900/85 border border-slate-800/80 p-3.5 rounded-xl text-center shadow-inner">
                                        <span className="text-[9px] font-black text-slate-500 uppercase tracking-widest block">
                                            Editing Payment #{rows.findIndex(r => r.id === activeField.rowId) + 1} &mdash; {
                                                activeField.fieldName === 'amount' ? 'Payment Amount' : 'Cash Tendered'
                                            }
                                        </span>
                                        <span className="text-xl font-black text-white font-mono mt-1 block">
                                            ₱{Number(rows.find(r => r.id === activeField.rowId)?.[activeField.fieldName] || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}
                                        </span>
                                    </div>

                                    {/* Keypad Grid */}
                                    <div className="grid grid-cols-3 gap-2">
                                        {[
                                            [ { val: '1', label: '1' }, { val: '2', label: '2' }, { val: '3', label: '3' } ],
                                            [ { val: '4', label: '4' }, { val: '5', label: '5' }, { val: '6', label: '6' } ],
                                            [ { val: '7', label: '7' }, { val: '8', label: '8' }, { val: '9', label: '9' } ],
                                            [ { val: '00', label: '00' }, { val: '0', label: '0' }, { val: '.', label: '.' } ]
                                        ].map((rowKeys, rIdx) => (
                                            <React.Fragment key={rIdx}>
                                                {rowKeys.map(k => (
                                                    <button
                                                        key={k.val}
                                                        type="button"
                                                        onClick={() => handleKeypadPress(k.val)}
                                                        className="h-12 sm:h-14 flex items-center justify-center bg-slate-900 hover:bg-slate-850 active:bg-slate-800 border border-slate-800/80 text-white font-black rounded-xl text-sm sm:text-base transition-all active:scale-95 shadow-sm"
                                                    >
                                                        {k.label}
                                                    </button>
                                                ))}
                                            </React.Fragment>
                                        ))}
                                    </div>

                                    {/* Actions Grid */}
                                    <div className="grid grid-cols-4 gap-2">
                                        <button
                                            type="button"
                                            onClick={() => handleKeypadPress('clear')}
                                            className="col-span-1 h-12 bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/30 text-rose-400 text-[10px] font-black rounded-xl uppercase tracking-wider tracking-widest transition-all active:scale-95"
                                        >
                                            Clear
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleKeypadPress('exact')}
                                            className="col-span-1 h-12 bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 text-[10px] font-black rounded-xl uppercase tracking-wider tracking-widest transition-all active:scale-95"
                                        >
                                            Exact
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleKeypadPress('backspace')}
                                            className="col-span-1 h-12 bg-slate-900 hover:bg-slate-850 border border-slate-800/80 text-slate-300 flex items-center justify-center rounded-xl transition-all active:scale-95"
                                        >
                                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M3 12l6.414 6.414A2 2 0 0010.828 19H20a2 2 0 002-2V7a2 2 0 00-2-2h-9.172a2 2 0 00-1.414.586L3 12z" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setActiveField({ rowId: null, fieldName: null })}
                                            className="col-span-1 h-12 bg-indigo-600 hover:bg-indigo-500 text-white text-[10px] font-black rounded-xl uppercase tracking-wider tracking-widest transition-all active:scale-95 shadow-md shadow-indigo-600/20"
                                        >
                                            Apply
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Tactile Split Adder */}
                    {remainingBalance > 0.001 && (
                        <button
                            type="button"
                            onClick={addRow}
                            disabled={!hasPaymentMethods || rows.some(r => !r.payment_method_id)}
                            className="w-full py-3.5 border-2 border-dashed border-slate-800 hover:border-indigo-500/40 hover:bg-indigo-500/5 rounded-2xl flex items-center justify-center gap-2 text-slate-400 hover:text-indigo-400 font-bold text-xs transition-all disabled:opacity-30 disabled:cursor-not-allowed"
                        >
                            <Plus className="w-4 h-4" />
                            Add Another Split Method (Remaining: ₱{remainingBalance.toLocaleString(undefined, {minimumFractionDigits: 2})})
                        </button>
                    )}
                </div>

                {/* Sticky Complete Button Footer (Requirement 8, 9, 10) */}
                <div className="p-4 border-t border-slate-800/80 bg-slate-900/95 backdrop-blur-md sticky bottom-0 z-30 shrink-0">
                    <button 
                        onClick={handleSubmit}
                        disabled={!hasPaymentMethods || !validation.isValid || isSubmitting || remainingBalance > 0.001 || hasNoActiveShift || isOffline()}
                        className={`w-full py-3.5 rounded-xl font-black flex items-center justify-center gap-2.5 text-xs tracking-wider transition-all uppercase ${
                            hasPaymentMethods && validation.isValid && !isSubmitting && remainingBalance <= 0.001 && !hasNoActiveShift && !isOffline()
                            ? 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-500/25 active:scale-[0.98]' 
                            : 'bg-slate-800 text-slate-500 cursor-not-allowed opacity-50'
                        }`}
                    >
                        {isSubmitting ? (
                            <>
                                <Loader2 className="w-4.5 h-4.5 animate-spin" />
                                Processing Split Payments...
                            </>
                        ) : (
                            <>
                                <CheckCircle2 className="w-4.5 h-4.5" />
                                Complete Payment (₱{totalPaid.toLocaleString(undefined, {minimumFractionDigits: 2})})
                            </>
                        )}
                    </button>
                    {isOffline() && (
                        <p className="text-[10px] text-center text-rose-400 mt-2.5 uppercase tracking-widest font-black flex items-center justify-center gap-1.5 animate-pulse">
                            <AlertCircle className="w-3.5 h-3.5 shrink-0" />
                            Terminal is offline. Payments cannot be finalized.
                        </p>
                    )}
                    {hasNoActiveShift && !isOffline() && (
                        <p className="text-[10px] text-center text-rose-400 mt-2.5 uppercase tracking-widest font-black flex items-center justify-center gap-1.5 animate-pulse">
                            <AlertCircle className="w-3.5 h-3.5 shrink-0" />
                            Open a cashier shift before completing payment.
                        </p>
                    )}
                    {!hasPaymentMethods && (
                        <p className="text-[9px] text-center text-amber-300 mt-2.5 uppercase tracking-widest font-black">
                            Configure at least one active payment method to record payment.
                        </p>
                    )}
                    {hasPaymentMethods && remainingBalance > 0.001 && (
                        <p className="text-[9px] text-center text-indigo-400 mt-2.5 uppercase tracking-widest font-black">
                            Split Remaining Balance: ₱{remainingBalance.toLocaleString(undefined, {minimumFractionDigits: 2})} to complete payment.
                        </p>
                    )}
                    {hasPaymentMethods && remainingBalance <= 0.001 && !validation.isValid && validation.errors.length > 0 && (
                        <p className="text-[9px] text-center text-rose-400 mt-2.5 uppercase tracking-widest font-black">
                            {validation.errors[0]}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
