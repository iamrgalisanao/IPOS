import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { 
    ChevronLeft, 
    Truck, 
    CheckCircle2, 
    AlertCircle 
} from 'lucide-react';

export default function Create({ auth }) {
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        name: '',
        contact_name: '',
        email: '',
        phone: '',
        address: '',
        payment_terms: 'COD',
        is_active: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('procurement.suppliers.store'));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center gap-3">
                    <Link
                        href={route('procurement.suppliers.index')}
                        className="p-2 bg-white border border-slate-200 text-slate-600 rounded-xl hover:bg-slate-50 transition-all"
                    >
                        <ChevronLeft size={16} />
                    </Link>
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Register Supplier</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Add a new partner vendor profile to the procurement network.</p>
                    </div>
                </div>
            }
        >
            <Head title="Register Supplier" />

            <div className="py-8">
                <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-8 space-y-8">
                        
                        <div className="flex items-center gap-4 pb-6 border-b border-slate-100">
                            <div className="p-4 bg-indigo-50 text-indigo-600 rounded-2xl">
                                <Truck size={24} />
                            </div>
                            <div>
                                <h3 className="text-lg font-black text-slate-800">Vendor Profile Identity</h3>
                                <p className="text-xs text-slate-400 font-medium">Verify primary registration code and name before submitting.</p>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="md:col-span-1">
                                <InputLabel htmlFor="code" value="Supplier Shortcode" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                <TextInput
                                    id="code"
                                    type="text"
                                    className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all uppercase"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                    required
                                    placeholder="e.g. VEND-COCA"
                                />
                                <InputError message={errors.code} className="mt-2" />
                                <p className="mt-1.5 text-[10px] text-slate-400 font-bold uppercase tracking-tight">Unique, uppercase vendor identifier.</p>
                            </div>

                            <div className="md:col-span-1">
                                <InputLabel htmlFor="name" value="Supplier / Company Name" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                <TextInput
                                    id="name"
                                    type="text"
                                    className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                    placeholder="e.g. Coca-Cola Beverages Philippines"
                                />
                                <InputError message={errors.name} className="mt-2" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4">
                            <div>
                                <InputLabel htmlFor="contact_name" value="Primary Contact Person" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                <TextInput
                                    id="contact_name"
                                    type="text"
                                    className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all"
                                    value={data.contact_name}
                                    onChange={(e) => setData('contact_name', e.target.value)}
                                    placeholder="e.g. Juan dela Cruz"
                                />
                                <InputError message={errors.contact_name} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="payment_terms" value="Payment Terms" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                <select
                                    id="payment_terms"
                                    className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all text-sm font-semibold px-4"
                                    value={data.payment_terms}
                                    onChange={(e) => setData('payment_terms', e.target.value)}
                                >
                                    <option value="COD">Cash On Delivery (COD)</option>
                                    <option value="NET_7">Net 7 Days</option>
                                    <option value="NET_15">Net 15 Days</option>
                                    <option value="NET_30">Net 30 Days</option>
                                    <option value="NET_60">Net 60 Days</option>
                                </select>
                                <InputError message={errors.payment_terms} className="mt-2" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4">
                            <div>
                                <InputLabel htmlFor="email" value="Email Address" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                <TextInput
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    placeholder="e.g. billing@vendor.com"
                                />
                                <InputError message={errors.email} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="phone" value="Phone Number" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                <TextInput
                                    id="phone"
                                    type="text"
                                    className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                    placeholder="e.g. +63 917 123 4567"
                                />
                                <InputError message={errors.phone} className="mt-2" />
                            </div>
                        </div>

                        <div className="pt-4">
                            <InputLabel htmlFor="address" value="Warehouse / Corporate Address" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                            <textarea
                                id="address"
                                className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all text-sm font-semibold h-24 px-4"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                                placeholder="Optional billing or warehouse location details..."
                            />
                            <InputError message={errors.address} className="mt-2" />
                        </div>

                        <div className="pt-4 border-t border-slate-100">
                            <InputLabel value="Directory Visibility" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                            <div className="grid grid-cols-2 gap-4 mt-2">
                                <button
                                    type="button"
                                    onClick={() => setData('is_active', true)}
                                    className={`flex items-center justify-center gap-3 p-4 rounded-2xl border-2 transition-all ${
                                        data.is_active
                                            ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                            : 'border-slate-100 bg-slate-50 text-slate-400 hover:border-slate-200'
                                    }`}
                                >
                                    <div className={`p-1.5 rounded-full ${data.is_active ? 'bg-indigo-500 text-white' : 'bg-slate-200 text-white'}`}>
                                        <CheckCircle2 size={12} />
                                    </div>
                                    <span className="text-sm font-black uppercase tracking-widest">Active</span>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setData('is_active', false)}
                                    className={`flex items-center justify-center gap-3 p-4 rounded-2xl border-2 transition-all ${
                                        !data.is_active
                                            ? 'border-slate-500 bg-slate-50 text-slate-700'
                                            : 'border-slate-100 bg-slate-50 text-slate-400 hover:border-slate-200'
                                    }`}
                                >
                                    <div className={`p-1.5 rounded-full ${!data.is_active ? 'bg-slate-500 text-white' : 'bg-slate-200 text-white'}`}>
                                        <AlertCircle size={12} />
                                    </div>
                                    <span className="text-sm font-black uppercase tracking-widest">Inactive</span>
                                </button>
                            </div>
                            <InputError message={errors.is_active} className="mt-2" />
                        </div>

                        <div className="mt-10 flex items-center justify-end gap-4 pt-6 border-t border-slate-100">
                            <Link
                                href={route('procurement.suppliers.index')}
                                className="inline-flex items-center justify-center px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-xs bg-slate-100 hover:bg-slate-200 text-slate-600 transition-all"
                            >
                                Cancel
                            </Link>
                            <PrimaryButton disabled={processing} className="px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-xs shadow-lg shadow-indigo-600/20 bg-indigo-600 hover:bg-indigo-500">
                                Save Supplier
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
