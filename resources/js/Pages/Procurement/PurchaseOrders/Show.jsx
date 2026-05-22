import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    ArrowLeft,
    CheckCircle2,
    XCircle,
    Send,
    ThumbsUp,
    FileText,
    Building2,
    Truck,
    Calendar,
    DollarSign,
    Clock,
    User,
    ClipboardList,
    Ban
} from 'lucide-react';

export default function Show({ auth, purchaseOrder }) {
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [pendingAction, setPendingAction] = useState(null);

    const handleTransition = (routeSuffix) => {
        setPendingAction(routeSuffix);
        setConfirmOpen(true);
    };

    const handleConfirm = () => {
        if (pendingAction) {
            router.post(route(`procurement.purchase-orders.${pendingAction}`, purchaseOrder.id));
        }
        setConfirmOpen(false);
        setPendingAction(null);
    };

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 4,
            maximumFractionDigits: 4,
        }).format(val);
    };

    const getStatusStyle = (status) => {
        switch (status) {
            case 'draft':
                return 'bg-slate-100 text-slate-700 border-slate-200';
            case 'pending_approval':
                return 'bg-amber-50 text-amber-700 border-amber-200';
            case 'approved':
                return 'bg-sky-50 text-sky-700 border-sky-200';
            case 'sent':
                return 'bg-indigo-50 text-indigo-700 border-indigo-200';
            case 'completed':
                return 'bg-emerald-50 text-emerald-700 border-emerald-200';
            case 'cancelled':
                return 'bg-rose-50 text-rose-700 border-rose-200';
            default:
                return 'bg-slate-50 text-slate-500 border-slate-100';
        }
    };

    const getStatusLabel = (status) => {
        return status.toUpperCase().replace('_', ' ');
    };

    // Helper checks for workflow triggers
    const isDraft = purchaseOrder.status === 'draft';
    const isPendingApproval = purchaseOrder.status === 'pending_approval';
    const isApproved = purchaseOrder.status === 'approved';
    const isSent = purchaseOrder.status === 'sent';
    const isTerminal = ['completed', 'cancelled'].includes(purchaseOrder.status);

    const hasCreatePerm = auth.permissions.includes('procurement.purchase-orders.create');
    const hasApprovePerm = auth.permissions.includes('procurement.purchase-orders.approve');

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('procurement.purchase-orders.index')}
                            className="p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl transition-all"
                            title="Back to List"
                        >
                            <ArrowLeft size={16} />
                        </Link>
                        <div>
                            <div className="flex items-center gap-3">
                                <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">
                                    {purchaseOrder.po_number}
                                </h2>
                                <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black border uppercase tracking-widest ${getStatusStyle(purchaseOrder.status)}`}>
                                    <Clock size={10} />
                                    {getStatusLabel(purchaseOrder.status)}
                                </span>
                            </div>
                            <p className="text-xs text-slate-400 font-bold mt-1 uppercase tracking-widest">
                                PO ID: {purchaseOrder.id}
                            </p>
                        </div>
                    </div>

                    {/* Active Transition Buttons */}
                    <div className="flex items-center gap-2 flex-wrap">
                        {isDraft && hasCreatePerm && (
                            <>
                                <button
                                    onClick={() => handleTransition('submit')}
                                    className="inline-flex items-center gap-2 px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                                >
                                    <ThumbsUp size={16} />
                                    Submit for Approval
                                </button>
                                <button
                                    onClick={() => handleTransition('cancel')}
                                    className="inline-flex items-center gap-2 px-5 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                                >
                                    <Ban size={16} />
                                    Cancel PO
                                </button>
                            </>
                        )}

                        {isPendingApproval && (
                            <>
                                {hasApprovePerm && (
                                    <button
                                        onClick={() => handleTransition('approve')}
                                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-sky-500 hover:bg-sky-600 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                                    >
                                        <CheckCircle2 size={16} />
                                        Approve Order
                                    </button>
                                )}
                                {hasCreatePerm && (
                                    <button
                                        onClick={() => handleTransition('cancel')}
                                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                                    >
                                        <Ban size={16} />
                                        Cancel PO
                                    </button>
                                )}
                            </>
                        )}

                        {isApproved && hasCreatePerm && (
                            <>
                                <button
                                    onClick={() => handleTransition('send')}
                                    className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                                >
                                    <Send size={16} />
                                    Send to Supplier
                                </button>
                                <button
                                    onClick={() => handleTransition('cancel')}
                                    className="inline-flex items-center gap-2 px-5 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                                >
                                    <Ban size={16} />
                                    Cancel PO
                                </button>
                            </>
                        )}

                        {isSent && hasCreatePerm && (
                            <>
                                <button
                                    onClick={() => handleTransition('complete')}
                                    className="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                                >
                                    <CheckCircle2 size={16} />
                                    Complete PO
                                </button>
                                <button
                                    onClick={() => handleTransition('cancel')}
                                    className="inline-flex items-center gap-2 px-5 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                                >
                                    <Ban size={16} />
                                    Cancel PO
                                </button>
                            </>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Purchase Order - ${purchaseOrder.po_number}`} />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* General Metadata cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        {/* Branch info */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm flex items-start gap-4">
                            <div className="p-3 bg-indigo-50 text-indigo-600 rounded-2xl">
                                <Building2 size={24} />
                            </div>
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Target Branch</h3>
                                <span className="text-base font-extrabold text-slate-800 mt-1 block">{purchaseOrder.branch?.name}</span>
                                <span className="text-xs text-slate-400 font-bold tracking-widest mt-0.5 block uppercase">CODE: {purchaseOrder.branch?.branch_code}</span>
                            </div>
                        </div>

                        {/* Supplier info */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm flex items-start gap-4">
                            <div className="p-3 bg-sky-50 text-sky-600 rounded-2xl">
                                <Truck size={24} />
                            </div>
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Target Supplier</h3>
                                <span className="text-base font-extrabold text-slate-800 mt-1 block">{purchaseOrder.supplier?.name}</span>
                                <span className="text-xs text-slate-400 font-bold tracking-widest mt-0.5 block uppercase">CODE: {purchaseOrder.supplier?.code}</span>
                            </div>
                        </div>

                        {/* Order timeline details */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm flex items-start gap-4">
                            <div className="p-3 bg-emerald-50 text-emerald-600 rounded-2xl">
                                <Calendar size={24} />
                            </div>
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Lifecycle Dates</h3>
                                <div className="text-xs text-slate-500 font-semibold mt-1.5 flex flex-col gap-1">
                                    <span>Ordered: <strong className="text-slate-700">{new Date(purchaseOrder.order_date).toLocaleDateString('en-US', { dateStyle: 'medium' })}</strong></span>
                                    {purchaseOrder.expected_delivery_date && (
                                        <span>Delivery target: <strong className="text-slate-700">{new Date(purchaseOrder.expected_delivery_date).toLocaleDateString('en-US', { dateStyle: 'medium' })}</strong></span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Line Items Table */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden mb-8">
                        <div className="px-8 py-6 border-b border-slate-50 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <ClipboardList size={18} className="text-indigo-600" />
                                <h3 className="font-extrabold text-slate-800 text-base">Purchase Order Items</h3>
                            </div>
                            <span className="text-xs font-black uppercase text-slate-400 tracking-wider">
                                {purchaseOrder.lines?.length} Items listed
                            </span>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/30">
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Product SKU</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Product Name</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Ordered Qty</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Unit Cost</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {purchaseOrder.lines?.map((line) => (
                                        <tr key={line.id} className="hover:bg-slate-50/20 transition-colors">
                                            <td className="px-8 py-4 font-black text-xs text-slate-500 uppercase tracking-widest">
                                                <span className="px-2 py-0.5 bg-slate-100 rounded">
                                                    {line.product?.sku}
                                                </span>
                                            </td>
                                            <td className="px-8 py-4 text-sm font-bold text-slate-700">
                                                {line.product?.name}
                                            </td>
                                            <td className="px-8 py-4 text-sm font-extrabold text-slate-600 text-center">
                                                {Number(line.ordered_quantity).toLocaleString('en-US')}
                                            </td>
                                            <td className="px-8 py-4 text-sm font-bold text-slate-600 text-right">
                                                {formatCurrency(line.unit_cost)}
                                            </td>
                                            <td className="px-8 py-4 text-sm font-black text-slate-800 text-right">
                                                {formatCurrency(line.line_total)}
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="bg-slate-50/50">
                                        <td colSpan="4" className="px-8 py-5 text-right text-xs font-black uppercase text-slate-400 tracking-wider">
                                            Estimated Total Amount
                                        </td>
                                        <td className="px-8 py-5 text-right font-black text-lg text-indigo-600">
                                            {formatCurrency(purchaseOrder.total_estimated_amount)}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Notes & Audit Sign-offs */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {/* Notes card */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm">
                            <h4 className="font-extrabold text-slate-800 text-sm uppercase tracking-wider mb-3">Order Notes & Instructions</h4>
                            <div className="text-sm text-slate-600 font-medium leading-relaxed bg-slate-50 rounded-2xl p-4 min-h-[100px]">
                                {purchaseOrder.notes || <span className="text-slate-300 italic">No notes provided for this purchase order.</span>}
                            </div>
                        </div>

                        {/* Audit Trail Signatures */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm flex flex-col gap-4">
                            <h4 className="font-extrabold text-slate-800 text-sm uppercase tracking-wider">Audit Signatures</h4>
                            
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-indigo-50 text-indigo-600 rounded-xl">
                                    <User size={16} />
                                </div>
                                <div className="text-xs">
                                    <span className="text-slate-400 font-bold uppercase block tracking-wider">Created By</span>
                                    <span className="text-slate-700 font-extrabold text-sm block mt-0.5">{purchaseOrder.created_by?.name || 'System / Batch'}</span>
                                </div>
                            </div>

                            {purchaseOrder.approved_by && (
                                <div className="flex items-center gap-3 border-t border-slate-50 pt-3">
                                    <div className="p-2 bg-emerald-50 text-emerald-600 rounded-xl">
                                        <User size={16} />
                                    </div>
                                    <div className="text-xs">
                                        <span className="text-slate-400 font-bold uppercase block tracking-wider">Approved By</span>
                                        <span className="text-slate-700 font-extrabold text-sm block mt-0.5">{purchaseOrder.approved_by?.name}</span>
                                        <span className="text-slate-400 block mt-0.5">Approved at: {new Date(purchaseOrder.approved_at).toLocaleString()}</span>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
            <PremiumDialog
                isOpen={confirmOpen}
                type={pendingAction === 'cancel' ? 'danger' : 'info'}
                title={pendingAction === 'cancel' ? 'Cancel Purchase Order' : 'Execute Status Transition'}
                message={
                    pendingAction === 'cancel'
                        ? 'Are you sure you want to cancel this purchase order? This action is terminal and will release any locked replenishment schedules.'
                        : 'Are you sure you want to perform this lifecycle transition?'
                }
                confirmLabel={pendingAction === 'cancel' ? 'Cancel Order' : 'Proceed'}
                onConfirm={handleConfirm}
                onCancel={() => {
                    setConfirmOpen(false);
                    setPendingAction(null);
                }}
            />
        </AuthenticatedLayout>
    );
}
