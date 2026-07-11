import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import VoidRefundModal from './Components/VoidRefundModal';
import { useConnectivityStore } from '@/POS/offline/connectivityStore';
import {
    Clock,
    User as UserIcon,
    Store,
    Receipt,
    CreditCard,
    CheckCircle2,
    XCircle,
    History,
    Package,
    ShieldCheck,
    Hash
} from 'lucide-react';

const StatusBadge = ({ status }) => {
    const statusConfig = {
        paid: { color: 'bg-emerald-100 text-emerald-800 border-emerald-200', icon: CheckCircle2 },
        voided: { color: 'bg-rose-100 text-rose-800 border-rose-200', icon: XCircle },
        refunded: { color: 'bg-amber-100 text-amber-800 border-amber-200', icon: History },
        draft: { color: 'bg-slate-100 text-slate-800 border-slate-200', icon: CheckCircle2 },
    };

    const config = statusConfig[status] || statusConfig.draft;
    const Icon = config.icon;

    return (
        <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border ${config.color}`}>
            <Icon size={14} />
            {status.toUpperCase()}
        </span>
    );
};

export default function Show({ auth, sale }) {
    const { isOffline } = useConnectivityStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [modalMode, setModalMode] = useState('void'); // 'void' or 'refund'

    const handleOpenModal = (mode) => {
        setModalMode(mode);
        setModalOpen(true);
    };

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
        }).format(parseFloat(val || 0));
    };

    const formatDate = (dateStr) => {
        const date = new Date(dateStr);
        return date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-4">
                        <Link
                            href={route('sales.history.index')}
                            className="p-2 hover:bg-slate-100 rounded-full transition-colors text-slate-500"
                        >
                            <ArrowLeft size={20} />
                        </Link>
                        <div className="space-y-1">
                            <div className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-600">
                                Transaction Detail
                            </div>
                            <h2 className="text-2xl font-bold leading-tight text-slate-900">{sale.sale_number}</h2>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <StatusBadge status={sale.status} />
                        {sale.status === 'paid' && (
                            <div className="flex items-center gap-2">
                                <button
                                    onClick={() => handleOpenModal('void')}
                                    disabled={isOffline}
                                    className="px-4 py-2 text-xs font-bold text-rose-600 bg-rose-50 border border-rose-200 hover:bg-rose-100 disabled:opacity-50 disabled:bg-slate-100 disabled:border-slate-200 disabled:text-slate-400 rounded-xl transition-all"
                                >
                                    Void Sale
                                </button>
                                <button
                                    onClick={() => handleOpenModal('refund')}
                                    disabled={isOffline}
                                    className="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:bg-slate-300 disabled:text-slate-400 rounded-xl transition-all"
                                >
                                    Refund Items
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Sale ${sale.sale_number}`} />

            <div className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-8">
                    {/* Top Stats/Metadata Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-3">
                            <div className="flex items-center gap-2 text-slate-400">
                                <Clock size={16} />
                                <span className="text-xs font-bold uppercase tracking-wider">Timestamp</span>
                            </div>
                            <p className="text-sm font-semibold text-slate-900 leading-relaxed">
                                {formatDate(sale.confirmed_at || sale.created_at)}
                            </p>
                        </div>
                        <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-3">
                            <div className="flex items-center gap-2 text-slate-400">
                                <Store size={16} />
                                <span className="text-xs font-bold uppercase tracking-wider">Branch</span>
                            </div>
                            <p className="text-sm font-semibold text-slate-900">
                                {sale.branch?.name || 'Main Branch'}
                            </p>
                        </div>
                        <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-3">
                            <div className="flex items-center gap-2 text-slate-400">
                                <UserIcon size={16} />
                                <span className="text-xs font-bold uppercase tracking-wider">Cashier</span>
                            </div>
                            <p className="text-sm font-semibold text-slate-900">
                                {sale.user?.name || 'System'}
                            </p>
                        </div>
                        <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-3">
                            <div className="flex items-center gap-2 text-slate-400">
                                <ShieldCheck size={16} />
                                <span className="text-xs font-bold uppercase tracking-wider">Isolation ID</span>
                            </div>
                            <p className="text-[10px] font-mono text-slate-500 break-all leading-tight">
                                {sale.client_request_uuid}
                            </p>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
                        {/* Items Section */}
                        <div className="lg:col-span-2 space-y-6">
                            <div className="bg-white rounded-[28px] shadow-sm border border-slate-200 overflow-hidden">
                                <div className="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                                    <div className="flex items-center gap-2">
                                        <Package size={18} className="text-slate-400" />
                                        <h3 className="text-sm font-bold text-slate-900 uppercase tracking-widest">Line Items</h3>
                                    </div>
                                    <span className="text-xs font-semibold text-slate-500">
                                        {sale.items?.length || 0} Products
                                    </span>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left">
                                        <thead>
                                            <tr className="border-b border-slate-50">
                                                <th className="px-6 py-3 text-[11px] font-bold text-slate-400 uppercase tracking-widest">Product</th>
                                                <th className="px-6 py-3 text-[11px] font-bold text-slate-400 uppercase tracking-widest text-center">Qty</th>
                                                <th className="px-6 py-3 text-[11px] font-bold text-slate-400 uppercase tracking-widest text-right">Unit Price</th>
                                                <th className="px-6 py-3 text-[11px] font-bold text-slate-400 uppercase tracking-widest text-right">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-50">
                                            {sale.items?.map((item) => (
                                                <tr key={item.id} className="group">
                                                    <td className="px-6 py-4">
                                                        <div className="text-sm font-bold text-slate-900">{item.product_name}</div>
                                                        <div className="text-[10px] text-slate-400 mt-0.5">SKU: {item.sku || 'N/A'}</div>
                                                    </td>
                                                    <td className="px-6 py-4 text-center">
                                                        <span className="text-sm font-semibold text-slate-600">
                                                            {parseFloat(item.quantity).toFixed(0)}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 text-right">
                                                        <span className="text-sm text-slate-600">
                                                            {formatCurrency(item.unit_price)}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 text-right">
                                                        <span className="text-sm font-bold text-slate-900">
                                                            {formatCurrency(item.line_total)}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="bg-slate-50/50 px-6 py-6 border-t border-slate-100 space-y-3">
                                    <div className="flex justify-between text-sm text-slate-600">
                                        <span>Subtotal</span>
                                        <span>{formatCurrency(sale.subtotal)}</span>
                                    </div>
                                    <div className="flex justify-between text-sm text-slate-600">
                                        <span>Tax Total</span>
                                        <span>{formatCurrency(sale.tax_total)}</span>
                                    </div>
                                    <div className="flex justify-between text-sm text-slate-600">
                                        <span>Discount</span>
                                        <span className="text-rose-600">-{formatCurrency(sale.discount_total)}</span>
                                    </div>
                                    <div className="flex justify-between items-center pt-3 border-t border-slate-200">
                                        <span className="text-sm font-bold text-slate-900 uppercase tracking-widest">Total Amount</span>
                                        <span className="text-2xl font-bold text-slate-900">{formatCurrency(sale.total)}</span>
                                    </div>
                                </div>
                            </div>

                            {/* Reversals Section */}
                            {(sale.reversals?.length > 0 || sale.reversalOfSale) && (
                                <div className="bg-white rounded-[28px] shadow-sm border border-rose-200 overflow-hidden">
                                    <div className="px-6 py-4 bg-rose-50 border-b border-rose-100 flex items-center gap-2">
                                        <AlertCircle className="text-rose-600" size={18} />
                                        <h3 className="text-sm font-bold text-rose-900 uppercase tracking-widest">Audit & Reversal History</h3>
                                    </div>
                                    <div className="p-6 space-y-4">
                                        {sale.reversalOfSale && (
                                            <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200">
                                                <p className="text-xs font-bold text-slate-500 uppercase">Original Transaction Reference</p>
                                                <p className="mt-1 text-sm font-semibold text-blue-600 underline">
                                                    <Link href={route('sales.history.show', sale.reversalOfSale.id)}>
                                                        {sale.reversalOfSale.sale_number}
                                                    </Link>
                                                </p>
                                            </div>
                                        )}
                                        {sale.reversals?.map((reversal, idx) => (
                                            <div key={idx} className="flex gap-4">
                                                <div className="mt-1">
                                                    <div className="w-8 h-8 rounded-full bg-rose-100 flex items-center justify-center text-rose-600">
                                                        <History size={16} />
                                                    </div>
                                                </div>
                                                <div className="space-y-1 flex-1">
                                                    <div className="flex items-center justify-between">
                                                        <p className="text-sm font-bold text-slate-900">
                                                            {reversal.type === 'void' ? 'Transaction Voided' : 'Transaction Refunded'}
                                                        </p>
                                                        <span className="text-xs text-slate-400">{formatDate(reversal.created_at)}</span>
                                                    </div>
                                                    <p className="text-sm text-slate-600 italic">"{reversal.reason_notes || 'No notes provided'}"</p>
                                                    <div className="flex items-center gap-2 mt-2">
                                                        <span className="px-2 py-0.5 rounded-lg bg-slate-100 text-[10px] font-bold text-slate-500 uppercase tracking-tight">
                                                            ID: {reversal.id}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Payments Section */}
                        <div className="space-y-6">
                            <div className="bg-white rounded-[28px] shadow-sm border border-slate-200 overflow-hidden">
                                <div className="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-2">
                                    <CreditCard size={18} className="text-slate-400" />
                                    <h3 className="text-sm font-bold text-slate-900 uppercase tracking-widest">Payments</h3>
                                </div>
                                <div className="p-6 space-y-4">
                                    {sale.payments?.length > 0 ? sale.payments.map((payment) => (
                                        <div key={payment.id} className="p-4 rounded-2xl bg-slate-50 border border-slate-100 space-y-3">
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs font-bold text-slate-500 uppercase tracking-tight">
                                                    {payment.payment_method?.name || 'Manual Payment'}
                                                </span>
                                                <span className="text-sm font-bold text-slate-900">{formatCurrency(payment.amount)}</span>
                                            </div>
                                            <div className="flex items-center justify-between text-[10px] text-slate-400">
                                                <span className="flex items-center gap-1">
                                                    <Hash size={10} />
                                                    {payment.reference_number || 'No Ref'}
                                                </span>
                                                <span>{formatDate(payment.paid_at)}</span>
                                            </div>
                                        </div>
                                    )) : (
                                        <p className="text-sm text-slate-500 text-center py-4">No payment records found.</p>
                                    )}
                                </div>
                            </div>

                            <div className="bg-slate-900 rounded-[28px] p-6 text-white shadow-xl shadow-slate-900/10">
                                <div className="flex items-center gap-2 mb-4 opacity-60">
                                    <Receipt size={16} />
                                    <span className="text-[10px] font-bold uppercase tracking-[0.2em]">Compliance Check</span>
                                </div>
                                <div className="space-y-4">
                                    <p className="text-sm leading-relaxed opacity-90 font-medium">
                                        This transaction is part of the immutable sales history and is cross-referenced with Tax Reporting and Accounting Sync outbox.
                                    </p>
                                    <div className="pt-4 border-t border-white/10 flex items-center justify-between">
                                        <span className="text-[10px] uppercase font-bold opacity-40">System Integrity</span>
                                        <CheckCircle2 size={16} className="text-emerald-400" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <VoidRefundModal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                sale={sale}
                mode={modalMode}
            />
        </AuthenticatedLayout>
    );
}
