import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { 
    Plus, 
    Edit2, 
    Trash2, 
    AlertCircle, 
    ArrowDownCircle, 
    ArrowUpCircle, 
    CheckCircle2, 
    X,
    Shield,
    GitBranch,
    Settings
} from 'lucide-react';

export default function Index({ auth, reasons, branches }) {
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
    const [selectedReason, setSelectedReason] = useState(null);
    const [activeTab, setActiveTab] = useState('cash_drop'); // cash_drop or cash_top_up

    // Add Form
    const addForm = useForm({
        event_type: 'cash_drop',
        code: '',
        name: '',
        branch_id: '',
        requires_manager_approval: false,
        sort_order: 0,
    });

    // Edit Form
    const editForm = useForm({
        name: '',
        requires_manager_approval: false,
        is_active: true,
        sort_order: 0,
    });

    // Delete Form (simple deactivation post)
    const deleteForm = useForm({});

    const openAddModal = () => {
        addForm.reset();
        addForm.setData('event_type', activeTab);
        setIsAddModalOpen(true);
    };

    const closeAddModal = () => {
        setIsAddModalOpen(false);
        addForm.reset();
    };

    const submitAdd = (e) => {
        e.preventDefault();
        addForm.post(route('admin.cash-drawer-reasons.store'), {
            onSuccess: () => closeAddModal(),
        });
    };

    const openEditModal = (reason) => {
        setSelectedReason(reason);
        editForm.setData({
            name: reason.name,
            requires_manager_approval: !!reason.requires_manager_approval,
            is_active: !!reason.is_active,
            sort_order: reason.sort_order,
        });
        setIsEditModalOpen(true);
    };

    const closeEditModal = () => {
        setIsEditModalOpen(false);
        setSelectedReason(null);
        editForm.reset();
    };

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('admin.cash-drawer-reasons.update', selectedReason.id), {
            onSuccess: () => closeEditModal(),
        });
    };

    const openDeleteModal = (reason) => {
        setSelectedReason(reason);
        setIsDeleteModalOpen(true);
    };

    const closeDeleteModal = () => {
        setIsDeleteModalOpen(false);
        setSelectedReason(null);
    };

    const submitDelete = (e) => {
        e.preventDefault();
        deleteForm.delete(route('admin.cash-drawer-reasons.destroy', selectedReason.id), {
            onSuccess: () => closeDeleteModal(),
        });
    };

    const filteredReasons = reasons.filter(r => r.event_type === activeTab);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight flex items-center gap-2">
                            <Settings className="w-6 h-6 text-indigo-500" />
                            Cash Drawer Reasons
                        </h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Configure drop and top-up reason categories, scopes, and manager authorization rules.</p>
                    </div>
                    <button
                        onClick={openAddModal}
                        className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-2xl text-xs font-black uppercase tracking-wider transition-all shadow-md shadow-indigo-600/20 active:scale-95"
                    >
                        <Plus size={16} />
                        Add New Reason
                    </button>
                </div>
            }
        >
            <Head title="Cash Drawer Reasons" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    
                    {/* Tabs Navigation */}
                    <div className="flex border-b border-slate-200 mb-8 gap-6">
                        <button
                            onClick={() => setActiveTab('cash_drop')}
                            className={`pb-4 text-sm font-black uppercase tracking-widest transition-all relative ${
                                activeTab === 'cash_drop' 
                                    ? 'text-indigo-600 border-b-2 border-indigo-600' 
                                    : 'text-slate-400 hover:text-slate-600 border-b-2 border-transparent'
                            }`}
                        >
                            <span className="flex items-center gap-2">
                                <ArrowDownCircle size={16} className={activeTab === 'cash_drop' ? 'text-indigo-500' : 'text-slate-400'} />
                                Cash Drops (Skims/Expenses)
                            </span>
                        </button>
                        <button
                            onClick={() => setActiveTab('cash_top_up')}
                            className={`pb-4 text-sm font-black uppercase tracking-widest transition-all relative ${
                                activeTab === 'cash_top_up' 
                                    ? 'text-indigo-600 border-b-2 border-indigo-600' 
                                    : 'text-slate-400 hover:text-slate-600 border-b-2 border-transparent'
                            }`}
                        >
                            <span className="flex items-center gap-2">
                                <ArrowUpCircle size={16} className={activeTab === 'cash_top_up' ? 'text-indigo-500' : 'text-slate-400'} />
                                Cash Top-ups (Replenish/Float)
                            </span>
                        </button>
                    </div>

                    {/* Table Card */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50">
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Sort</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Code</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Display Name</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Scope</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Manager Approval</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Status</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {filteredReasons.length > 0 ? (
                                        filteredReasons.map((reason) => (
                                            <tr key={reason.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-8 py-5 font-extrabold text-slate-400 text-sm">
                                                    {reason.sort_order}
                                                </td>
                                                <td className="px-8 py-5 font-black text-slate-700 tracking-wider text-xs">
                                                    <span className="px-2.5 py-1 bg-slate-100 text-slate-650 rounded-lg">
                                                        {reason.code}
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <span className="font-extrabold text-slate-700">{reason.name}</span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    {reason.branch ? (
                                                        <div className="flex items-center gap-1.5 text-xs font-bold text-slate-600">
                                                            <GitBranch size={13} className="text-indigo-500" />
                                                            {reason.branch.name}
                                                        </div>
                                                    ) : (
                                                        <span className="text-xs font-bold text-slate-400">Tenant-Wide</span>
                                                    )}
                                                </td>
                                                <td className="px-8 py-5">
                                                    {reason.requires_manager_approval ? (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-widest bg-amber-50 text-amber-700 border border-amber-100">
                                                            <Shield size={10} />
                                                            Required
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-widest bg-slate-50 text-slate-400 border border-slate-100">
                                                            None
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-8 py-5">
                                                    <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border ${
                                                        reason.is_active
                                                            ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                                            : 'bg-slate-50 text-slate-400 border-slate-100'
                                                    }`}>
                                                        {reason.is_active ? (
                                                            <>
                                                                <CheckCircle2 size={10} />
                                                                Active
                                                            </>
                                                        ) : (
                                                            <>
                                                                <X size={10} />
                                                                Inactive
                                                            </>
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <button
                                                            onClick={() => openEditModal(reason)}
                                                            className="p-2 text-slate-400 hover:text-indigo-650 hover:bg-indigo-50 rounded-xl transition-all"
                                                            title="Edit Reason"
                                                        >
                                                            <Edit2 size={16} />
                                                        </button>
                                                        {reason.is_active && (
                                                            <button
                                                                onClick={() => openDeleteModal(reason)}
                                                                className="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all"
                                                                title="Deactivate Reason"
                                                            >
                                                                <Trash2 size={16} />
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="7" className="px-8 py-20 text-center text-slate-500">
                                                No configuration reasons found for this event type.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {/* Add Modal */}
            <Modal show={isAddModalOpen} onClose={closeAddModal} maxWidth="lg">
                <form onSubmit={submitAdd} className="p-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h3 className="text-xl font-black text-slate-800">Add Cash Drawer Reason</h3>
                            <p className="text-sm text-slate-500 font-medium mt-1">Configure a new standard code category for POS drawer events.</p>
                        </div>
                        <button
                            type="button"
                            onClick={closeAddModal}
                            className="p-2 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-50 transition-all"
                        >
                            <X size={20} />
                        </button>
                    </div>

                    <div className="space-y-5">
                        {/* Event Type */}
                        <div>
                            <InputLabel value="Event Type" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5" />
                            <select
                                className="block w-full border-slate-200 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                value={addForm.data.event_type}
                                onChange={(e) => addForm.setData('event_type', e.target.value)}
                            >
                                <option value="cash_drop">Cash Drop (Skim / Outflow)</option>
                                <option value="cash_top_up">Cash Top-up (Replenish / Inflow)</option>
                            </select>
                            <InputError message={addForm.errors.event_type} className="mt-1" />
                        </div>

                        {/* Code */}
                        <div>
                            <InputLabel htmlFor="add_code" value="Reason Code" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5" />
                            <TextInput
                                id="add_code"
                                type="text"
                                className="block w-full uppercase"
                                placeholder="E.g. CORRECTION_ADJUST"
                                value={addForm.data.code}
                                onChange={(e) => addForm.setData('code', e.target.value.toUpperCase())}
                                required
                            />
                            <p className="text-[10px] text-slate-450 mt-1 font-medium">Unique code. Uppercase alphanumeric with underscores only.</p>
                            <InputError message={addForm.errors.code} className="mt-1" />
                        </div>

                        {/* Display Name */}
                        <div>
                            <InputLabel htmlFor="add_name" value="Display Name" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5" />
                            <TextInput
                                id="add_name"
                                type="text"
                                className="block w-full"
                                placeholder="E.g. Shift Drawer Correction"
                                value={addForm.data.name}
                                onChange={(e) => addForm.setData('name', e.target.value)}
                                required
                            />
                            <InputError message={addForm.errors.name} className="mt-1" />
                        </div>

                        {/* Branch Scope */}
                        <div>
                            <InputLabel value="Branch Scope (Optional)" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5" />
                            <select
                                className="block w-full border-slate-200 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                value={addForm.data.branch_id}
                                onChange={(e) => addForm.setData('branch_id', e.target.value)}
                            >
                                <option value="">Tenant-Wide (All Branches)</option>
                                {branches.map(b => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                            <InputError message={addForm.errors.branch_id} className="mt-1" />
                        </div>

                        {/* Sort Order */}
                        <div>
                            <InputLabel htmlFor="add_sort" value="Sort Order" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5" />
                            <TextInput
                                id="add_sort"
                                type="number"
                                className="block w-full"
                                value={addForm.data.sort_order}
                                onChange={(e) => addForm.setData('sort_order', parseInt(e.target.value) || 0)}
                                required
                            />
                            <InputError message={addForm.errors.sort_order} className="mt-1" />
                        </div>

                        {/* Manager Approval Required */}
                        <div className="flex items-center gap-3 mt-4">
                            <input
                                id="add_manager"
                                type="checkbox"
                                className="rounded text-indigo-600 focus:ring-indigo-500 border-slate-350 w-4 h-4 cursor-pointer"
                                checked={addForm.data.requires_manager_approval}
                                onChange={(e) => addForm.setData('requires_manager_approval', e.target.checked)}
                            />
                            <label htmlFor="add_manager" className="text-xs font-extrabold text-slate-700 cursor-pointer select-none">
                                Require manager credentials to record this event
                            </label>
                        </div>
                    </div>

                    <div className="mt-8 flex items-center justify-end gap-4">
                        <SecondaryButton onClick={closeAddModal} className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs border-none bg-slate-100 hover:bg-slate-200">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={addForm.processing} className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-lg shadow-indigo-600/20 bg-indigo-600 hover:bg-indigo-500">
                            Save Reason
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* Edit Modal */}
            <Modal show={isEditModalOpen} onClose={closeEditModal} maxWidth="lg">
                <form onSubmit={submitEdit} className="p-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h3 className="text-xl font-black text-slate-800">Edit Cash Drawer Reason</h3>
                            <p className="text-sm text-slate-500 font-medium mt-1">Reason code: <span className="font-extrabold text-indigo-600">{selectedReason?.code}</span></p>
                        </div>
                        <button
                            type="button"
                            onClick={closeEditModal}
                            className="p-2 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-50 transition-all"
                        >
                            <X size={20} />
                        </button>
                    </div>

                    <div className="space-y-5">
                        {/* Display Name */}
                        <div>
                            <InputLabel htmlFor="edit_name" value="Display Name" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5" />
                            <TextInput
                                id="edit_name"
                                type="text"
                                className="block w-full"
                                value={editForm.data.name}
                                onChange={(e) => editForm.setData('name', e.target.value)}
                                required
                            />
                            <InputError message={editForm.errors.name} className="mt-1" />
                        </div>

                        {/* Sort Order */}
                        <div>
                            <InputLabel htmlFor="edit_sort" value="Sort Order" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5" />
                            <TextInput
                                id="edit_sort"
                                type="number"
                                className="block w-full"
                                value={editForm.data.sort_order}
                                onChange={(e) => editForm.setData('sort_order', parseInt(e.target.value) || 0)}
                                required
                            />
                            <InputError message={editForm.errors.sort_order} className="mt-1" />
                        </div>

                        {/* Manager Approval Required */}
                        <div className="flex items-center gap-3">
                            <input
                                id="edit_manager"
                                type="checkbox"
                                className="rounded text-indigo-600 focus:ring-indigo-500 border-slate-350 w-4 h-4 cursor-pointer"
                                checked={editForm.data.requires_manager_approval}
                                onChange={(e) => editForm.setData('requires_manager_approval', e.target.checked)}
                            />
                            <label htmlFor="edit_manager" className="text-xs font-extrabold text-slate-700 cursor-pointer select-none">
                                Require manager credentials to record this event
                            </label>
                        </div>

                        {/* Is Active Toggle */}
                        <div className="flex items-center gap-3">
                            <input
                                id="edit_active"
                                type="checkbox"
                                className="rounded text-indigo-600 focus:ring-indigo-500 border-slate-350 w-4 h-4 cursor-pointer"
                                checked={editForm.data.is_active}
                                onChange={(e) => editForm.setData('is_active', e.target.checked)}
                            />
                            <label htmlFor="edit_active" className="text-xs font-extrabold text-slate-700 cursor-pointer select-none">
                                Reason is active and available in POS
                            </label>
                        </div>
                    </div>

                    <div className="mt-8 flex items-center justify-end gap-4">
                        <SecondaryButton onClick={closeEditModal} className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs border-none bg-slate-100 hover:bg-slate-200">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={editForm.processing} className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-lg shadow-indigo-600/20 bg-indigo-600 hover:bg-indigo-500">
                            Save Changes
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* Delete/Deactivate Confirmation Modal */}
            <Modal show={isDeleteModalOpen} onClose={closeDeleteModal} maxWidth="md">
                <form onSubmit={submitDelete} className="p-8">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-black text-slate-800 flex items-center gap-2">
                            <AlertCircle className="w-5 h-5 text-rose-500" />
                            Deactivate Cash Drawer Reason
                        </h3>
                        <button
                            type="button"
                            onClick={closeDeleteModal}
                            className="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-50 transition-all"
                        >
                            <X size={18} />
                        </button>
                    </div>
                    <p className="text-sm text-slate-500 leading-relaxed font-medium">
                        Are you sure you want to deactivate <span className="font-extrabold text-slate-700">"{selectedReason?.name}" ({selectedReason?.code})</span>? 
                        This reason code will no longer be visible or selectable in new cash drawer events on POS terminals. Historical event records will be unaffected.
                    </p>
                    <div className="mt-8 flex justify-end gap-4">
                        <SecondaryButton onClick={closeDeleteModal} className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs border-none bg-slate-100 hover:bg-slate-200">
                            Cancel
                        </SecondaryButton>
                        <button
                            type="submit"
                            className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs bg-rose-600 hover:bg-rose-500 text-white shadow-lg shadow-rose-600/20 active:scale-95 transition-all"
                            disabled={deleteForm.processing}
                        >
                            Deactivate Reason
                        </button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
