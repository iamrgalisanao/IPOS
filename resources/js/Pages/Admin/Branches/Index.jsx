import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { 
    GitBranch, 
    Edit2, 
    Search,
    X,
    CheckCircle2,
    AlertTriangle,
    ShieldAlert
} from 'lucide-react';

export default function Index({ auth, branches, filters }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedBranch, setSelectedBranch] = useState(null);
    const [searchQuery, setSearchQuery] = useState(filters.search || '');

    const { data, setData, put, processing, errors, reset } = useForm({
        inventory_deduction_policy: 'strict_block',
    });

    const openEditModal = (branch) => {
        setSelectedBranch(branch);
        setData({
            inventory_deduction_policy: branch.inventory_deduction_policy || 'strict_block',
        });
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setSelectedBranch(null);
        reset();
    };

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.branches.inventory-policy.update', selectedBranch.id), {
            onSuccess: () => closeModal(),
        });
    };

    const handleSearch = (e) => {
        setSearchQuery(e.target.value);
        // We can do client-side filtering or reload with Inertia
    };

    const filteredBranches = branches.data.filter(branch => 
        branch.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        branch.branch_code.toLowerCase().includes(searchQuery.toLowerCase())
    );

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div>
                    <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Branch Settings</h2>
                    <p className="text-sm text-slate-500 font-medium mt-1">Manage configurations and inventory deduction policies for your retail locations.</p>
                </div>
            }
        >
            <Head title="Branch Settings" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Search */}
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                        <div className="relative flex-1 max-w-md">
                            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <Search size={18} />
                            </div>
                            <input
                                type="text"
                                placeholder="Search branches..."
                                className="block w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                value={searchQuery}
                                onChange={handleSearch}
                            />
                        </div>
                    </div>

                    {/* Table */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50">
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Branch Name</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Branch Code</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Status</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Deduction Policy</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {filteredBranches.length > 0 ? (
                                        filteredBranches.map((branch) => (
                                            <tr key={branch.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center gap-4">
                                                        <div className="p-3 bg-indigo-50 text-indigo-600 rounded-2xl">
                                                            <GitBranch size={20} />
                                                        </div>
                                                        <span className="font-extrabold text-slate-700">{branch.name}</span>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <span className="px-3 py-1 bg-slate-100 text-slate-600 rounded-lg text-xs font-black tracking-wider uppercase">
                                                        {branch.branch_code}
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border ${
                                                        branch.status === 'active'
                                                            ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                                            : 'bg-slate-50 text-slate-400 border-slate-100'
                                                    }`}>
                                                        {branch.status === 'active' ? (
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
                                                    <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-extrabold border ${
                                                        branch.inventory_deduction_policy === 'allow_negative_with_warning'
                                                            ? 'bg-amber-50 text-amber-700 border-amber-200'
                                                            : 'bg-indigo-50 text-indigo-700 border-indigo-200'
                                                    }`}>
                                                        {branch.inventory_deduction_policy === 'allow_negative_with_warning' ? (
                                                            <>
                                                                <AlertTriangle size={14} className="text-amber-500" />
                                                                Allow Soft Negatives
                                                            </>
                                                        ) : (
                                                            <>
                                                                <ShieldAlert size={14} className="text-indigo-500" />
                                                                Strict Block
                                                            </>
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <button
                                                            onClick={() => openEditModal(branch)}
                                                            className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                                            title="Edit Deduction Policy"
                                                        >
                                                            <Edit2 size={18} />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="5" className="px-8 py-20 text-center text-slate-500">
                                                No branches found matching your search.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {/* Edit Policy Modal */}
            <Modal show={isModalOpen} onClose={closeModal} maxWidth="lg">
                <form onSubmit={submit} className="p-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h3 className="text-xl font-black text-slate-800">Edit Inventory Policy</h3>
                            <p className="text-sm text-slate-500 font-medium mt-1">
                                {selectedBranch?.name} ({selectedBranch?.branch_code})
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
                            <InputLabel value="Inventory Deduction Policy" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                            <div className="space-y-3">
                                <button
                                    type="button"
                                    onClick={() => setData('inventory_deduction_policy', 'strict_block')}
                                    className={`flex items-start gap-4 p-4 w-full rounded-2xl border-2 text-left transition-all ${
                                        data.inventory_deduction_policy === 'strict_block'
                                            ? 'border-indigo-500 bg-indigo-50/50'
                                            : 'border-slate-100 bg-slate-50 hover:border-slate-200'
                                    }`}
                                >
                                    <div className="mt-1">
                                        <ShieldAlert className={data.inventory_deduction_policy === 'strict_block' ? 'text-indigo-600' : 'text-slate-400'} size={20} />
                                    </div>
                                    <div>
                                        <div className="font-extrabold text-slate-800">Strict Block Shortages</div>
                                        <div className="text-xs text-slate-500 mt-1 font-medium leading-relaxed">
                                            POS sales transaction completes only if all ingredients/products have sufficient inventory levels. Fails closed under stockout conditions.
                                        </div>
                                    </div>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setData('inventory_deduction_policy', 'allow_negative_with_warning')}
                                    className={`flex items-start gap-4 p-4 w-full rounded-2xl border-2 text-left transition-all ${
                                        data.inventory_deduction_policy === 'allow_negative_with_warning'
                                            ? 'border-indigo-500 bg-indigo-50/50'
                                            : 'border-slate-100 bg-slate-50 hover:border-slate-200'
                                    }`}
                                >
                                    <div className="mt-1">
                                        <AlertTriangle className={data.inventory_deduction_policy === 'allow_negative_with_warning' ? 'text-amber-600' : 'text-slate-400'} size={20} />
                                    </div>
                                    <div>
                                        <div className="font-extrabold text-slate-800">Allow Soft Negatives</div>
                                        <div className="text-xs text-slate-500 mt-1 font-medium leading-relaxed">
                                            POS sales transaction completes even under stockout conditions. Generates negative inventory and appends a variance warning log.
                                        </div>
                                    </div>
                                </button>
                            </div>
                            <InputError message={errors.inventory_deduction_policy} className="mt-2" />
                        </div>
                    </div>

                    <div className="mt-8 flex items-center justify-end gap-4">
                        <SecondaryButton onClick={closeModal} className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs border-none bg-slate-100 hover:bg-slate-200">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={processing} className="px-6 py-2.5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-lg shadow-indigo-600/20 bg-indigo-600 hover:bg-indigo-500">
                            Save Changes
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
