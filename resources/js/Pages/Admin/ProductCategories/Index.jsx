import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    Tag, 
    Plus, 
    Edit2, 
    Trash2, 
    Search,
    Filter,
    FolderTree,
    X,
    CheckCircle2,
    AlertCircle,
    Download,
    Upload,
    FileSpreadsheet,
    ShieldCheck
} from 'lucide-react';

export default function Index({ auth, categories, importPreview }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingCategory, setEditingCategory] = useState(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
    const [pendingDeleteCategory, setPendingDeleteCategory] = useState(null);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const canPreviewImport = auth?.permissions?.includes('manage_products') && auth?.tenant?.subscription?.features?.includes('catalog.edit');

    const { data, setData, post, put, delete: destroy, processing, errors, reset, clearErrors } = useForm({
        name: '',
        code: '',
        description: '',
        status: 'active',
    });

    const importForm = useForm({
        csv_file: null,
    });

    const openCreateModal = () => {
        setEditingCategory(null);
        reset();
        clearErrors();
        setIsModalOpen(true);
    };

    const openEditModal = (category) => {
        setEditingCategory(category);
        setData({
            name: category.name,
            code: category.code,
            description: category.description || '',
            status: category.status,
        });
        clearErrors();
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setEditingCategory(null);
        reset();
    };

    const submit = (e) => {
        e.preventDefault();
        if (editingCategory) {
            put(route('admin.product-categories.update', editingCategory.id), {
                onSuccess: () => closeModal(),
            });
        } else {
            post(route('admin.product-categories.store'), {
                onSuccess: () => closeModal(),
            });
        }
    };

    const deleteCategory = (category) => {
        setPendingDeleteCategory(category);
        setDeleteConfirmOpen(true);
    };

    const handleConfirmDelete = () => {
        if (pendingDeleteCategory) {
            destroy(route('admin.product-categories.destroy', pendingDeleteCategory.id));
        }
        setDeleteConfirmOpen(false);
        setPendingDeleteCategory(null);
    };

    const submitImportPreview = (e) => {
        e.preventDefault();
        importForm.post(route('admin.product-categories.import.preview'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setIsImportModalOpen(false);
                importForm.reset();
            },
        });
    };

    const filteredCategories = categories.filter(category => 
        category.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        category.code.toLowerCase().includes(searchQuery.toLowerCase())
    );

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Product Categories</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Organize your products into logical groups for better management and reporting.</p>
                    </div>
                    <div className="flex items-center gap-3">
                        <a
                            href={route('admin.product-categories.export')}
                            className="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-bold text-sm shadow-sm transition-all"
                        >
                            <Download size={16} />
                            Export CSV
                        </a>
                        {canPreviewImport && (
                            <button
                                type="button"
                                onClick={() => setIsImportModalOpen(true)}
                                className="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-bold text-sm shadow-sm transition-all"
                            >
                                <Upload size={16} />
                                Import Preview
                            </button>
                        )}
                        <button
                            onClick={openCreateModal}
                            className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
                        >
                            <Plus size={18} />
                            New Category
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Product Categories" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {importPreview?.type === 'categories' && (
                        <div className="mb-8 rounded-[2rem] border border-amber-200 bg-amber-50/80 p-6 shadow-sm">
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <ShieldCheck size={18} className="text-amber-700" />
                                        <p className="text-[11px] font-black uppercase tracking-widest text-amber-700">Category Import Preview</p>
                                    </div>
                                    <p className="mt-2 text-sm font-semibold text-slate-700">Preview only. No categories were created or updated.</p>
                                </div>
                                <div className="grid grid-cols-3 gap-3 text-center">
                                    <div className="rounded-2xl bg-white px-4 py-3 border border-amber-100">
                                        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Rows</p>
                                        <p className="mt-1 text-lg font-black text-slate-800">{importPreview.summary.total_rows}</p>
                                    </div>
                                    <div className="rounded-2xl bg-white px-4 py-3 border border-emerald-100">
                                        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Valid</p>
                                        <p className="mt-1 text-lg font-black text-emerald-600">{importPreview.summary.valid_rows}</p>
                                    </div>
                                    <div className="rounded-2xl bg-white px-4 py-3 border border-rose-100">
                                        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">Invalid</p>
                                        <p className="mt-1 text-lg font-black text-rose-600">{importPreview.summary.invalid_rows}</p>
                                    </div>
                                </div>
                            </div>

                            {(importPreview.missing_columns?.length > 0 || importPreview.unexpected_columns?.length > 0) && (
                                <div className="mt-4 grid gap-3 lg:grid-cols-2">
                                    {importPreview.missing_columns?.length > 0 && (
                                        <div className="rounded-2xl bg-white border border-rose-100 px-4 py-3">
                                            <p className="text-[10px] font-black uppercase tracking-widest text-rose-600">Missing Columns</p>
                                            <p className="mt-2 text-sm font-semibold text-slate-700">{importPreview.missing_columns.join(', ')}</p>
                                        </div>
                                    )}
                                    {importPreview.unexpected_columns?.length > 0 && (
                                        <div className="rounded-2xl bg-white border border-slate-200 px-4 py-3">
                                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">Unexpected Columns</p>
                                            <p className="mt-2 text-sm font-semibold text-slate-700">{importPreview.unexpected_columns.join(', ')}</p>
                                        </div>
                                    )}
                                </div>
                            )}

                            {importPreview.rows?.some((row) => row.errors.length > 0) && (
                                <div className="mt-4 rounded-2xl bg-white border border-amber-100 overflow-hidden">
                                    <div className="px-4 py-3 border-b border-slate-100 bg-slate-50/80">
                                        <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">Row Validation Findings</p>
                                    </div>
                                    <div className="divide-y divide-slate-100">
                                        {importPreview.rows.filter((row) => row.errors.length > 0).slice(0, 5).map((row) => (
                                            <div key={row.row_number} className="px-4 py-3">
                                                <p className="text-xs font-black uppercase tracking-widest text-rose-600">Row {row.row_number}</p>
                                                <p className="mt-2 text-sm text-slate-700">{row.errors.join(' ')}</p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Header Actions */}
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                        <div className="relative flex-1 max-w-md">
                            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <Search size={18} />
                            </div>
                            <input
                                type="text"
                                placeholder="Search by name or code..."
                                className="block w-full pl-11 pr-4 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all shadow-sm"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                        </div>
                        
                        <div className="flex items-center gap-3">
                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">
                                Category CSV exports are read-only and audit-logged.
                            </span>
                            <button className="inline-flex items-center gap-2 px-4 py-3 bg-white border border-slate-200 text-slate-600 rounded-2xl text-sm font-bold shadow-sm hover:bg-slate-50 transition-all">
                                <Filter size={16} />
                                Filters
                            </button>
                        </div>
                    </div>

                    {/* Table */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50">
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Category Info</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Code</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Description</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Status</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {filteredCategories.length > 0 ? (
                                        filteredCategories.map((category) => (
                                            <tr key={category.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center gap-4">
                                                        <div className="p-3 bg-indigo-50 text-indigo-600 rounded-2xl">
                                                            <Tag size={20} />
                                                        </div>
                                                        <span className="font-extrabold text-slate-700">{category.name}</span>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <span className="px-3 py-1 bg-slate-100 text-slate-600 rounded-lg text-xs font-black tracking-wider uppercase">
                                                        {category.code}
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <p className="text-sm text-slate-500 font-medium line-clamp-1 max-w-xs">
                                                        {category.description || <span className="italic opacity-50">No description</span>}
                                                    </p>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex justify-center">
                                                        <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border ${
                                                            category.status === 'active'
                                                                ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                                                : 'bg-slate-50 text-slate-400 border-slate-100'
                                                        }`}>
                                                            {category.status === 'active' ? (
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
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <button
                                                            onClick={() => openEditModal(category)}
                                                            className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                                        >
                                                            <Edit2 size={18} />
                                                        </button>
                                                        <button
                                                            onClick={() => deleteCategory(category)}
                                                            className="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all"
                                                        >
                                                            <Trash2 size={18} />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="5" className="px-8 py-20 text-center">
                                                <div className="flex flex-col items-center justify-center">
                                                    <div className="p-6 bg-slate-50 rounded-full mb-4">
                                                        <FolderTree size={40} className="text-slate-300" />
                                                    </div>
                                                    <h3 className="text-lg font-bold text-slate-800">No categories found</h3>
                                                    <p className="text-slate-500 text-sm mt-1 max-w-xs mx-auto">
                                                        {searchQuery ? "We couldn't find any categories matching your search." : "Start by creating your first product category to organize your catalog."}
                                                    </p>
                                                    {searchQuery && (
                                                        <button 
                                                            onClick={() => setSearchQuery('')}
                                                            className="mt-4 text-indigo-600 font-bold text-sm"
                                                        >
                                                            Clear Search
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

            {/* Create/Edit Modal */}
            <Modal show={isModalOpen} onClose={closeModal} maxWidth="xl">
                <form onSubmit={submit} className="p-8">
                    <div className="flex items-center justify-between mb-8">
                        <div>
                            <h3 className="text-xl font-black text-slate-800">
                                {editingCategory ? 'Edit Category' : 'New Category'}
                            </h3>
                            <p className="text-sm text-slate-500 font-medium mt-1">
                                {editingCategory ? 'Update existing category details.' : 'Define a new category for your products.'}
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
                            <InputLabel htmlFor="name" value="Category Name" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                            <TextInput
                                id="name"
                                type="text"
                                className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                isFocused
                                placeholder="e.g. Beverages, Electronics, Services"
                            />
                            <InputError message={errors.name} className="mt-2" />
                        </div>

                        <div>
                            <InputLabel htmlFor="code" value="Category Code" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                            <TextInput
                                id="code"
                                type="text"
                                className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all uppercase"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                required
                                placeholder="e.g. BEV, ELEC, SERV"
                            />
                            <InputError message={errors.code} className="mt-2" />
                            <p className="mt-1.5 text-[10px] text-slate-400 font-bold uppercase tracking-tighter">Unique identifier for the category (Short codes preferred).</p>
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                            <textarea
                                id="description"
                                className="mt-1 block w-full border-slate-200 rounded-2xl py-3 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-sm transition-all text-sm font-medium h-24"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="Optional description of what this category contains..."
                            />
                            <InputError message={errors.description} className="mt-2" />
                        </div>

                        <div>
                            <InputLabel value="Visibility Status" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                            <div className="grid grid-cols-2 gap-4 mt-2">
                                <button
                                    type="button"
                                    onClick={() => setData('status', 'active')}
                                    className={`flex items-center justify-center gap-3 p-4 rounded-2xl border-2 transition-all ${
                                        data.status === 'active'
                                            ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                            : 'border-slate-100 bg-slate-50 text-slate-400 hover:border-slate-200'
                                    }`}
                                >
                                    <div className={`p-1.5 rounded-full ${data.status === 'active' ? 'bg-indigo-500 text-white' : 'bg-slate-200 text-white'}`}>
                                        <CheckCircle2 size={12} />
                                    </div>
                                    <span className="text-sm font-black uppercase tracking-widest">Active</span>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setData('status', 'inactive')}
                                    className={`flex items-center justify-center gap-3 p-4 rounded-2xl border-2 transition-all ${
                                        data.status === 'inactive'
                                            ? 'border-slate-500 bg-slate-50 text-slate-700'
                                            : 'border-slate-100 bg-slate-50 text-slate-400 hover:border-slate-200'
                                    }`}
                                >
                                    <div className={`p-1.5 rounded-full ${data.status === 'inactive' ? 'bg-slate-500 text-white' : 'bg-slate-200 text-white'}`}>
                                        <AlertCircle size={12} />
                                    </div>
                                    <span className="text-sm font-black uppercase tracking-widest">Inactive</span>
                                </button>
                            </div>
                            <InputError message={errors.status} className="mt-2" />
                        </div>
                    </div>

                    <div className="mt-10 flex items-center justify-end gap-4">
                        <SecondaryButton onClick={closeModal} className="px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-xs border-none bg-slate-100 hover:bg-slate-200">
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={processing} className="px-8 py-3 rounded-2xl font-black uppercase tracking-widest text-xs shadow-lg shadow-indigo-600/20 bg-indigo-600 hover:bg-indigo-500">
                            {editingCategory ? 'Update Category' : 'Create Category'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={isImportModalOpen} onClose={() => setIsImportModalOpen(false)} maxWidth="2xl">
                <form onSubmit={submitImportPreview} className="p-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h3 className="text-xl font-black text-slate-800">Category Import Preview</h3>
                            <p className="text-sm text-slate-500 font-medium mt-1">Upload a CSV for validation-only preview. No categories will be created or updated.</p>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 mb-6">
                        <div className="flex items-start gap-3">
                            <FileSpreadsheet size={18} className="text-indigo-600 mt-0.5" />
                            <div>
                                <p className="text-[11px] font-black uppercase tracking-widest text-slate-500">Template And Preview</p>
                                <p className="mt-2 text-sm text-slate-600">Download the current category template, populate your rows, then upload the CSV to preview validation results only.</p>
                                <a
                                    href={route('admin.product-categories.import.template')}
                                    className="mt-3 inline-flex items-center gap-2 text-sm font-bold text-indigo-600 hover:text-indigo-500"
                                >
                                    <Download size={16} />
                                    Download Category Template
                                </a>
                            </div>
                        </div>
                    </div>

                    <label className="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">CSV File</label>
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        onChange={(e) => importForm.setData('csv_file', e.target.files?.[0] ?? null)}
                        className="block w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium shadow-sm"
                    />
                    <InputError message={importForm.errors.csv_file} className="mt-2" />

                    <div className="mt-8 flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setIsImportModalOpen(false)}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={importForm.processing}>{importForm.processing ? 'Previewing...' : 'Run Preview'}</PrimaryButton>
                    </div>
                </form>
            </Modal>
            {deleteConfirmOpen && (
                <PremiumDialog
                    isOpen={deleteConfirmOpen}
                    type="danger"
                    title="Delete Category"
                    message={`Are you sure you want to delete ${pendingDeleteCategory?.name}? This action is terminal and will permanently remove this category from the product catalog categorization system.`}
                    confirmLabel="Delete Category"
                    onConfirm={handleConfirmDelete}
                    onCancel={() => {
                        setDeleteConfirmOpen(false);
                        setPendingDeleteCategory(null);
                    }}
                />
            )}
        </AuthenticatedLayout>
    );
}
