import React, { useState, useEffect, useCallback } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import {
    X, UserCheck, Users, Calculator, AlertTriangle, Loader2, Plus, Trash2,
    ShieldCheck, Percent, Receipt, ChevronDown,
} from 'lucide-react';
import { isOffline } from '@/POS/offline/offlineGuards';

const CATEGORY_LABELS = {
    senior: 'Senior Citizen',
    pwd: 'PWD',
    solo_parent: 'Solo Parent',
    other: 'Other',
};

const MODE_LABELS = {
    standard: 'Standard (Full Bill)',
    line_item: 'Line-Item (Specific Products)',
    portion: 'Portion (Per Pax)',
    memc: 'MEMC (Most Expensive Meal)',
};

/**
 * POS Special Discount Modal
 *
 * Allows cashiers to apply BIR-compliant statutory discounts (SC, PWD, Solo Parent)
 * with identity capture, pax controls, and real-time calculation preview.
 *
 * Props:
 *   isOpen            — boolean, controls modal visibility
 *   onClose           — callback when modal is dismissed
 *   onApply           — callback when discount is confirmed (receives { discountType, options, result })
 *   discountTypes     — array of available DiscountType objects from backend
 *   cartItems         — array of cart line items for calculation preview
 *   buildPosHeaders   — function to build auth headers for API calls
 */
