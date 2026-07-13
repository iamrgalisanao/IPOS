import React, { useState, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { AlertCircle, ArrowDownCircle, ArrowUpCircle } from 'lucide-react';
import { catalogCache } from '@/POS/offline/catalogCache';

const FALLBACK_REASONS = [
    { event_type: 'cash_drop', code: 'SKIM', name: 'Skim (Excess Cash)', requires_manager_approval: true },
    { event_type: 'cash_drop', code: 'EXPENSE', name: 'Petty Cash Expense', requires_manager_approval: false },
    { event_type: 'cash_drop', code: 'ERROR', name: 'Correction', requires_manager_approval: false },
    { event_type: 'cash_top_up', code: 'REPLENISH', name: 'Replenish Change', requires_manager_approval: false },
    { event_type: 'cash_top_up', code: 'LOAN', name: 'Initial Float Addition', requires_manager_approval: false },
    { event_type: 'cash_top_up', code: 'ERROR', name: 'Correction', requires_manager_approval: false }
];

export default function RecordCashEventModal({ show, onClose, shift }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        shift_id: shift?.id || '',
        event_type: 'cash_drop',
        amount: '',
        reason_code: 'SKIM',
        reason_notes: '',
        manager_email: '',
        manager_password: '',
    });

    const [reasons, setReasons] = useState([]);

    useEffect(() => {
        catalogCache.getCachedCatalog().then(payload => {
            if (payload && payload.cash_drawer_reasons && payload.cash_drawer_reasons.length > 0) {
                setReasons(payload.cash_drawer_reasons);
            }
        }).catch(err => {
            console.error('Failed to load cached cash drawer reasons', err);
        });
    }, [show]);

    useEffect(() => {
        const filtered = reasons.length > 0
            ? reasons.filter(r => r.event_type === data.event_type)
            : FALLBACK_REASONS.filter(r => r.event_type === data.event_type);
        if (filtered.length > 0) {
            // Check if the current reason_code is already in the filtered list
            const currentIsValid = filtered.some(r => r.code === data.reason_code);
            if (!currentIsValid) {
                setData('reason_code', filtered[0].code);
            }
        }
    }, [data.event_type, reasons]);

    if (!shift) return null;

    const THRESHOLD = 5000;

    const activeReasons = reasons.length > 0
        ? reasons.filter(r => r.event_type === data.event_type)
        : FALLBACK_REASONS.filter(r => r.event_type === data.event_type);

    const selectedReason = activeReasons.find(r => r.code === data.reason_code);
    const reasonRequiresApproval = selectedReason?.requires_manager_approval || false;
    const isHighValueDrop = data.event_type === 'cash_drop' && Number(data.amount) > THRESHOLD;
    const isApprovalRequired = isHighValueDrop || reasonRequiresApproval;
    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('shifts.drawer-events'), {
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Modal show={show} onClose={onClose}>
            <form onSubmit={handleSubmit} className="p-6">
                <h2 className="text-lg font-medium text-slate-100 mb-4">
                    Record Cash Drawer Event
                </h2>

                <div className="space-y-4">
                    {/* Event Type Toggle */}
                    <div className="flex p-1 bg-slate-800 rounded-lg">
                        <button
                            type="button"
                            onClick={() => setData('event_type', 'cash_drop')}
                            className={`flex-1 flex items-center justify-center gap-2 py-2 rounded-md transition-all ${
                                data.event_type === 'cash_drop'
                                    ? 'bg-rose-500 text-white shadow-lg'
                                    : 'text-slate-400 hover:text-slate-200'
                            }`}
                        >
                            <ArrowDownCircle className="w-4 h-4" />
                            <span className="text-sm font-medium">Cash Drop</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setData('event_type', 'cash_top_up')}
                            className={`flex-1 flex items-center justify-center gap-2 py-2 rounded-md transition-all ${
                                data.event_type === 'cash_top_up'
                                    ? 'bg-emerald-500 text-white shadow-lg'
                                    : 'text-slate-400 hover:text-slate-200'
                            }`}
                        >
                            <ArrowUpCircle className="w-4 h-4" />
                            <span className="text-sm font-medium">Cash Top-up</span>
                        </button>
                    </div>

                    {/* Amount */}
                    <div>
                        <InputLabel htmlFor="amount" value="Amount (₱)" />
                        <TextInput
                            id="amount"
                            type="number"
                            step="0.01"
                            name="amount"
                            value={data.amount}
                            className="mt-1 block w-full"
                            onChange={(e) => setData('amount', e.target.value)}
                            placeholder="0.00"
                            required
                        />
                        <InputError message={errors.amount} className="mt-2" />
                    </div>

                    {/* Reason Code */}
                    <div>
                        <InputLabel htmlFor="reason_code" value="Reason Code" />
                        <select
                            id="reason_code"
                            className="mt-1 block w-full bg-slate-900 border-slate-700 text-slate-100 rounded-lg focus:border-indigo-500 focus:ring-indigo-500"
                            value={data.reason_code}
                            onChange={(e) => setData('reason_code', e.target.value)}
                        >
                            {activeReasons.map(r => (
                                <option key={r.code} value={r.code}>
                                    {r.name} {r.requires_manager_approval ? '(Requires Manager)' : ''}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.reason_code} className="mt-2" />
                    </div>

                    {/* Notes */}
                    <div>
                        <InputLabel htmlFor="reason_notes" value="Notes" />
                        <textarea
                            id="reason_notes"
                            className="mt-1 block w-full bg-slate-900 border-slate-700 text-slate-100 rounded-lg focus:border-indigo-500 focus:ring-indigo-500"
                            value={data.reason_notes}
                            onChange={(e) => setData('reason_notes', e.target.value)}
                            rows="2"
                            placeholder="Optional details..."
                        />
                        <InputError message={errors.reason_notes} className="mt-2" />
                    </div>

                    {/* Threshold Warning */}
                    {isApprovalRequired && (
                        <div className="space-y-4">
                            <div className="p-3 bg-amber-500/10 border border-amber-500/20 rounded-lg flex gap-3">
                                <AlertCircle className="w-5 h-5 text-amber-500 shrink-0" />
                                <div className="text-xs text-amber-200">
                                    <p className="font-bold">Manager Approval Required</p>
                                    <p>
                                        {isHighValueDrop
                                            ? `This drop exceeds ₱${THRESHOLD.toLocaleString()}. A manager must authorize this transaction.`
                                            : `The selected reason "${selectedReason?.name || data.reason_code}" requires manager authorization.`}
                                    </p>
                                </div>
                            </div>
                            
                            <div className="bg-slate-900 rounded-xl p-4 border border-slate-800">
                                <h3 className="text-sm font-bold text-slate-200 mb-4">Manager Credentials</h3>
                                
                                <div className="space-y-4">
                                    <div>
                                        <InputLabel htmlFor="manager_email" value="Manager Email" />
                                        <TextInput
                                            id="manager_email"
                                            type="email"
                                            className="mt-1 block w-full bg-slate-950 border-slate-700"
                                            value={data.manager_email}
                                            onChange={(e) => setData('manager_email', e.target.value)}
                                            required={isApprovalRequired}
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
                                            required={isApprovalRequired}
                                        />
                                        <InputError message={errors.manager_password} className="mt-2" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                <div className="mt-6 flex justify-end gap-3">
                    <SecondaryButton onClick={onClose} disabled={processing}>
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton
                        className={data.event_type === 'cash_drop' ? 'bg-rose-600 hover:bg-rose-500' : 'bg-emerald-600 hover:bg-emerald-500'}
                        disabled={processing}
                    >
                        {processing ? 'Processing...' : 'Record Event'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
