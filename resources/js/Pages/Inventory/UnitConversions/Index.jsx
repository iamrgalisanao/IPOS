import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    Scale, 
    Plus, 
    Edit2, 
    Archive, 
    Search,
    Filter,
    X,
    CheckCircle2,
    AlertCircle,
    Globe,
    FileText
} from 'lucide-react';

export default function Index({ auth, conversions, products, filters }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingConversion, setEditingConversion] = useState(null);
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [typeFilter, setTypeFilter] = useState(filters.type || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || '');
    const [deactivateConfirmOpen, setDeactivateConfirmOpen] = useState(false);
    const [pendingDeactivateConversion, setPendingDeactivateConversion] = useState(null);

    const { data, setData, post, put, delete: destroy, processing, errors, reset, clearErrors } = useForm({
        product_id: '',
        from_unit: '',
        to_unit: '',
        conversion_factor: '',
        is_active: true,
    });

    const openCreateModal = () => {
        setEditingConversion(null);
        reset();
        clearErrors();
        setIsModalOpen(true);
    };

    const openEditModal = (conversion) => {
        setEditingConversion(conversion);
        setData({
            product_id: conversion.product_id || '',
            from_unit: conversion.from_unit,
            to_unit: conversion.to_unit,
            conversion_factor: conversion.conversion_factor,
            is_active: conversion.is_active,
        });
        clearErrors();
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setEditingConversion(null);
        reset();
    };

    const submit = (e) => {
        e.preventDefault();
        const payload = {
            ...data,
            product_id: data.product_id || null,
        };

        if (editingConversion) {
            // Put route model binding uses put
            router.put(route('inventory.unit-conversions.update', editingConversion.id), payload, {
                onSuccess: () => closeModal(),
            });
        } else {
            router.post(route('inventory.unit-conversions.store'), payload, {
                onSuccess: () => closeModal(),
            });
        }
    };

    const deactivateConversion = (conversion) => {
        setPendingDeactivateConversion(conversion);
        setDeactivateConfirmOpen(true);
    };

    const handleConfirmDeactivate = () => {
        if (pendingDeactivateConversion) {
            destroy(route('inventory.unit-conversions.destroy', pendingDeactivateConversion.id));
        }
        setDeactivateConfirmOpen(false);
        setPendingDeactivateConversion(null);
    };

    const handleFilterChange = (key, value) => {
        const queryParams = {
            search: searchQuery,
            type: typeFilter,
            status: statusFilter,
            [key]: value
        };
        
        router.get(route('inventory.unit-conversions.index'), queryParams, {
            preserveState: true,
            replace: true
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Unit Conversions</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Manage global and product-specific recipe unit conversions for ingredient scaling.</p>
                    </div>
                    <button
                        onClick={openCreateModal}
                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
                    >
                        <Plus size={18} />
                        New Conversion
                    </button>
                </div>
            }
        >
            <Head title="Unit Conversions" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Filters */}
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                        <div className="relative flex-1 max-w-md">
                            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <Search size={18} />
                            </div>
                            <input
                                type="text"
                                placeholder="Search by unit name or product..."
                                className="block w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                value={searchQuery}
                                onChange={(e) => {
                                    setSearchQuery(e.target.value);
                                    handleFilterChange('search', e.target.value);
                                }}
                            />
                        </div>

                        <div className="flex items-center gap-3">
                            <select
                                className="bg-white border border-slate-200 text-slate-600 rounded-2xl text-sm font-bold shadow-sm px-4 py-3 focus:ring-indigo-500/20 focus:border-indigo-500"
                                value={typeFilter}
                                onChange={(e) => {
                                    setTypeFilter(e.target.value);
                                    handleFilterChange('type', e.target.value);
                                }}
                            >
                                <option value="">All Scopes</option>
                                <option value="global">Global Only</option>
                                <option value="product">Product Specific</option>
                            </select>

                            <select
                                className="bg-white border border-slate-200 text-slate-600 rounded-2xl text-sm font-bold shadow-sm px-4 py-3 focus:ring-indigo-500/20 focus:border-indigo-500"
                                value={statusFilter}
                                onChange={(e) => {
                                    setStatusFilter(e.target.value);
                                    handleFilterChange('status', e.target.value);
                                }}
                            >
                                <option value="">All Statuses</option>
                                <option value="active">Active Only</option>
                                <option value="inactive">Inactive Only</option>
                            </select>
                        </div>
                    </div>

                    {/* Table */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50">
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Scope / Product</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">From Unit</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">To Unit</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Conversion Factor</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Status</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {conversions.data.length > 0 ? (
                                        conversions.data.map((conv) => (
                                            <tr key={conv.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-8 py-5">
                                                    {conv.product ? (
                                                        <div className="flex items-center gap-3">
                                                            <div className="p-2 bg-indigo-50 text-indigo-600 rounded-lg">
                                                                <FileText size={16} />
                                                            </div>
                                                            <div>
                                                                <div className="font-extrabold text-slate-700">{conv.product.name}</div>
                                                                <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">SKU: {conv.product.sku}</div>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <div className="flex items-center gap-3">
                                                            <div className="p-2 bg-emerald-50 text-emerald-600 rounded-lg">
                                                                <Globe size={16} />
                                                            </div>
                                                            <div>
                                                                <div className="font-extrabold text-slate-700">Global (All Products)</div>
                                                                <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Fallback System</div>
                                                            </div>
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-8 py-5">
                                                    <span className="px-3 py-1 bg-slate-100 text-slate-700 rounded-lg text-xs font-black tracking-wider uppercase">
                                                        {conv.from_unit}
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <span className="px-3 py-1 bg-slate-100 text-slate-700 rounded-lg text-xs font-black tracking-wider uppercase">
                                                        {conv.to_unit}
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <span className="font-black text-slate-800 text-sm">
                                                        {parseFloat(conv.conversion_factor).toFixed(4)}
                                                    </span>
                                                    <span className="text-slate-400 text-xs font-medium ml-1">
                                                        (1 {conv.from_unit} = {parseFloat(conv.conversion_factor)} {conv.to_unit})
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex justify-center">
                                                        <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border ${
                                                            conv.is_active
                                                                ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                                                : 'bg-slate-50 text-slate-400 border-slate-100'
                                                        }`}>
                                                            {conv.is_active ? (
                                                                <>
                                                                    <CheckCircle2 size={10} />
                                                                    Active
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <Archive size={10} />
                                                                    Inactive
                                                                </>
                                                            )}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <button
                                                            onClick={() => openEditModal(conv)}
                                                            className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                                            title="Edit conversion"
                                                        >
                                                            <Edit2 size={18} />
                                                        </button>
                                                        {conv.is_active && (
                                                            <button
                                                                onClick={() => deactivateConversion(conv)}
                                                                className="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all"
                                                                title="Deactivate conversion"
                                                            >
                                                                <Archive size={18} />
                                                            </button>
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
                                                        <Scale size={40} className="text-slate-300" />
                                                    </div>
                                                    <h3 className="text-lg font-bold text-slate-800">No conversions found</h3>
                                                    <p className="text-slate-500 text-sm mt-1 max-w-xs mx-auto">
                                                        Start by creating your first unit conversion rule.
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

            {/* Create/Edit Modal */}
            <Modal show={isModalOpen} onClose={closeModal} maxWidth="xl">
                <form onSubmit={submit} className="p-8">
                    <div className="flex items-center justify-between mb-8">
                        <div>
                            <h3 className="text-xl font-black text-slate-800">
                                {editingConversion ? 'Edit Conversion Rule' : 'New Conversion Rule'}
                            </h3>
                            <p className="text-sm text-slate-500 font-medium mt-1">
                                Define ratio for mapping bulk inventory units to recipe units.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={closeModal}
                            className="p-2 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-50 transition-all"
                        >
                            <X size={20} />
                        </button>
                    </div>

                    <div className="space-y-6">
                        <div>
                            <InputLabel htmlFor="product_id" value="Product (Leave empty for Global scope)" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                            <select
                                id="product_id"
                                className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm text-sm font-medium"
                                value={data.product_id}
                                onChange={(e) => setData('product_id', e.target.value)}
                            >
                                <option value="">Global / Standard Fallback Rule</option>
                                {products.map((prod) => (
                                    <option key={prod.id} value={prod.id}>
                                        {prod.name} ({prod.sku})
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.product_id} className="mt-2" />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="from_unit" value="From Unit (Inventory Unit)" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                <TextInput
                                    id="from_unit"
                                    type="text"
                                    className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all"
                                    value={data.from_unit}
                                    onChange={(e) => setData('from_unit', e.target.value)}
                                    required
                                    placeholder="e.g. Crate, Bag, Case"
                                />
                                <InputError message={errors.from_unit} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="to_unit" value="To Unit (Recipe/Base Unit)" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                <TextInput
                                    id="to_unit"
                                    type="text"
                                    className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all"
                                    value={data.to_unit}
                                    onChange={(e) => setData('to_unit', e.target.value)}
                                    required
                                    placeholder="e.g. Piece, kg, gram"
                                />
                                <InputError message={errors.to_unit} className="mt-2" />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="conversion_factor" value="Conversion Factor (Multiply by this)" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                            <TextInput
                                id="conversion_factor"
                                type="number"
                                step="any"
                                className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all"
                                value={data.conversion_factor}
                                onChange={(e) => setData('conversion_factor', e.target.value)}
                                required
                                placeholder="e.g. 10.0000, 2.5"
                            />
                            <InputError message={errors.conversion_factor} className="mt-2" />
                            <p className="mt-1.5 text-[10px] text-slate-400 font-bold uppercase tracking-tighter">
                                How many base units are contained inside one inventory unit? e.g. 1 Bag = 2.5 kg (factor is 2.5).
                            </p>
                        </div>

                        <div>
                            <InputLabel value="Active Status" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
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
                                    <span className="text-sm font-black uppercase tracking-widest">Inactive</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="mt-10 flex items-center justify-end gap-4">
                        <SecondaryButton onClick={closeModal} className="px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-xs border-none bg-slate-100 hover:bg-slate-200">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={processing} className="px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-xs shadow-lg shadow-indigo-600/20 bg-indigo-600 hover:bg-indigo-500">
                            {editingConversion ? 'Update' : 'Create'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            {deactivateConfirmOpen && (
                <PremiumDialog
                    isOpen={deactivateConfirmOpen}
                    type="warning"
                    title="Deactivate Conversion Rule"
                    message="Are you sure you want to deactivate this unit conversion rule? This will preserve history but make it unavailable for future stock transactions."
                    confirmLabel="Deactivate"
                    onConfirm={handleConfirmDeactivate}
                    onCancel={() => {
                        setDeactivateConfirmOpen(false);
                        setPendingDeactivateConversion(null);
                    }}
                />
            )}
        </AuthenticatedLayout>
    );
}
