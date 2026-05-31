import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import CloseShiftModal from '@/Components/Shift/CloseShiftModal';
import RecordCashEventModal from '@/Components/Shift/RecordCashEventModal';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    ArrowLeft, 
    Calendar, 
    User, 
    Building2, 
    Wallet, 
    ArrowUpCircle, 
    ArrowDownCircle, 
    CheckCircle2, 
    AlertCircle, 
    Clock,
    FileText,
    History,
    ShoppingCart,
    Lock,
    X
} from 'lucide-react';

export default function ShiftShow({ auth, shift }) {
    const [showCloseModal, setShowCloseModal] = useState(false);
    const [showEventModal, setShowEventModal] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const [depositAmount, setDepositAmount] = useState(shift.counted_cash_amount || '');
    const [varianceExplanation, setVarianceExplanation] = useState('');
    const [bankName, setBankName] = useState('');
    const [referenceNumber, setReferenceNumber] = useState('');
    const [depositedAt, setDepositedAt] = useState(new Date().toISOString().split('T')[0]);
    const [managerNotes, setManagerNotes] = useState('');

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2
        }).format(parseFloat(val || 0));
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '---';
        return new Date(dateStr).toLocaleString('en-PH', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'open': return 'bg-emerald-100 text-emerald-700 border-emerald-200';
            case 'closing_submitted': return 'bg-amber-100 text-amber-700 border-amber-200';
            case 'approved': return 'bg-blue-100 text-blue-700 border-blue-200';
            case 'closed': return 'bg-gray-100 text-gray-700 border-gray-200';
            default: return 'bg-gray-100 text-gray-700 border-gray-200';
        }
    };

    const handleApprove = () => {
        setDepositAmount(shift.counted_cash_amount || '');
        setConfirmOpen(true);
    };

    const handleConfirmApprove = (e) => {
        e.preventDefault();
        router.post(route('shifts.approve', shift.id), {
            manager_notes: managerNotes,
            deposit_amount: depositAmount,
            variance_explanation: varianceExplanation,
            bank_name: bankName,
            reference_number: referenceNumber,
            deposited_at: depositedAt,
        }, {
            onSuccess: () => setConfirmOpen(false)
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between w-full">
                    <div className="flex items-center gap-4">
                        <Link href={route('shifts.index')} className="p-2 hover:bg-gray-100 rounded-full transition-colors">
                            <ArrowLeft size={20} className="text-gray-500" />
                        </Link>
                        <div>
                            <h2 className="text-xl font-bold leading-tight text-gray-800">Shift Summary</h2>
                            <div className="flex items-center gap-2 mt-0.5 text-xs font-medium text-gray-500">
                                <span>ID: {shift.id.split('-')[0].toUpperCase()}</span>
                                <span>•</span>
                                <span>{shift.branch?.name}</span>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        {shift.status === 'open' && shift.cashier_id === auth.user.id && (
                            <button
                                onClick={() => setShowCloseModal(true)}
                                className="inline-flex items-center px-4 py-2 bg-rose-600 border border-transparent rounded-xl font-bold text-white text-sm uppercase tracking-widest hover:bg-rose-700 transition-all shadow-lg shadow-rose-100"
                            >
                                <Lock className="w-4 h-4 mr-2" />
                                Close Shift
                            </button>
                        )}
                        
                        {shift.status !== 'open' && (
                            <Link
                                href={route('shifts.z-report', shift.id)}
                                className="inline-flex items-center px-4 py-2 bg-white border border-gray-200 rounded-xl font-bold text-gray-700 text-sm uppercase tracking-widest hover:bg-gray-50 transition-all shadow-sm"
                            >
                                <FileText className="w-4 h-4 mr-2" />
                                Z-Report
                            </Link>
                        )}
                        
                        {shift.status === 'closing_submitted' && auth.permissions.includes('approve_shift') && (
                            <button
                                onClick={handleApprove}
                                className="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-xl font-bold text-white text-sm uppercase tracking-widest hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100"
                            >
                                <CheckCircle2 className="w-4 h-4 mr-2" />
                                Approve Shift
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Shift Summary - ${shift.cashier?.name}`} />

            {showCloseModal && <CloseShiftModal shift={shift} onClose={() => setShowCloseModal(false)} />}
            {showEventModal && <RecordCashEventModal shift={shift} show={showEventModal} onClose={() => setShowEventModal(false)} />}

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-8">
                    
                    {/* Header Summary Card */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 sm:p-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
                            <div className="flex items-center gap-4">
                                <div className="w-16 h-16 bg-blue-50 rounded-2xl flex items-center justify-center text-blue-600">
                                    <User size={32} />
                                </div>
                                <div>
                                    <h3 className="text-2xl font-bold text-gray-900">{shift.cashier?.name}</h3>
                                    <p className="text-sm text-gray-500 mt-1 flex items-center gap-2">
                                        <Calendar size={14} />
                                        Opened: {formatDate(shift.opened_at)}
                                    </p>
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <span className={`px-4 py-1.5 text-xs font-bold rounded-full border uppercase tracking-wider ${getStatusColor(shift.status)}`}>
                                    {shift.status.replace('_', ' ')}
                                </span>
                                {shift.status === 'approved' && (
                                    <span className="flex items-center gap-1.5 px-4 py-1.5 bg-emerald-50 text-emerald-700 text-xs font-bold rounded-full border border-emerald-100">
                                        <CheckCircle2 size={14} />
                                        Verified
                                    </span>
                                )}
                                {parseFloat(shift.expected_cash_amount) > parseFloat(shift.branch?.cash_drawer_limit || 5000) && (
                                    <span className="flex items-center gap-1.5 px-4 py-1.5 bg-rose-50 text-rose-700 text-xs font-bold rounded-full border border-rose-100 animate-pulse">
                                        <AlertCircle size={14} />
                                        Threshold Exceeded
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        
                        {/* Left Column: Financials & Events */}
                        <div className="lg:col-span-2 space-y-8">
                            
                            {/* Financial Breakdown */}
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                <div className="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                                    <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Opening Cash</p>
                                    <p className="text-lg font-bold text-gray-900">{formatCurrency(shift.opening_cash_amount)}</p>
                                </div>
                                <div className="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                                    <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Expected Cash</p>
                                    <p className="text-lg font-bold text-gray-900">{formatCurrency(shift.expected_cash_amount)}</p>
                                </div>
                                <div className="bg-white p-5 rounded-xl border border-gray-100 shadow-sm">
                                    <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Counted Cash</p>
                                    <p className="text-lg font-bold text-gray-900">{shift.status === 'open' ? '---' : formatCurrency(shift.counted_cash_amount)}</p>
                                </div>
                                <div className={`p-5 rounded-xl border shadow-sm ${parseFloat(shift.variance_amount) === 0 ? 'bg-emerald-50 border-emerald-100' : 'bg-rose-50 border-rose-100'}`}>
                                    <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Variance</p>
                                    <p className={`text-lg font-bold ${parseFloat(shift.variance_amount) === 0 ? 'text-emerald-700' : 'text-rose-700'}`}>
                                        {shift.status === 'open' ? '---' : formatCurrency(shift.variance_amount)}
                                    </p>
                                </div>
                            </div>

                            {/* Cash Drawer Events */}
                            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="px-6 py-4 border-b border-gray-50 flex items-center justify-between">
                                    <h4 className="font-bold text-gray-900 flex items-center gap-2">
                                        <History size={18} className="text-gray-400" />
                                        Cash Drawer Events
                                    </h4>
                                    <div className="flex items-center gap-3">
                                        {shift.status === 'open' && auth.permissions.includes('manage_cash_drawer') && (
                                            <button
                                                onClick={() => setShowEventModal(true)}
                                                className="text-xs font-bold text-indigo-600 hover:text-indigo-700 uppercase tracking-wider"
                                            >
                                                + Record Event
                                            </button>
                                        )}
                                        <span className="text-xs text-gray-500 font-medium">{shift.cash_drawer_events?.length || 0} Events</span>
                                    </div>
                                </div>
                                <div className="divide-y divide-gray-50">
                                    {shift.cash_drawer_events?.length > 0 ? shift.cash_drawer_events.map((event) => (
                                        <div key={event.id} className="px-6 py-4 flex items-center justify-between hover:bg-gray-50/50 transition-colors">
                                            <div className="flex items-center gap-4">
                                                <div className={`p-2 rounded-lg ${['cash_in', 'cash_top_up'].includes(event.event_type) ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600'}`}>
                                                    {['cash_in', 'cash_top_up'].includes(event.event_type) ? <ArrowUpCircle size={20} /> : <ArrowDownCircle size={20} />}
                                                </div>
                                                <div>
                                                    <p className="text-sm font-semibold text-gray-900 uppercase tracking-tight">{event.event_type.replace(/_/g, ' ')}</p>
                                                    <div className="flex items-center gap-2 mt-0.5">
                                                        <p className="text-xs text-gray-500">{event.reason_code || event.reason || 'No reason'}</p>
                                                        {event.created_by && (
                                                            <>
                                                                <span className="text-gray-300">•</span>
                                                                <p className="text-[10px] text-gray-400 font-medium">By {event.created_by.name}</p>
                                                            </>
                                                        )}
                                                    </div>
                                                    {event.reason_notes && <p className="text-[10px] text-gray-400 italic mt-1">"{event.reason_notes}"</p>}
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <p className={`text-sm font-bold ${['cash_in', 'cash_top_up'].includes(event.event_type) ? 'text-emerald-600' : 'text-rose-600'}`}>
                                                    {['cash_in', 'cash_top_up'].includes(event.event_type) ? '+' : '-'}{formatCurrency(event.amount)}
                                                </p>
                                                <p className="text-[10px] text-gray-400 mt-0.5 font-medium">{formatDate(event.created_at || event.occurred_at)}</p>
                                            </div>
                                        </div>
                                    )) : (
                                        <div className="px-6 py-12 text-center text-gray-400 italic">No drawer events recorded during this shift.</div>
                                    )}
                                </div>
                            </div>

                            {/* Spot Audits History */}
                            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="px-6 py-4 border-b border-gray-50 flex items-center justify-between">
                                    <h4 className="font-bold text-gray-900 flex items-center gap-2">
                                        <ShieldCheck size={18} className="text-gray-400" />
                                        Surprise Spot Audits
                                    </h4>
                                    <span className="text-xs text-gray-500 font-medium">{shift.spot_audits?.length || 0} Audits</span>
                                </div>
                                <div className="divide-y divide-gray-50">
                                    {shift.spot_audits?.length > 0 ? shift.spot_audits.map((audit) => (
                                        <div key={audit.id} className="px-6 py-4 flex items-center justify-between hover:bg-gray-50/50 transition-colors">
                                            <div>
                                                <p className="text-sm font-semibold text-gray-900">Spot Audit by {audit.manager?.name || 'Manager'}</p>
                                                <div className="flex items-center gap-2 mt-0.5">
                                                    <p className="text-xs text-gray-500">Counted: {formatCurrency(audit.counted_cash_amount)}</p>
                                                    <span className="text-gray-300">•</span>
                                                    <p className="text-xs text-gray-500">Expected: {formatCurrency(audit.expected_cash_amount)}</p>
                                                </div>
                                                {audit.audit_notes && <p className="text-[10px] text-gray-400 italic mt-1">"{audit.audit_notes}"</p>}
                                            </div>
                                            <div className="text-right">
                                                <span className={`inline-block px-2.5 py-1 text-[10px] font-bold rounded-full border ${parseFloat(audit.variance_amount) === 0 ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-100'}`}>
                                                    Variance: {formatCurrency(audit.variance_amount)}
                                                </span>
                                                <p className="text-[10px] text-gray-400 mt-1 font-medium">{formatDate(audit.occurred_at || audit.created_at)}</p>
                                            </div>
                                        </div>
                                    )) : (
                                        <div className="px-6 py-12 text-center text-gray-400 italic">No surprise spot audits conducted during this shift.</div>
                                    )}
                                </div>
                            </div>

                        </div>

                        {/* Right Column: Payments & Approval */}
                        <div className="space-y-8">
                            
                            {/* Cash Payments */}
                            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="px-6 py-4 border-b border-gray-50 flex items-center gap-2">
                                    <ShoppingCart size={18} className="text-gray-400" />
                                    <h4 className="font-bold text-gray-900">Cash Payments Collected</h4>
                                </div>
                                <div className="p-6">
                                    <div className="space-y-4">
                                        {shift.sale_payments?.length > 0 ? (
                                            <>
                                                <div className="flex justify-between items-center pb-4 border-b border-gray-100">
                                                    <span className="text-sm text-gray-500">Total Transactions</span>
                                                    <span className="text-sm font-bold text-gray-900">{shift.sale_payments.length}</span>
                                                </div>
                                                <div className="max-h-64 overflow-y-auto space-y-3 pr-2 scrollbar-thin scrollbar-thumb-gray-200">
                                                    {shift.sale_payments.map((p) => (
                                                        <div key={p.id} className="flex justify-between items-center text-xs">
                                                            <span className="text-gray-600 font-mono">#{p.sale?.sale_number || p.sale_id.split('-')[0]}</span>
                                                            <span className="font-semibold text-gray-800">{formatCurrency(p.amount)}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </>
                                        ) : (
                                            <div className="text-center py-4 text-gray-400 text-sm italic">No cash payments recorded.</div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Denomination Breakdown */}
                            {(shift.opening_denominations || shift.closing_denominations) && (
                                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                                    <div className="px-6 py-4 border-b border-gray-50 flex items-center gap-2">
                                        <FileText size={18} className="text-gray-400" />
                                        <h4 className="font-bold text-gray-900">Cash Count Audit</h4>
                                    </div>
                                    <div className="p-6">
                                        <div className="grid grid-cols-2 gap-8">
                                            <div>
                                                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-4">Opening Breakout</p>
                                                {shift.opening_denominations ? (
                                                    <div className="space-y-1">
                                                        {Object.entries(shift.opening_denominations).filter(([_, count]) => count > 0).map(([val, count]) => (
                                                            <div key={val} className="flex justify-between text-xs py-1 border-b border-gray-50 last:border-0">
                                                                <span className="text-gray-500">₱{val} × {count}</span>
                                                                <span className="font-bold text-gray-700">{formatCurrency(parseFloat(val) * count)}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                ) : <p className="text-xs text-gray-400 italic">No breakdown available.</p>}
                                            </div>
                                            <div>
                                                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-4">Closing Breakout</p>
                                                {shift.closing_denominations ? (
                                                    <div className="space-y-1">
                                                        {Object.entries(shift.closing_denominations).filter(([_, count]) => count > 0).map(([val, count]) => (
                                                            <div key={val} className="flex justify-between text-xs py-1 border-b border-gray-50 last:border-0">
                                                                <span className="text-gray-500">₱{val} × {count}</span>
                                                                <span className="font-bold text-gray-700">{formatCurrency(parseFloat(val) * count)}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                ) : <p className="text-xs text-gray-400 italic">No breakdown available.</p>}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Approval Status */}
                            {shift.status !== 'open' && (
                                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                                    <div className="px-6 py-4 border-b border-gray-50 flex items-center gap-2">
                                        <ShieldCheck size={18} className="text-gray-400" />
                                        <h4 className="font-bold text-gray-900">Manager Review</h4>
                                    </div>
                                    <div className="p-6 space-y-6">
                                        {shift.status === 'approved' ? (
                                            <>
                                                <div className="flex items-center gap-3">
                                                    <div className="w-10 h-10 bg-emerald-50 text-emerald-600 rounded-full flex items-center justify-center">
                                                        <CheckCircle2 size={24} />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-bold text-gray-900">Approved by {shift.approved_by_user?.name}</p>
                                                        <p className="text-[10px] text-gray-400 font-medium uppercase tracking-tighter">{formatDate(shift.approved_at)}</p>
                                                    </div>
                                                </div>
                                                {shift.manager_notes && (
                                                    <div className="p-4 bg-gray-50 rounded-lg">
                                                        <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Review Notes</p>
                                                        <p className="text-sm text-gray-600 italic leading-relaxed">"{shift.manager_notes}"</p>
                                                    </div>
                                                )}
                                                {shift.deposit_record && (
                                                    <div className="p-4 bg-indigo-50/50 border border-indigo-100 rounded-xl space-y-3 mt-4">
                                                        <p className="text-[10px] font-bold text-indigo-500 uppercase tracking-widest">Bank Deposit Voucher</p>
                                                        <div className="grid grid-cols-2 gap-y-2 text-xs">
                                                            <span className="text-gray-500">Deposit Amount:</span>
                                                            <span className="font-semibold text-gray-800 text-right">{formatCurrency(shift.deposit_record.deposit_amount)}</span>
                                                            
                                                            <span className="text-gray-500">Bank Name:</span>
                                                            <span className="font-semibold text-gray-800 text-right">{shift.deposit_record.bank_name || 'N/A'}</span>
                                                            
                                                            <span className="text-gray-500">Reference No:</span>
                                                            <span className="font-semibold text-gray-800 text-right font-mono">{shift.deposit_record.reference_number || 'N/A'}</span>
                                                            
                                                            <span className="text-gray-500">Deposited At:</span>
                                                            <span className="font-semibold text-gray-800 text-right">{shift.deposit_record.deposited_at ? new Date(shift.deposit_record.deposited_at).toLocaleDateString('en-PH') : 'N/A'}</span>
                                                        </div>
                                                        {shift.deposit_record.variance_explanation && (
                                                            <div className="mt-2 pt-2 border-t border-indigo-100/50 text-[11px] text-gray-600">
                                                                <p className="font-bold text-gray-400 uppercase tracking-wider mb-0.5">Variance Explanation:</p>
                                                                <p className="italic">"{shift.deposit_record.variance_explanation}"</p>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </>
                                        ) : (
                                            <div className="flex items-center gap-3 text-amber-600">
                                                <AlertCircle size={24} />
                                                <p className="text-sm font-semibold">Awaiting manager reconciliation.</p>
                                            </div>
                                        )}

                                        {shift.closing_notes && (
                                            <div className="p-4 bg-blue-50/50 rounded-lg border border-blue-100/50">
                                                <p className="text-[10px] font-bold text-blue-400 uppercase tracking-widest mb-2">Cashier Closing Notes</p>
                                                <p className="text-sm text-blue-700 italic leading-relaxed">"{shift.closing_notes}"</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                        </div>

                    </div>
                </div>
            </div>
            {confirmOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                        <div className="fixed inset-0 transition-opacity" aria-hidden="true" onClick={() => setConfirmOpen(false)}>
                            <div className="absolute inset-0 bg-gray-500 opacity-75"></div>
                        </div>

                        <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                        <div className="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-xl sm:w-full">
                            <form onSubmit={handleConfirmApprove} className="p-6 sm:p-8 space-y-6">
                                <div className="flex items-center justify-between mb-4 border-b border-gray-100 pb-4">
                                    <h3 className="text-xl font-bold text-gray-900">Approve Shift & Record Deposit</h3>
                                    <button type="button" onClick={() => setConfirmOpen(false)} className="p-2 hover:bg-gray-100 rounded-full transition-colors">
                                        <X size={20} className="text-gray-400" />
                                    </button>
                                </div>

                                <div className="space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Expected Cash</label>
                                            <p className="text-sm font-semibold text-gray-800">{formatCurrency(shift.expected_cash_amount)}</p>
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-1">Counted Cash</label>
                                            <p className="text-sm font-semibold text-gray-800">{formatCurrency(shift.counted_cash_amount)}</p>
                                        </div>
                                    </div>

                                    {parseFloat(shift.variance_amount) !== 0 && (
                                        <div className="p-3 bg-amber-50 border border-amber-100 rounded-lg text-xs text-amber-800 font-medium">
                                            Warning: A variance of {formatCurrency(shift.variance_amount)} was reported.
                                        </div>
                                    )}

                                    <div>
                                        <label htmlFor="deposit_amount" className="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2">
                                            Actual Bank Deposit Amount (PHP) <span className="text-red-500">*</span>
                                        </label>
                                        <input
                                            type="number"
                                            id="deposit_amount"
                                            step="0.01"
                                            className="block w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500 transition-all text-sm font-medium"
                                            required
                                            value={depositAmount}
                                            onChange={(e) => setDepositAmount(e.target.value)}
                                        />
                                    </div>

                                    <div>
                                        <label htmlFor="bank_name" className="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2">
                                            Destination Bank Name
                                        </label>
                                        <input
                                            type="text"
                                            id="bank_name"
                                            placeholder="e.g. BDO, BPI"
                                            className="block w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500 transition-all text-sm"
                                            value={bankName}
                                            onChange={(e) => setBankName(e.target.value)}
                                        />
                                    </div>

                                    <div>
                                        <label htmlFor="reference_number" className="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2">
                                            Bank Reference / Slip Number
                                        </label>
                                        <input
                                            type="text"
                                            id="reference_number"
                                            placeholder="e.g. Reference/Transaction Number"
                                            className="block w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500 transition-all text-sm"
                                            value={referenceNumber}
                                            onChange={(e) => setReferenceNumber(e.target.value)}
                                        />
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <label htmlFor="deposited_at" className="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2">
                                                Deposit Date
                                            </label>
                                            <input
                                                type="date"
                                                id="deposited_at"
                                                className="block w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500 transition-all text-sm"
                                                value={depositedAt}
                                                onChange={(e) => setDepositedAt(e.target.value)}
                                            />
                                        </div>
                                    </div>

                                    {parseFloat(shift.variance_amount) !== 0 && (
                                        <div>
                                            <label htmlFor="variance_explanation" className="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2">
                                                Variance Explanation <span className="text-red-500">*</span>
                                            </label>
                                            <textarea
                                                id="variance_explanation"
                                                className="block w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500 transition-all text-sm"
                                                rows="2"
                                                placeholder="Explain the cause of variance..."
                                                required={parseFloat(shift.variance_amount) !== 0}
                                                value={varianceExplanation}
                                                onChange={(e) => setVarianceExplanation(e.target.value)}
                                            ></textarea>
                                        </div>
                                    )}

                                    <div>
                                        <label htmlFor="manager_notes" className="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2">
                                            Manager Review Notes
                                        </label>
                                        <textarea
                                            id="manager_notes"
                                            className="block w-full px-4 py-2 bg-gray-50 border border-gray-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500 transition-all text-sm"
                                            rows="2"
                                            placeholder="General notes for approval..."
                                            value={managerNotes}
                                            onChange={(e) => setManagerNotes(e.target.value)}
                                        ></textarea>
                                    </div>
                                </div>

                                <div className="mt-6 flex flex-col sm:flex-row-reverse gap-3 border-t border-gray-100 pt-4">
                                    <button
                                        type="submit"
                                        className="w-full sm:w-auto inline-flex items-center justify-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-xl font-bold text-white text-xs uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none transition-all shadow-md"
                                    >
                                        <CheckCircle2 className="w-4 h-4 mr-2" />
                                        Approve Shift
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setConfirmOpen(false)}
                                        className="w-full sm:w-auto inline-flex items-center justify-center px-6 py-2.5 bg-white border border-gray-200 rounded-xl font-bold text-gray-700 text-xs uppercase tracking-widest hover:bg-gray-50 focus:outline-none transition-all"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function ShieldCheck({ size, className }) {
    return (
        <svg 
            xmlns="http://www.w3.org/2000/svg" 
            width={size} 
            height={size} 
            viewBox="0 0 24 24" 
            fill="none" 
            stroke="currentColor" 
            strokeWidth="2" 
            strokeLinecap="round" 
            strokeLinejoin="round" 
            className={className}
        >
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
            <path d="m9 12 2 2 4-4" />
        </svg>
    );
}
