import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { 
    Plus, 
    Search,
    X,
    CheckCircle2,
    AlertCircle,
    Eye,
    Edit2,
    RefreshCw,
    FileText,
    Building2,
    Truck,
    Calendar,
    DollarSign,
    Clock
} from 'lucide-react';

export default function Index({ auth, purchaseOrders, suppliers, branches, filters }) {
    const [branchId, setBranchId] = useState(filters.branch_id || '');
    const [status, setStatus] = useState(filters.status || '');
    const [supplierId, setSupplierId] = useState(filters.supplier_id || '');

    const handleFilterChange = (newBranch, newStatus, newSupplier) => {
        router.get(route('procurement.purchase-orders.index'), {
            branch_id: newBranch,
            status: newStatus,
            supplier_id: newSupplier
        }, { preserveState: true });
    };

    const handleClear = () => {
        setBranchId('');
        setStatus('');
        setSupplierId('');
        router.get(route('procurement.purchase-orders.index'), {});
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

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Purchase Orders</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Raise procurement requests, request branch-manager approvals, and manage supplier orders.</p>
                    </div>
                    {auth.permissions.includes('procurement.purchase-orders.create') && (
                        <Link
                            href={route('procurement.purchase-orders.create')}
                            className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
                        >
                            <Plus size={18} />
                            Create PO
                        </Link>
                    )}
                </div>
            }
        >
            <Head title="Purchase Orders" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Filtering Panel */}
                    <div className="bg-white rounded-3xl border border-slate-100 p-6 shadow-sm mb-8 flex flex-wrap gap-4 items-end">
                        {branches.length > 1 && (
                            <div className="flex-1 min-w-[200px]">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Branch</label>
                                <select
                                    className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                    value={branchId}
                                    onChange={(e) => { setBranchId(e.target.value); handleFilterChange(e.target.value, status, supplierId); }}
                                >
                                    <option value="">All Branches</option>
                                    {branches.map(b => (
                                        <option key={b.id} value={b.id}>{b.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <div className="flex-1 min-w-[200px]">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Supplier</label>
                            <select
                                className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                value={supplierId}
                                onChange={(e) => { setSupplierId(e.target.value); handleFilterChange(branchId, status, e.target.value); }}
                            >
                                <option value="">All Suppliers</option>
                                {suppliers.map(s => (
                                    <option key={s.id} value={s.id}>{s.name}</option>
                                ))}
                            </select>
                        </div>

                        <div className="flex-1 min-w-[200px]">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Status</label>
                            <select
                                className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                value={status}
                                onChange={(e) => { setStatus(e.target.value); handleFilterChange(branchId, e.target.value, supplierId); }}
                            >
                                <option value="">All Statuses</option>
                                <option value="draft">Draft</option>
                                <option value="pending_approval">Pending Approval</option>
                                <option value="approved">Approved</option>
                                <option value="sent">Sent to Supplier</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>

                        <div className="flex items-center gap-2">
                            {(branchId || status || supplierId) && (
                                <button
                                    onClick={handleClear}
                                    className="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm font-bold shadow-sm transition-all flex items-center gap-2 h-[42px]"
                                >
                                    <RefreshCw size={14} />
                                    Reset
                                </button>
                            )}
                        </div>
                    </div>

                    {/* PO List Table */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50">
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">PO Number</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Branch & Supplier</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Order Date</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Estimated Total</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Status</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {purchaseOrders.length > 0 ? (
                                        purchaseOrders.map((po) => (
                                            <tr key={po.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center gap-3">
                                                        <div className="p-3 bg-indigo-50 text-indigo-600 rounded-2xl">
                                                            <FileText size={20} />
                                                        </div>
                                                        <div>
                                                            <span className="font-extrabold text-slate-800 block text-sm tracking-tight">{po.po_number}</span>
                                                            <span className="text-xs text-slate-400 font-medium">By {po.created_by?.name || 'System'}</span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center gap-1.5 text-slate-700 font-extrabold text-sm">
                                                        <Building2 size={14} className="text-slate-400" />
                                                        {po.branch?.name}
                                                    </div>
                                                    <div className="flex items-center gap-1.5 text-xs text-slate-400 font-medium mt-1">
                                                        <Truck size={14} className="text-slate-400" />
                                                        {po.supplier?.name}
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5 text-sm font-semibold text-slate-500">
                                                    <div className="flex items-center gap-1.5">
                                                        <Calendar size={14} className="text-slate-400" />
                                                        {new Date(po.order_date).toLocaleDateString('en-US', { dateStyle: 'medium' })}
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5 text-sm font-black text-slate-800">
                                                    {formatCurrency(po.total_estimated_amount)}
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex justify-center">
                                                        <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border ${getStatusStyle(po.status)}`}>
                                                            <Clock size={10} />
                                                            {getStatusLabel(po.status)}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Link
                                                            href={route('procurement.purchase-orders.show', po.id)}
                                                            className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                                            title="View Details"
                                                        >
                                                            <Eye size={18} />
                                                        </Link>
                                                        {po.status === 'draft' && auth.permissions.includes('procurement.purchase-orders.create') && (
                                                            <Link
                                                                href={route('procurement.purchase-orders.edit', po.id)}
                                                                className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                                                title="Edit Order"
                                                            >
                                                                <Edit2 size={18} />
                                                            </Link>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="6" className="px-8 py-20 text-center">
                                                <div className="flex flex-col items-center justify-center">
                                                    <div className="p-6 bg-slate-50 rounded-full mb-4">
                                                        <FileText size={40} className="text-slate-300" />
                                                    </div>
                                                    <h3 className="text-lg font-bold text-slate-800">No purchase orders found</h3>
                                                    <p className="text-slate-500 text-sm mt-1 max-w-xs mx-auto">
                                                        {branchId || status || supplierId ? "No purchase orders match your filter criteria." : "Create your first purchase order to request or queue vendor products."}
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
