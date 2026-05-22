import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    ChevronLeft, 
    Truck, 
    Edit2,
    CheckCircle2, 
    AlertCircle,
    Mail,
    Phone,
    MapPin,
    Calendar,
    CreditCard,
    User
} from 'lucide-react';

export default function Show({ auth, supplier }) {
    const [confirmOpen, setConfirmOpen] = useState(false);

    const toggleStatus = () => {
        setConfirmOpen(true);
    };

    const handleConfirmStatusChange = () => {
        router.patch(route('procurement.suppliers.toggle-status', supplier.id));
        setConfirmOpen(false);
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('procurement.suppliers.index')}
                            className="p-2 bg-white border border-slate-200 text-slate-600 rounded-xl hover:bg-slate-50 transition-all"
                        >
                            <ChevronLeft size={16} />
                        </Link>
                        <div>
                            <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Supplier Profile</h2>
                            <p className="text-sm text-slate-500 font-medium mt-1">Detailed directory record for vendor {supplier.name}.</p>
                        </div>
                    </div>
                    {auth.permissions.includes('procurement.suppliers.manage') && (
                        <div className="flex items-center gap-2">
                            <Link
                                href={route('procurement.suppliers.edit', supplier.id)}
                                className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-50 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
                            >
                                <Edit2 size={18} />
                                Edit Profile
                            </Link>
                        </div>
                    )}
                </div>
            }
        >
            <Head title={`Supplier Details - ${supplier.name}`} />

            <div className="py-8">
                <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                    
                    {/* Primary Info Card */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-8 flex flex-col md:flex-row items-start justify-between gap-6">
                        <div className="flex items-center gap-6">
                            <div className="p-5 bg-indigo-50 text-indigo-600 rounded-[1.5rem]">
                                <Truck size={36} />
                            </div>
                            <div>
                                <div className="flex items-center gap-3">
                                    <h1 className="text-2xl font-black text-slate-800 tracking-tight">{supplier.name}</h1>
                                    <span className="px-3 py-1 bg-slate-100 text-slate-600 rounded-lg text-xs font-black tracking-wider uppercase">
                                        {supplier.code}
                                    </span>
                                </div>
                                <div className="flex items-center gap-4 mt-2">
                                    <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border ${
                                        supplier.is_active
                                            ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                            : 'bg-slate-50 text-slate-400 border-slate-100'
                                    }`}>
                                        {supplier.is_active ? (
                                            <>
                                                <CheckCircle2 size={10} />
                                                Active Partner
                                            </>
                                        ) : (
                                            <>
                                                <AlertCircle size={10} />
                                                Inactive / Locked
                                            </>
                                        )}
                                    </span>
                                    {auth.permissions.includes('procurement.suppliers.manage') && (
                                        <button
                                            onClick={toggleStatus}
                                            className="text-xs font-black text-indigo-600 hover:text-indigo-500 uppercase tracking-widest"
                                        >
                                            {supplier.is_active ? 'Deactivate Account' : 'Activate Account'}
                                        </button>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Information Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                        {/* Directory Contacts Card */}
                        <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-8 md:col-span-2 space-y-6">
                            <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider pb-4 border-b border-slate-50">
                                Contact & Communication Info
                            </h3>

                            <div className="space-y-4">
                                <div className="flex items-start gap-4">
                                    <div className="p-2 bg-slate-50 text-slate-500 rounded-xl mt-0.5">
                                        <User size={16} />
                                    </div>
                                    <div>
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400 block">Contact Person</span>
                                        <span className="font-extrabold text-slate-700">{supplier.contact_name || <span className="italic font-medium text-slate-400">Not specified</span>}</span>
                                    </div>
                                </div>

                                <div className="flex items-start gap-4">
                                    <div className="p-2 bg-slate-50 text-slate-500 rounded-xl mt-0.5">
                                        <Mail size={16} />
                                    </div>
                                    <div>
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400 block">Billing Email</span>
                                        {supplier.email ? (
                                            <a href={`mailto:${supplier.email}`} className="font-extrabold text-indigo-600 hover:text-indigo-500 transition-colors">
                                                {supplier.email}
                                            </a>
                                        ) : (
                                            <span className="italic font-medium text-slate-400">No email registered</span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex items-start gap-4">
                                    <div className="p-2 bg-slate-50 text-slate-500 rounded-xl mt-0.5">
                                        <Phone size={16} />
                                    </div>
                                    <div>
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400 block">Phone / Mobile</span>
                                        {supplier.phone ? (
                                            <a href={`tel:${supplier.phone}`} className="font-extrabold text-slate-700">
                                                {supplier.phone}
                                            </a>
                                        ) : (
                                            <span className="italic font-medium text-slate-400">No phone registered</span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex items-start gap-4">
                                    <div className="p-2 bg-slate-50 text-slate-500 rounded-xl mt-0.5">
                                        <MapPin size={16} />
                                    </div>
                                    <div>
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400 block">Warehouse / Corporate Address</span>
                                        <span className="font-semibold text-slate-600 block leading-relaxed">
                                            {supplier.address || <span className="italic font-medium text-slate-400">No address details registered</span>}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Settings & Terms Card */}
                        <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-8 space-y-6">
                            <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider pb-4 border-b border-slate-50">
                                Procurement Settings
                            </h3>

                            <div className="space-y-4">
                                <div className="flex items-start gap-4">
                                    <div className="p-2 bg-slate-50 text-slate-500 rounded-xl mt-0.5">
                                        <CreditCard size={16} />
                                    </div>
                                    <div>
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400 block">Payment Terms</span>
                                        <span className="font-extrabold text-slate-700">{supplier.payment_terms || 'COD'}</span>
                                    </div>
                                </div>

                                <div className="flex items-start gap-4">
                                    <div className="p-2 bg-slate-50 text-slate-500 rounded-xl mt-0.5">
                                        <Calendar size={16} />
                                    </div>
                                    <div>
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400 block">Registered On</span>
                                        <span className="font-bold text-slate-600 text-xs">{formatDate(supplier.created_at)}</span>
                                    </div>
                                </div>

                                <div className="flex items-start gap-4">
                                    <div className="p-2 bg-slate-50 text-slate-500 rounded-xl mt-0.5">
                                        <Calendar size={16} />
                                    </div>
                                    <div>
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-400 block">Last Updated</span>
                                        <span className="font-bold text-slate-600 text-xs">{formatDate(supplier.updated_at)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <PremiumDialog
                isOpen={confirmOpen}
                type={supplier.is_active ? 'warning' : 'success'}
                title={supplier.is_active ? 'Deactivate Supplier Partner' : 'Activate Supplier Partner'}
                message={`Are you sure you want to ${supplier.is_active ? 'deactivate' : 'activate'} the active status of this supplier (${supplier.name})? This may affect pending replenishment schedules and purchase orders.`}
                confirmLabel={supplier.is_active ? 'Deactivate' : 'Activate'}
                onConfirm={handleConfirmStatusChange}
                onCancel={() => setConfirmOpen(false)}
            />
        </AuthenticatedLayout>
    );
}