export default function SpecialDiscountModal({
    isOpen = false,
    onClose = () => {},
    onApply = () => {},
    discountTypes = [],
    cartItems = [],
    buildPosHeaders = () => ({}),
}) {
    const [selectedTypeId, setSelectedTypeId] = useState(null);
    const [applicationMode, setApplicationMode] = useState('standard');
    const [eligiblePersonCount, setEligiblePersonCount] = useState(1);
    const [totalPaxCount, setTotalPaxCount] = useState(1);
    const [memcBaseValue, setMemcBaseValue] = useState(0);
    const [beneficiaries, setBeneficiaries] = useState([
        { beneficiary_name: '', id_number: '', tin: '', spic_number: '' },
    ]);
    const [calculationResult, setCalculationResult] = useState(null);
    const [isCalculating, setIsCalculating] = useState(false);
    const [errors, setErrors] = useState([]);
    const [isAuthorizing, setIsAuthorizing] = useState(false);
    const [approvalError, setApprovalError] = useState(null);
    const [managerApprovalId, setManagerApprovalId] = useState(null);
    const [managerEmail, setManagerEmail] = useState('');
    const [managerPassword, setManagerPassword] = useState('');

    const selectedType = discountTypes.find((t) => t.id === selectedTypeId) || null;
    const statutoryCategory = selectedType?.statutory_category || null;

    // Reset state when modal opens
    useEffect(() => {
        if (isOpen) {
            setSelectedTypeId(null);
            setApplicationMode('standard');
            setEligiblePersonCount(1);
            setTotalPaxCount(1);
            setMemcBaseValue(0);
            setBeneficiaries([{ beneficiary_name: '', id_number: '', tin: '', spic_number: '' }]);
            setCalculationResult(null);
            setErrors([]);
            setApprovalError(null);
            setManagerApprovalId(null);
            setManagerEmail('');
            setManagerPassword('');
        }
    }, [isOpen]);

    // Auto-adjust beneficiaries array to match eligible person count
    useEffect(() => {
        if (!selectedType?.requires_identity) return;
        setBeneficiaries((prev) => {
            const count = Math.max(1, eligiblePersonCount);
            const updated = [...prev];
            while (updated.length < count) {
                updated.push({ beneficiary_name: '', id_number: '', tin: '', spic_number: '' });
            }
            return updated.slice(0, count);
        });
    }, [eligiblePersonCount, selectedType]);

    // Real-time calculation preview via backend API
    const calculatePreview = useCallback(async () => {
        if (!selectedTypeId || cartItems.length === 0) {
            setCalculationResult(null);
            return;
        }

        setIsCalculating(true);
        setErrors([]);

        try {
            if (isOffline()) {
                setErrors(['Special discount calculation requires a server connection. Reconnect before applying discounts.']);
                setCalculationResult(null);
                return;
            }

            const response = await fetch('/api/pos/discounts/calculate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    ...buildPosHeaders(),
                },
                body: JSON.stringify({
                    discount_type_id: selectedTypeId,
                    cart_items: cartItems.map((item) => ({
                        product_id: item.product_id || item.id,
                        line_subtotal: Number(item.selling_price || item.unit_price || 0) * item.quantity,
                        tax_bucket: item.tax_bucket || 'vatable',
                    })),
                    options: {
                        application_mode: applicationMode,
                        eligible_person_count: eligiblePersonCount,
                        total_pax_count: totalPaxCount,
                        memc_base_value: memcBaseValue,
                        beneficiaries,
                    },
                }),
            });

            const data = await response.json();

            if (!data.is_valid) {
                setErrors(data.errors || ['Calculation failed.']);
                setCalculationResult(null);
            } else {
                setCalculationResult(data);
                setErrors([]);
            }
        } catch (err) {
            setErrors(['Unable to connect to calculation service.']);
            setCalculationResult(null);
        } finally {
            setIsCalculating(false);
        }
    }, [selectedTypeId, cartItems, applicationMode, eligiblePersonCount, totalPaxCount, memcBaseValue, beneficiaries, buildPosHeaders]);

    // Debounced recalculation when inputs change
    useEffect(() => {
        if (!isOpen || !selectedTypeId) return;
        const timer = setTimeout(calculatePreview, 400);
        return () => clearTimeout(timer);
    }, [isOpen, selectedTypeId, calculatePreview]);

    const handleBeneficiaryChange = (index, field, value) => {
        setBeneficiaries((prev) => {
            const updated = [...prev];
            updated[index] = { ...updated[index], [field]: value };
            return updated;
        });
    };

    const handleApply = async () => {
        if (isOffline()) {
            setErrors(['Special discounts require a server connection. Reconnect before applying discounts.']);
            return;
        }

        if (!selectedType) {
            setErrors(['Please select a discount type.']);
            return;
        }
        if (!calculationResult || !calculationResult.is_valid) {
            setErrors(['Cannot apply — calculation invalid. Please review inputs.']);
            return;
        }

        // Manager Approval Workflow
        let approvalId = null;
        if (calculationResult.approval_required) {
            if (!managerEmail || !managerPassword) {
                setApprovalError('Manager email and password are required.');
                return;
            }
            setIsAuthorizing(true);
            setApprovalError(null);
            try {
                const response = await fetch('/api/pos/manager/authorize', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        ...buildPosHeaders(),
                    },
                    body: JSON.stringify({
                        discount_type_id: selectedTypeId,
                        cart_items: cartItems.map((item) => ({
                            product_id: item.product_id || item.id,
                            quantity: Number(item.quantity),
                        })),
                        options: {
                            application_mode: applicationMode,
                            eligible_person_count: eligiblePersonCount,
                            total_pax_count: totalPaxCount,
                            memc_base_value: memcBaseValue,
                            beneficiaries,
                        },
                        manager_email: managerEmail,
                        manager_password: managerPassword,
                    }),
                });

                if (!response.ok) {
                    const errData = await response.json();
                    throw new Error(errData.message || 'Manager authorization failed.');
                }

                const approvalData = await response.json();
                approvalId = approvalData.approval_id;
                setManagerApprovalId(approvalId);
            } catch (err) {
                setApprovalError(err.message);
                setIsAuthorizing(false);
                return;
            }
        }

        onApply({
            discountType: selectedType,
            options: {
                application_mode: applicationMode,
                eligible_person_count: eligiblePersonCount,
                total_pax_count: totalPaxCount,
                memc_base_value: memcBaseValue,
                beneficiaries,
            },
            managerApprovalId: approvalId,
            result: calculationResult,
        });
        onClose();
    };

    const formatPeso = (value) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    return (
        <Transition show={isOpen} as={React.Fragment}>
            <Dialog as="div" className="relative z-[100]" onClose={onClose}>
                {/* Backdrop */}
                <TransitionChild
                    as={React.Fragment}
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" />
                </TransitionChild>

                <div className="fixed inset-0 overflow-y-auto">
                    <div className="flex min-h-full items-center justify-center p-4">
                        <TransitionChild
                            as={React.Fragment}
                            enter="ease-out duration-300"
                            enterFrom="opacity-0 scale-95"
                            enterTo="opacity-100 scale-100"
                            leave="ease-in duration-200"
                            leaveFrom="opacity-100 scale-100"
                            leaveTo="opacity-0 scale-95"
                        >
                            <DialogPanel className="w-full max-w-2xl rounded-2xl bg-slate-900 border border-slate-700 shadow-2xl overflow-hidden">
                                {/* Header */}
                                <div className="flex items-center justify-between px-6 py-4 border-b border-slate-700 bg-slate-800/50">
                                    <div className="flex items-center gap-3">
                                        <div className="p-2 rounded-lg bg-indigo-500/10 text-indigo-400">
                                            <ShieldCheck className="w-5 h-5" />
                                        </div>
                                        <div>
                                            <h2 className="text-lg font-bold text-white">Special Discount</h2>
                                            <p className="text-xs text-slate-400">BIR-compliant statutory discount application</p>
                                        </div>
                                    </div>
                                    <button
                                        onClick={onClose}
                                        className="p-2 text-slate-400 hover:text-white hover:bg-slate-700 rounded-lg transition-colors"
                                    >
                                        <X className="w-5 h-5" />
                                    </button>
                                </div>

                                {/* Body */}
                                <div className="max-h-[60vh] overflow-y-auto px-6 py-5 space-y-5">
                                    {/* Step 1: Discount Category */}
                                    <div>
                                        <label className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">
                                            <Percent className="w-3.5 h-3.5" /> Discount Category
                                        </label>
                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                            {discountTypes.map((type) => {
                                                const isActive = selectedTypeId === type.id;
                                                return (
                                                    <button
                                                        key={type.id}
                                                        onClick={() => setSelectedTypeId(type.id)}
                                                        className={`flex flex-col items-center gap-1.5 rounded-xl border p-3 text-center transition-all ${
                                                            isActive
                                                                ? 'border-indigo-500 bg-indigo-500/10 text-white shadow-lg shadow-indigo-500/10'
                                                                : 'border-slate-700 bg-slate-800/50 text-slate-400 hover:border-slate-600 hover:text-slate-200'
                                                        }`}
                                                    >
                                                        <UserCheck className={`w-5 h-5 ${isActive ? 'text-indigo-400' : 'text-slate-500'}`} />
                                                        <span className="text-xs font-bold leading-tight">
                                                            {CATEGORY_LABELS[type.statutory_category] || type.name}
                                                        </span>
                                                        <span className="text-[10px] text-slate-500">
                                                            {(type.default_rate * 100).toFixed(0)}% off
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    {/* Step 2: Application Mode */}
                                    {selectedType && (
                                        <div>
                                            <label className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">
                                                <Calculator className="w-3.5 h-3.5" /> Application Mode
                                            </label>
                                            <div className="grid grid-cols-2 gap-2">
                                                {Object.entries(MODE_LABELS).map(([mode, label]) => (
                                                    <button
                                                        key={mode}
                                                        onClick={() => setApplicationMode(mode)}
                                                        className={`rounded-lg border px-3 py-2 text-xs font-semibold transition-all ${
                                                            applicationMode === mode
                                                                ? 'border-indigo-500 bg-indigo-500/10 text-white'
                                                                : 'border-slate-700 bg-slate-800/50 text-slate-400 hover:text-slate-200'
                                                        }`}
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {/* Step 3: Pax Controls */}
                                    {selectedType && (applicationMode === 'portion' || applicationMode === 'memc') && (
                                        <div className="grid grid-cols-2 gap-3">
                                            <div>
                                                <label className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-400 mb-1.5">
                                                    <Users className="w-3.5 h-3.5" /> Eligible Persons
                                                </label>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    value={eligiblePersonCount}
                                                    onChange={(e) => setEligiblePersonCount(Math.max(1, parseInt(e.target.value) || 1))}
                                                    className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm font-bold text-white focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1.5 block">
                                                    Total Pax
                                                </label>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    value={totalPaxCount}
                                                    onChange={(e) => setTotalPaxCount(Math.max(1, parseInt(e.target.value) || 1))}
                                                    className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm font-bold text-white focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {/* MEMC base value */}
                                    {selectedType && applicationMode === 'memc' && (
                                        <div>
                                            <label className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1.5 block">
                                                MEMC Base Value (₱)
                                            </label>
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={memcBaseValue}
                                                onChange={(e) => setMemcBaseValue(parseFloat(e.target.value) || 0)}
                                                className="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm font-bold text-white focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                                            />
                                        </div>
                                    )}

                                    {/* Step 4: Beneficiary Identity */}
                                    {selectedType?.requires_identity && (
                                        <div>
                                            <label className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">
                                                <UserCheck className="w-3.5 h-3.5" /> Beneficiary Identity
                                            </label>
                                            <div className="space-y-3">
                                                {beneficiaries.map((beneficiary, index) => (
                                                    <div key={index} className="rounded-xl border border-slate-700 bg-slate-800/50 p-3 space-y-2">
                                                        <div className="flex items-center justify-between">
                                                            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                                                Beneficiary {index + 1}
                                                            </span>
                                                            {beneficiaries.length > 1 && (
                                                                <button
                                                                    onClick={() => setBeneficiaries((prev) => prev.filter((_, i) => i !== index))}
                                                                    className="text-rose-400 hover:text-rose-300"
                                                                >
                                                                    <Trash2 className="w-3.5 h-3.5" />
                                                                </button>
                                                            )}
                                                        </div>
                                                        <div>
                                                            <label className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 block mb-1">Full Name</label>
                                                            <input
                                                                type="text"
                                                                value={beneficiary.beneficiary_name}
                                                                onChange={(e) => handleBeneficiaryChange(index, 'beneficiary_name', e.target.value)}
                                                                placeholder="Juan Dela Cruz"
                                                                className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white placeholder-slate-600 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                                                            />
                                                        </div>
                                                        {(statutoryCategory === 'senior' || statutoryCategory === 'pwd') && (
                                                            <div className="grid grid-cols-2 gap-2">
                                                                <div>
                                                                    <label className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 block mb-1">ID Number</label>
                                                                    <input
                                                                        type="text"
                                                                        value={beneficiary.id_number}
                                                                        onChange={(e) => handleBeneficiaryChange(index, 'id_number', e.target.value)}
                                                                        placeholder="SC-2024-001"
                                                                        className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white placeholder-slate-600 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 block mb-1">TIN</label>
                                                                    <input
                                                                        type="text"
                                                                        value={beneficiary.tin}
                                                                        onChange={(e) => handleBeneficiaryChange(index, 'tin', e.target.value)}
                                                                        placeholder="123-456-789"
                                                                        className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white placeholder-slate-600 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                                                                    />
                                                                </div>
                                                            </div>
                                                        )}
                                                        {statutoryCategory === 'solo_parent' && (
                                                            <div>
                                                                <label className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 block mb-1">SPIC Number</label>
                                                                <input
                                                                    type="text"
                                                                    value={beneficiary.spic_number}
                                                                    onChange={(e) => handleBeneficiaryChange(index, 'spic_number', e.target.value)}
                                                                    placeholder="SPIC-2024-001"
                                                                    className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white placeholder-slate-600 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
                                                                />
                                                            </div>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {/* Errors */}
                                    {errors.length > 0 && (
                                        <div className="rounded-xl border border-rose-500/20 bg-rose-500/10 p-3 space-y-1">
                                            {errors.map((err, i) => (
                                                <div key={i} className="flex items-center gap-2 text-xs text-rose-300">
                                                    <AlertTriangle className="w-3.5 h-3.5 shrink-0" />
                                                    {err}
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {/* Calculation Preview */}
                                    {isCalculating && (
                                        <div className="flex items-center justify-center gap-2 py-4 text-slate-400">
                                            <Loader2 className="w-4 h-4 animate-spin" />
                                            <span className="text-xs">Calculating discount...</span>
                                        </div>
                                    )}
                                    {calculationResult && !isCalculating && (
                                        <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4 space-y-2">
                                            <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-400 mb-1">
                                                <Receipt className="w-3.5 h-3.5" /> Discount Summary
                                            </div>
                                            <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                                                <div className="text-slate-400">Gross Eligible:</div>
                                                <div className="text-right font-mono text-slate-200">{formatPeso(calculationResult.gross_eligible_amount)}</div>
                                                <div className="text-slate-400">Less VAT:</div>
                                                <div className="text-right font-mono text-amber-400">-{formatPeso(calculationResult.vat_amount_removed)}</div>
                                                <div className="text-slate-400">Discountable Base:</div>
                                                <div className="text-right font-mono text-slate-200">{formatPeso(calculationResult.discountable_base)}</div>
                                                <div className="text-slate-400">Discount Amount:</div>
                                                <div className="text-right font-mono text-emerald-400 font-bold">-{formatPeso(calculationResult.discount_amount)}</div>
                                                <div className="border-t border-slate-700 pt-1.5 text-slate-300 font-bold">Net Payable:</div>
                                                <div className="border-t border-slate-700 pt-1.5 text-right font-mono text-white font-bold text-base">{formatPeso(calculationResult.net_payable)}</div>
                                            </div>
                                        </div>
                                    )}
                                    {calculationResult?.approval_required && !isCalculating && (
                                        <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 space-y-3">
                                            <div className="text-xs font-bold uppercase tracking-wider text-amber-300">Independent manager approval required</div>
                                            <input
                                                type="email"
                                                value={managerEmail}
                                                onChange={(event) => setManagerEmail(event.target.value)}
                                                placeholder="Manager email"
                                                autoComplete="username"
                                                className="w-full rounded-lg border-slate-600 bg-slate-900 text-sm text-white"
                                            />
                                            <input
                                                type="password"
                                                value={managerPassword}
                                                onChange={(event) => setManagerPassword(event.target.value)}
                                                placeholder="Manager password"
                                                autoComplete="current-password"
                                                className="w-full rounded-lg border-slate-600 bg-slate-900 text-sm text-white"
                                            />
                                        </div>
                                    )}
                                </div>

                                {/* Footer */}
                                <div className="flex items-center justify-between gap-3 px-6 py-4 border-t border-slate-700 bg-slate-800/50">
                                    <button
                                        onClick={onClose}
                                        className="rounded-lg border border-slate-600 px-4 py-2 text-sm font-bold text-slate-300 hover:bg-slate-700 transition-colors"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        onClick={handleApply}
                                        disabled={!selectedType || !calculationResult || isCalculating || isAuthorizing}
                                        className="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 hover:bg-indigo-500 transition-all disabled:cursor-not-allowed disabled:opacity-50 flex items-center gap-2"
                                    >
                                        {isAuthorizing ? (
                                            <>
                                                <Loader2 className="w-4 h-4 animate-spin" />
                                                Authorizing...
                                            </>
                                        ) : (
                                            <>
                                                <ShieldCheck className="w-4 h-4" />
                                                Apply Discount
                                            </>
                                        )}
                                    </button>
                                </div>
                                {approvalError && (
                                    <div className="px-6 pb-4 -mt-2">
                                        <div className="p-3 bg-rose-500/10 border border-rose-500/20 rounded-lg flex gap-2 items-start text-rose-400">
                                            <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                                            <p className="text-[11px] font-bold leading-tight">{approvalError}</p>
                                        </div>
                                    </div>
                                )}
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}
