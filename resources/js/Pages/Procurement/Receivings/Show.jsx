import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    ArrowLeft,
    CheckCircle2,
    XCircle,
    FileCheck,
    Building2,
    Truck,
    Calendar,
    DollarSign,
    Clock,
    User,
    ClipboardList,
    Ban,
    Lock,
    Edit2,
    Link as LinkIcon
} from 'lucide-react';

export default function Show({ auth, purchaseReceiving }) {
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [pendingAction, setPendingAction] = useState(null);

    const handlePost = () => {
        setPendingAction('post');
        setConfirmOpen(true);
    };

    const handleCancel = () => {
        setPendingAction('cancel');
        setConfirmOpen(true);
    };

    const handleConfirm = () => {
        if (pendingAction === 'post') {
            router.post(route('procurement.receivings.post', purchaseReceiving.id));
        } else if (pendingAction === 'cancel') {
            router.post(route('procurement.receivings.cancel', purchaseReceiving.id));
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
            case 'posted':
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

    const isDraft = purchaseReceiving.status === 'draft';
    const isPosted = purchaseReceiving.status === 'posted';
    const isCancelled = purchaseReceiving.status === 'cancelled';

    const hasCreatePerm = auth.permissions.includes('procurement.receiving.create');
    const hasPostPerm = auth.permissions.includes('procurement.receiving.post');

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('procurement.receivings.index')}
                            className="p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl transition-all"
                            title="Back to List"
                        >
                            <ArrowLeft size={16} />
                        </Link>
                        <div>
                            <div className="flex items-center gap-3">
                                <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">
                                    {purchaseReceiving.receiving_number}
                                </h2>
                                <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black border uppercase tracking-widest ${getStatusStyle(purchaseReceiving.status)}`}>
                                    <Clock size={10} />
                                    {getStatusLabel(purchaseReceiving.status)}
                                </span>
                            </div>
                            <p className="text-xs text-slate-400 font-bold mt-1 uppercase tracking-widest">
                                GRV ID: {purchaseReceiving.id}
                            </p>
                        </div>
                    </div>

                    {/* Active Actions */}
                    <div className="flex items-center gap-2 flex-wrap">
                        {isDraft && hasCreatePerm && (
                            <Link
                                href={route('procurement.receivings.edit', purchaseReceiving.id)}
                                className="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-xl font-bold text-sm shadow-sm transition-all active:scale-95"
                            >
                                <Edit2 size={16} />
                                Edit Draft
                            </Link>
                        )}

                        {isDraft && hasPostPerm && (
                            <button
                                onClick={handlePost}
                                className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                            >
                                <CheckCircle2 size={16} />
                                Post GRV
                            </button>
                        )}

                        {isDraft && hasCreatePerm && (
                            <button
                                onClick={handleCancel}
                                className="inline-flex items-center gap-2 px-5 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-bold text-sm shadow-md transition-all active:scale-95"
                            >
                                <Ban size={16} />
                                Cancel Voucher
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Goods Receiving Voucher - ${purchaseReceiving.receiving_number}`} />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Metadata cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        {/* Branch info */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm flex items-start gap-4">
                            <div className="p-3 bg-indigo-50 text-indigo-600 rounded-2xl">
                                <Building2 size={24} />
                            </div>
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Target Branch</h3>
                                <span className="text-base font-extrabold text-slate-800 mt-1 block">{purchaseReceiving.branch?.name}</span>
                                <span className="text-xs text-slate-400 font-bold tracking-widest mt-0.5 block uppercase">CODE: {purchaseReceiving.branch?.branch_code}</span>
                            </div>
                        </div>

                        {/* Supplier info */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm flex items-start gap-4">
                            <div className="p-3 bg-sky-50 text-sky-600 rounded-2xl">
                                <Truck size={24} />
                            </div>
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Target Supplier</h3>
                                <span className="text-base font-extrabold text-slate-800 mt-1 block">{purchaseReceiving.supplier?.name}</span>
                                <span className="text-xs text-slate-400 font-bold tracking-widest mt-0.5 block uppercase">CODE: {purchaseReceiving.supplier?.code}</span>
                            </div>
                        </div>

                        {/* Dates / Reference details */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm flex items-start gap-4">
                            <div className="p-3 bg-emerald-50 text-emerald-600 rounded-2xl">
                                <Calendar size={24} />
                            </div>
                            <div>
                                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400">Receiving Info</h3>
                                <div className="text-xs text-slate-500 font-semibold mt-1.5 flex flex-col gap-1">
                                    <span>Received: <strong className="text-slate-700">{new Date(purchaseReceiving.received_at).toLocaleDateString('en-US', { dateStyle: 'medium' })}</strong></span>
                                    {purchaseReceiving.delivery_ref_number && (
                                        <span>Delivery Ref: <strong className="text-slate-700">{purchaseReceiving.delivery_ref_number}</strong></span>
                                    )}
                                    {purchaseReceiving.purchase_order && (
                                        <span className="flex items-center gap-1">
                                            Linked PO: 
                                            <Link 
                                                href={route('procurement.purchase-orders.show', purchaseReceiving.purchase_order_id)}
                                                className="text-indigo-600 font-bold hover:underline"
                                            >
                                                {purchaseReceiving.purchase_order.po_number}
                                            </Link>
                                        </span>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Table items */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden mb-8">
                        <div className="px-8 py-6 border-b border-slate-50 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <ClipboardList size={18} className="text-indigo-600" />
                                <h3 className="font-extrabold text-slate-800 text-base">Received Voucher Items</h3>
                            </div>
                            <span className="text-xs font-black uppercase text-slate-400 tracking-wider">
                                {purchaseReceiving.lines?.length} items listed
                            </span>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/30">
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">SKU</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Product Name</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Ordered Qty</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Received Qty</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Lot/Batch No.</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Expiry Date</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Unit Cost</th>
                                        <th className="px-8 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {purchaseReceiving.lines?.map((line) => (
                                        <tr key={line.id} className="hover:bg-slate-50/20 transition-colors">
                                            <td className="px-8 py-4 font-black text-xs text-slate-500 uppercase tracking-widest">
                                                <span className="px-2 py-0.5 bg-slate-100 rounded">
                                                    {line.product?.sku}
                                                </span>
                                            </td>
                                            <td className="px-8 py-4 text-sm font-bold text-slate-700">
                                                {line.product?.name}
                                            </td>
                                            <td className="px-8 py-4 text-sm font-semibold text-slate-400 text-center">
                                                {line.ordered_quantity ? Number(line.ordered_quantity).toLocaleString('en-US') : '-'}
                                            </td>
                                            <td className="px-8 py-4 text-sm font-extrabold text-slate-800 text-center bg-indigo-50/10">
                                                {Number(line.received_quantity).toLocaleString('en-US')}
                                            </td>
                                            <td className="px-8 py-4 text-sm font-medium text-slate-600">
                                                {line.lot_number || <span className="text-slate-300 italic">None</span>}
                                            </td>
                                            <td className="px-8 py-4 text-sm font-semibold text-slate-500">
                                                {line.expiry_date ? new Date(line.expiry_date).toLocaleDateString('en-US', { dateStyle: 'medium' }) : <span className="text-slate-300 italic">None</span>}
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
                                        <td colSpan="7" className="px-8 py-5 text-right text-xs font-black uppercase text-slate-400 tracking-wider">
                                            Total Received Value
                                        </td>
                                        <td className="px-8 py-5 text-right font-black text-lg text-indigo-600">
                                            {formatCurrency(purchaseReceiving.total_received_amount)}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Notes & Signatures */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {/* Notes card */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm">
                            <h4 className="font-extrabold text-slate-800 text-sm uppercase tracking-wider mb-3">Receiving Notes</h4>
                            <div className="text-sm text-slate-600 font-medium leading-relaxed bg-slate-50 rounded-2xl p-4 min-h-[100px]">
                                {purchaseReceiving.notes || <span className="text-slate-300 italic">No notes provided for this receiving voucher.</span>}
                            </div>
                        </div>

                        {/* Audit trail */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm flex flex-col gap-4">
                            <h4 className="font-extrabold text-slate-800 text-sm uppercase tracking-wider">Audit Signatures</h4>
                            
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-indigo-50 text-indigo-600 rounded-xl">
                                    <User size={16} />
                                </div>
                                <div className="text-xs">
                                    <span className="text-slate-400 font-bold uppercase block tracking-wider">Received By (Drafted)</span>
                                    <span className="text-slate-700 font-extrabold text-sm block mt-0.5">{purchaseReceiving.received_by?.name || 'System'}</span>
                                    <span className="text-slate-400 block mt-0.5">Date: {new Date(purchaseReceiving.created_at).toLocaleString()}</span>
                                </div>
                            </div>

                            {purchaseReceiving.posted_by && (
                                <div className="flex items-center gap-3 border-t border-slate-50 pt-3">
                                    <div className="p-2 bg-emerald-50 text-emerald-600 rounded-xl">
                                        <User size={16} />
                                    </div>
                                    <div className="text-xs">
                                        <span className="text-slate-400 font-bold uppercase block tracking-wider">Posted & Valuation Committed By</span>
                                        <span className="text-slate-700 font-extrabold text-sm block mt-0.5">{purchaseReceiving.posted_by?.name}</span>
                                        <span className="text-slate-400 block mt-0.5">Posted at: {new Date(purchaseReceiving.posted_at).toLocaleString()}</span>
                                    </div>
                                </div>
                            )}

                            {purchaseReceiving.cancelled_at && (
                                <div className="flex items-center gap-3 border-t border-slate-50 pt-3">
                                    <div className="p-2 bg-rose-50 text-rose-600 rounded-xl">
                                        <User size={16} />
                                    </div>
                                    <div className="text-xs">
                                        <span className="text-slate-400 font-bold uppercase block tracking-wider">Cancelled At</span>
                                        <span className="text-slate-400 block mt-0.5">Cancelled at: {new Date(purchaseReceiving.cancelled_at).toLocaleString()}</span>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
            <PremiumDialog
                isOpen={confirmOpen}
                type={pendingAction === 'cancel' ? 'danger' : 'success'}
                title={pendingAction === 'cancel' ? 'Cancel Receiving Voucher' : 'Post Goods Receiving Voucher'}
                message={
                    pendingAction === 'cancel'
                        ? 'Are you sure you want to cancel this Goods Receiving Voucher? This will permanently discard this draft.'
                        : 'WARNING: Posting will update branch inventory levels and permanently recalculate Weighted Average Cost (WAC). This action CANNOT be undone. Are you sure you want to proceed?'
                }
                confirmLabel={pendingAction === 'cancel' ? 'Cancel Voucher' : 'Post Voucher'}
                onConfirm={handleConfirm}
                onCancel={() => {
                    setConfirmOpen(false);
                    setPendingAction(null);
                }}
            />
        </AuthenticatedLayout>
    );
}
