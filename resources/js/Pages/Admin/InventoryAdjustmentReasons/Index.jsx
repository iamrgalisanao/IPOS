import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { Plus, Edit2, Shield, Archive, Settings } from 'lucide-react';

export default function Index({ auth, reasons, categories, directions }) {
    const [editing, setEditing] = useState(null);
    const [isOpen, setIsOpen] = useState(false);

    const form = useForm({
        code: '',
        name: '',
        description: '',
        reason_category: categories[0] || 'damage',
        direction_policy: directions[0] || 'decrease_only',
        requires_notes: true,
        evidence_required: false,
        is_opening_balance: false,
        is_active: true,
        sort_order: 0,
    });

    const openCreate = () => {
        setEditing(null);
        form.reset();
        setIsOpen(true);
    };

    const openEdit = (reason) => {
        setEditing(reason);
        form.setData({
            code: reason.code,
            name: reason.name,
            description: reason.description || '',
            reason_category: reason.reason_category,
            direction_policy: reason.direction_policy,
            requires_notes: !!reason.requires_notes,
            evidence_required: !!reason.evidence_required,
            is_opening_balance: !!reason.is_opening_balance,
            is_active: !!reason.is_active,
            sort_order: reason.sort_order || 0,
        });
        setIsOpen(true);
    };

    const submit = (event) => {
        event.preventDefault();
        const options = { onSuccess: () => setIsOpen(false) };
        if (editing) {
            form.put(route('admin.inventory-adjustment-reasons.update', editing.id), options);
        } else {
            form.post(route('admin.inventory-adjustment-reasons.store'), options);
        }
    };

    const deactivate = (reason) => {
        if (window.confirm(`Deactivate ${reason.code}?`)) {
            form.delete(route('admin.inventory-adjustment-reasons.destroy', reason.id));
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight flex items-center gap-2">
                            <Settings className="w-6 h-6 text-indigo-500" />
                            Inventory Adjustment Reasons
                        </h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Manage direction-aware stock adjustment reasons and setup-only opening balance labels.</p>
                    </div>
                    <button onClick={openCreate} className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-bold">
                        <Plus size={16} />
                        Add Reason
                    </button>
                </div>
            }
        >
            <Head title="Inventory Adjustment Reasons" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="bg-white border border-slate-200 rounded-lg overflow-hidden">
                        <table className="w-full text-left">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-5 py-3 text-xs font-black uppercase text-slate-500">Code</th>
                                    <th className="px-5 py-3 text-xs font-black uppercase text-slate-500">Name</th>
                                    <th className="px-5 py-3 text-xs font-black uppercase text-slate-500">Category</th>
                                    <th className="px-5 py-3 text-xs font-black uppercase text-slate-500">Direction</th>
                                    <th className="px-5 py-3 text-xs font-black uppercase text-slate-500">Controls</th>
                                    <th className="px-5 py-3 text-xs font-black uppercase text-slate-500">Status</th>
                                    <th className="px-5 py-3 text-xs font-black uppercase text-slate-500 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {reasons.map((reason) => (
                                    <tr key={reason.id}>
                                        <td className="px-5 py-4 text-xs font-black text-slate-700">{reason.code}</td>
                                        <td className="px-5 py-4 text-sm font-bold text-slate-700">{reason.name}</td>
                                        <td className="px-5 py-4 text-sm text-slate-600">{reason.reason_category}</td>
                                        <td className="px-5 py-4 text-sm text-slate-600">{reason.direction_policy}</td>
                                        <td className="px-5 py-4">
                                            <div className="flex flex-wrap gap-2 text-[10px] font-black uppercase">
                                                {reason.requires_notes && <span className="px-2 py-1 rounded bg-amber-50 text-amber-700">Notes</span>}
                                                {reason.evidence_required && <span className="px-2 py-1 rounded bg-rose-50 text-rose-700">Evidence</span>}
                                                {reason.direction_policy === 'bidirectional' && <span className="inline-flex items-center gap-1 px-2 py-1 rounded bg-indigo-50 text-indigo-700"><Shield size={10} /> Approval</span>}
                                                {reason.is_opening_balance && <span className="px-2 py-1 rounded bg-emerald-50 text-emerald-700">Setup</span>}
                                            </div>
                                        </td>
                                        <td className="px-5 py-4 text-sm font-bold">{reason.is_active ? 'Active' : 'Inactive'}</td>
                                        <td className="px-5 py-4">
                                            <div className="flex justify-end gap-2">
                                                {reason.is_active && (
                                                    <button onClick={() => openEdit(reason)} className="p-2 text-slate-500 hover:text-indigo-600" title="Edit">
                                                        <Edit2 size={16} />
                                                    </button>
                                                )}
                                                <button onClick={() => deactivate(reason)} className="p-2 text-slate-500 hover:text-rose-600" title="Deactivate">
                                                    <Archive size={16} />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={isOpen} onClose={() => setIsOpen(false)}>
                <form onSubmit={submit} className="p-6 space-y-4">
                    <h3 className="text-lg font-black text-slate-800">{editing ? 'Update Reason Version' : 'Add Adjustment Reason'}</h3>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Code" />
                            <TextInput disabled={!!editing} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="w-full" />
                            <InputError message={form.errors.code} />
                        </div>
                        <div>
                            <InputLabel value="Name" />
                            <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="w-full" />
                            <InputError message={form.errors.name} />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Category" />
                            <select value={form.data.reason_category} onChange={(e) => form.setData('reason_category', e.target.value)} className="w-full border-slate-300 rounded-md">
                                {categories.map((category) => <option key={category} value={category}>{category}</option>)}
                            </select>
                            <InputError message={form.errors.reason_category} />
                        </div>
                        <div>
                            <InputLabel value="Direction" />
                            <select value={form.data.direction_policy} onChange={(e) => form.setData('direction_policy', e.target.value)} className="w-full border-slate-300 rounded-md">
                                {directions.map((direction) => <option key={direction} value={direction}>{direction}</option>)}
                            </select>
                            <InputError message={form.errors.direction_policy} />
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Description" />
                        <textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} className="w-full border-slate-300 rounded-md" rows="3" />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm font-bold text-slate-700">
                        <label><input type="checkbox" checked={form.data.requires_notes} onChange={(e) => form.setData('requires_notes', e.target.checked)} /> Notes required</label>
                        <label><input type="checkbox" checked={form.data.evidence_required} onChange={(e) => form.setData('evidence_required', e.target.checked)} /> Evidence reserved</label>
                        <label><input type="checkbox" checked={form.data.is_opening_balance} onChange={(e) => form.setData('is_opening_balance', e.target.checked)} /> Opening balance</label>
                        <label><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} /> Active</label>
                    </div>

                    <div>
                        <InputLabel value="Sort order" />
                        <TextInput type="number" min="0" value={form.data.sort_order} onChange={(e) => form.setData('sort_order', e.target.value)} className="w-40" />
                        <InputError message={form.errors.sort_order} />
                    </div>

                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setIsOpen(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={form.processing}>{editing ? 'Create Version' : 'Create Reason'}</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
