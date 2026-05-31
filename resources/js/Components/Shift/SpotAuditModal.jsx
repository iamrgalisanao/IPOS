import React, { useState, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { ShieldAlert, Calculator } from 'lucide-react';

export default function SpotAuditModal({ show, onClose, shift }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        manager_email: '',
        manager_password: '',
        counted_cash_amount: '0',
        denominations: {},
        audit_notes: '',
    });

    const [denominationsState, setDenominationsState] = useState({
        '1000': '',
        '500': '',
        '200': '',
        '100': '',
        '50': '',
        '20': '',
        '10': '',
        '5': '',
        '1': '',
    });

    useEffect(() => {
        if (show) {
            reset();
            clearErrors();
            setDenominationsState({
                '1000': '', '500': '', '200': '', '100': '', '50': '', '20': '', '10': '', '5': '', '1': ''
            });
        }
    }, [show]);

    useEffect(() => {
        let total = 0;
        const mappedDenoms = {};
        Object.entries(denominationsState).forEach(([value, count]) => {
            const parsedCount = parseInt(count) || 0;
            total += parseFloat(value) * parsedCount;
            if (parsedCount > 0) {
                mappedDenoms[value] = parsedCount;
            }
        });
        
        // Ensure total matches to two decimal places
        setData(prevData => ({
            ...prevData,
            counted_cash_amount: total.toFixed(2),
            denominations: mappedDenoms
        }));
    }, [denominationsState]);

    if (!shift) return null;

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('spot-audit', shift.id), {
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    const handleDenomChange = (val, count) => {
        setDenominationsState(prev => ({
            ...prev,
            [val]: count
        }));
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={handleSubmit} className="p-6">
                <div className="flex items-center gap-3 mb-6 border-b border-slate-800 pb-4">
                    <div className="p-2 bg-indigo-500/20 rounded-lg">
                        <ShieldAlert className="w-6 h-6 text-indigo-400" />
                    </div>
                    <div>
                        <h2 className="text-lg font-bold text-white tracking-tight">
                            Surprise Spot Audit
                        </h2>
                        <p className="text-xs text-slate-400">
                            Perform a mid-shift cash count. This action requires manager credentials and is permanently recorded.
                        </p>
                    </div>
                </div>

                {errors.spot_audit && (
                    <div className="mb-4 bg-rose-500/10 border border-rose-500/20 text-rose-400 px-4 py-3 rounded-lg text-sm">
                        {errors.spot_audit}
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                    {/* Left Column: Denominations */}
                    <div>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-sm font-bold text-slate-200 flex items-center gap-2">
                                <Calculator className="w-4 h-4 text-slate-400" />
                                Cash Count
                            </h3>
                            <span className="text-lg font-mono font-bold text-emerald-400">
                                ₱{data.counted_cash_amount}
                            </span>
                        </div>
                        
                        <div className="space-y-2 max-h-64 overflow-y-auto pr-2 custom-scrollbar">
                            {Object.keys(denominationsState).sort((a,b) => Number(b) - Number(a)).map(val => (
                                <div key={val} className="flex items-center justify-between gap-4 bg-slate-900/50 p-2 rounded-lg border border-slate-800">
                                    <span className="text-sm font-medium text-slate-300 w-16">₱{val}</span>
                                    <span className="text-slate-500 text-xs">x</span>
                                    <TextInput
                                        type="number"
                                        min="0"
                                        step="1"
                                        className="w-24 text-right bg-slate-950 border-slate-700 h-8 text-sm"
                                        value={denominationsState[val]}
                                        onChange={e => handleDenomChange(val, e.target.value)}
                                        placeholder="0"
                                    />
                                </div>
                            ))}
                        </div>
                        <InputError message={errors.counted_cash_amount} className="mt-2" />
                        <InputError message={errors.denominations} className="mt-2" />
                    </div>

                    {/* Right Column: Auth & Notes */}
                    <div className="space-y-4">
                        <div className="bg-slate-900 rounded-xl p-4 border border-slate-800">
                            <h3 className="text-sm font-bold text-slate-200 mb-4">Manager Authorization</h3>
                            
                            <div className="space-y-4">
                                <div>
                                    <InputLabel htmlFor="manager_email" value="Manager Email" />
                                    <TextInput
                                        id="manager_email"
                                        type="email"
                                        className="mt-1 block w-full bg-slate-950 border-slate-700"
                                        value={data.manager_email}
                                        onChange={(e) => setData('manager_email', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.manager_email} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="manager_password" value="Manager Password" />
                                    <TextInput
                                        id="manager_password"
                                        type="password"
                                        className="mt-1 block w-full bg-slate-950 border-slate-700"
                                        value={data.manager_password}
                                        onChange={(e) => setData('manager_password', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.manager_password} className="mt-2" />
                                </div>
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="audit_notes" value="Audit Notes (Optional)" />
                            <textarea
                                id="audit_notes"
                                className="mt-1 block w-full bg-slate-900 border-slate-700 text-slate-100 rounded-lg focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                value={data.audit_notes}
                                onChange={(e) => setData('audit_notes', e.target.value)}
                                rows="3"
                                placeholder="Explain any known variances..."
                            />
                            <InputError message={errors.audit_notes} className="mt-2" />
                        </div>
                    </div>
                </div>

                <div className="mt-8 flex justify-end gap-3 border-t border-slate-800 pt-4">
                    <SecondaryButton onClick={onClose} disabled={processing}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton
                        className="bg-indigo-600 hover:bg-indigo-500"
                        disabled={processing || parseFloat(data.counted_cash_amount) === 0}
                    >
                        {processing ? 'Processing...' : 'Submit Audit'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
