import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    Plus, 
    Edit2, 
    Search,
    Filter,
    X,
    CheckCircle2,
    AlertCircle,
    Truck,
    Mail,
    Phone,
    MapPin,
    Eye,
    RefreshCw
} from 'lucide-react';

export default function Index({ auth, suppliers, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [selectedSupplierId, setSelectedSupplierId] = useState(null);

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        router.get(route('procurement.suppliers.index'), { search, status }, { preserveState: true });
    };

    const handleStatusFilter = (newStatus) => {
        setStatus(newStatus);
        router.get(route('procurement.suppliers.index'), { search, status: newStatus }, { preserveState: true });
    };

    const handleClear = () => {
        setSearch('');
        setStatus('');
        router.get(route('procurement.suppliers.index'), {});
    };

    const toggleStatus = (supplierId) => {
        setSelectedSupplierId(supplierId);
        setConfirmOpen(true);
    };

    const handleConfirmStatusChange = () => {
        if (selectedSupplierId) {
            router.patch(route('procurement.suppliers.toggle-status', selectedSupplierId));
        }
        setConfirmOpen(false);
        setSelectedSupplierId(null);
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Supplier Directory</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Manage vendor contact profiles, payment terms, and active procurement visibility.</p>
                    </div>
                    {auth.permissions.includes('procurement.suppliers.manage') && (
                        <Link
                            href={route('procurement.suppliers.create')}
                            className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
                        >
                            <Plus size={18} />
                            New Supplier
                        </Link>
                    )}
                </div>
            }
        >
            <Head title="Suppliers Directory" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Header Actions */}
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                        <form onSubmit={handleSearchSubmit} className="relative flex-1 max-w-md">
                            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <Search size={18} />
                            </div>
                            <input
                                type="text"
                                placeholder="Search by name or code..."
                                className="block w-full pl-11 pr-12 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => { setSearch(''); router.get(route('procurement.suppliers.index'), { search: '', status }); }}
                                    className="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-slate-600"
                                >
                                    <X size={16} />
                                </button>
                            )}
                        </form>
                        
                        <div className="flex items-center gap-3">
                            <div className="inline-flex rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
                                <button
                                    onClick={() => handleStatusFilter('')}
                                    className={`px-4 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-all ${
                                        status === ''
                                            ? 'bg-slate-900 text-white'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    All
                                </button>
                                <button
                                    onClick={() => handleStatusFilter('active')}
                                    className={`px-4 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-all ${
                                        status === 'active'
                                            ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/10'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    Active
                                </button>
                                <button
                                    onClick={() => handleStatusFilter('inactive')}
                                    className={`px-4 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-all ${
                                        status === 'inactive'
                                            ? 'bg-slate-500 text-white'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    Inactive
                                </button>
                            </div>

                            {(search || status) && (
                                <button 
                                    onClick={handleClear}
                                    className="inline-flex items-center gap-2 px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-2xl text-sm font-bold shadow-sm transition-all"
                                >
                                    <RefreshCw size={14} />
                                    Reset
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Table */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50">
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Supplier Name</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Shortcode</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Primary Contact</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Terms</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Status</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {suppliers.length > 0 ? (
                                        suppliers.map((supplier) => (
                                            <tr key={supplier.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center gap-4">
                                                        <div className="p-3 bg-indigo-50 text-indigo-600 rounded-2xl">
                                                            <Truck size={20} />
                                                        </div>
                                                        <div>
                                                            <span className="font-extrabold text-slate-700 block">{supplier.name}</span>
                                                            {supplier.address && (
                                                                <span className="text-xs text-slate-400 flex items-center gap-1 mt-0.5 font-medium">
                                                                    <MapPin size={12} />
                                                                    {supplier.address}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5 font-black text-xs text-slate-600 uppercase tracking-widest">
                                                    <span className="px-2.5 py-1 bg-slate-100 rounded-lg">
                                                        {supplier.code}
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="text-sm font-bold text-slate-600">{supplier.contact_name || <span className="text-slate-300 italic">None</span>}</div>
                                                    {(supplier.email || supplier.phone) && (
                                                        <div className="text-xs text-slate-400 flex flex-col gap-0.5 mt-1 font-medium">
                                                            {supplier.email && <span className="flex items-center gap-1"><Mail size={12} /> {supplier.email}</span>}
                                                            {supplier.phone && <span className="flex items-center gap-1"><Phone size={12} /> {supplier.phone}</span>}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-8 py-5 font-extrabold text-xs text-slate-500 uppercase tracking-wider">
                                                    {supplier.payment_terms || <span className="text-slate-300 italic">COD</span>}
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex justify-center">
                                                        <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border ${
                                                            supplier.is_active
                                                                ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                                                : 'bg-slate-50 text-slate-400 border-slate-100'
                                                        }`}>
                                                            {supplier.is_active ? (
                                                                <>
                                                                    <CheckCircle2 size={10} />
                                                                    Active
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <AlertCircle size={10} />
                                                                    Inactive
                                                                </>
                                                            )}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Link
                                                            href={route('procurement.suppliers.show', supplier.id)}
                                                            className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                                            title="View Details"
                                                        >
                                                            <Eye size={18} />
                                                        </Link>
                                                        {auth.permissions.includes('procurement.suppliers.manage') && (
                                                            <>
                                                                <Link
                                                                    href={route('procurement.suppliers.edit', supplier.id)}
                                                                    className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                                                    title="Edit Details"
                                                                >
                                                                    <Edit2 size={18} />
                                                                </Link>
                                                                <button
                                                                    onClick={() => toggleStatus(supplier.id)}
                                                                    className={`p-2 rounded-xl transition-all ${
                                                                        supplier.is_active
                                                                            ? 'text-slate-400 hover:text-rose-600 hover:bg-rose-50'
                                                                            : 'text-slate-400 hover:text-emerald-600 hover:bg-emerald-50'
                                                                    }`}
                                                                    title={supplier.is_active ? "Deactivate Supplier" : "Activate Supplier"}
                                                                >
                                                                    <X size={18} />
                                                                </button>
                                                            </>
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
                                                        <Truck size={40} className="text-slate-300" />
                                                    </div>
                                                    <h3 className="text-lg font-bold text-slate-800">No suppliers found</h3>
                                                    <p className="text-slate-500 text-sm mt-1 max-w-xs mx-auto">
                                                        {search || status ? "We couldn't find any suppliers matching your search filters." : "Start by registering your first supplier profile to manage inbound flow."}
                                                    </p>
                                                    {(search || status) && (
                                                        <button 
                                                            onClick={handleClear}
                                                            className="mt-4 text-indigo-600 font-bold text-sm"
                                                        >
                                                            Clear Filters
                                                        </button>
                                                    )}
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
            <PremiumDialog
                isOpen={confirmOpen}
                type="warning"
                title="Toggle Supplier Status"
                message="Are you sure you want to change the active status of this supplier? This action might affect pending purchase orders and replenishment schedules linked to this supplier."
                confirmLabel="Change Status"
                onConfirm={handleConfirmStatusChange}
                onCancel={() => {
                    setConfirmOpen(false);
                    setSelectedSupplierId(null);
                }}
            />
        </AuthenticatedLayout>
    );
}
