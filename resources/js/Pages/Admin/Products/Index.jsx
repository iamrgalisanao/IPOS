import React, { useState } from 'react';
import { Link, Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import PremiumDialog from '@/Components/PremiumDialog';
import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { 
    Package, 
    Plus, 
    Search, 
    Edit2, 
    Trash2, 
    Filter,
    MoreVertical,
    ChevronRight,
    Tag,
    AlertCircle,
    CheckCircle2,
    DollarSign,
    Layers,
    Download,
    Upload,
    FileSpreadsheet,
    ShieldCheck
} from 'lucide-react';

export default function Index({ auth, products, categories, filters, importPreview }) {
    const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
    const [pendingDeleteProduct, setPendingDeleteProduct] = useState(null);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const canPreviewImport = auth?.permissions?.includes('manage_products') && auth?.tenant?.subscription?.features?.includes('catalog.edit');
    const importForm = useForm({
        csv_file: null,
    });

    const onSearchChange = (e) => {
        router.get(route('admin.products.index'), { 
            ...filters, 
            search: e.target.value 
        }, { 
            preserveState: true,
            replace: true 
        });
    };

    const onCategoryChange = (e) => {
        router.get(route('admin.products.index'), { 
            ...filters, 
            category_id: e.target.value 
        }, { 
            preserveState: true 
        });
    };

    const deleteProduct = (product) => {
        setPendingDeleteProduct(product);
        setDeleteConfirmOpen(true);
    };

    const handleConfirmDelete = () => {
        if (pendingDeleteProduct) {
            router.delete(route('admin.products.destroy', pendingDeleteProduct.id));
        }
        setDeleteConfirmOpen(false);
        setPendingDeleteProduct(null);
    };

    const submitImportPreview = (e) => {
        e.preventDefault();
        importForm.post(route('admin.products.import.preview'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setIsImportModalOpen(false);
                importForm.reset();
            },
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Product Catalog</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Manage your centralized inventory master and pricing strategies.</p>
                    </div>
                        <div className="flex items-center gap-3">
                            <a
                                href={route('admin.products.export', filters)}
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
                            <Link
                                href={route('admin.products.create')}
                                className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 transition-all active:scale-95"
                            >
                                <Plus size={18} />
                                Add Product
                            </Link>
                        </div>
                </div>
            }
        >
            <Head title="Products" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {importPreview?.type === 'products' && (
                        <div className="mb-8 rounded-[2rem] border border-amber-200 bg-amber-50/80 p-6 shadow-sm">
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <ShieldCheck size={18} className="text-amber-700" />
                                        <p className="text-[11px] font-black uppercase tracking-widest text-amber-700">Product Import Preview</p>
                                    </div>
                                    <p className="mt-2 text-sm font-semibold text-slate-700">Preview only. No products were created or updated.</p>
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

                    {/* Filter Bar */}
                    <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm mb-8">
                        <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div className="md:col-span-2 relative">
                                <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                    <Search size={18} />
                                </div>
                                <input
                                    type="text"
                                    placeholder="Search by SKU, Name, or Barcode..."
                                    className="block w-full pl-11 pr-4 py-3 bg-slate-50 border-none rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 transition-all"
                                    value={filters.search || ''}
                                    onChange={onSearchChange}
                                />
                            </div>
                            
                            <div>
                                <select
                                    className="block w-full px-4 py-3 bg-slate-50 border-none rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 transition-all"
                                    value={filters.category_id || ''}
                                    onChange={onCategoryChange}
                                >
                                    <option value="">All Categories</option>
                                    {categories.map(category => (
                                        <option key={category.id} value={category.id}>{category.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="flex items-center justify-end">
                                <div className="flex items-center gap-4">
                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">
                                        CSV exports are read-only and follow current catalog filters.
                                    </span>
                                    <Link 
                                        href={route('admin.product-categories.index')}
                                        className="inline-flex items-center gap-2 text-xs font-black uppercase tracking-widest text-indigo-600 hover:text-indigo-500 transition-colors"
                                    >
                                        <Layers size={14} />
                                        Manage Categories
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Table */}
                    <div className="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50">
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Product Details</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Category</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400">Price</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Tracking</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center">Status</th>
                                        <th className="px-8 py-5 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {products.data.length > 0 ? (
                                        products.data.map((product) => (
                                            <tr key={product.id} className="hover:bg-slate-50/50 transition-colors group">
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center gap-4">
                                                        <div className="p-3 bg-slate-100 text-slate-500 rounded-2xl group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-all">
                                                            <Package size={20} />
                                                        </div>
                                                        <div>
                                                            <p className="font-extrabold text-slate-800">{product.name}</p>
                                                            <div className="flex items-center gap-2 mt-1">
                                                                <span className="text-[10px] font-black text-slate-400 uppercase tracking-tighter">SKU: {product.sku}</span>
                                                                <div className="w-1 h-1 bg-slate-300 rounded-full" />
                                                                <span className="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">{product.unit_of_measure}</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <span className="inline-flex items-center gap-1.5 px-3 py-1 bg-indigo-50 text-indigo-600 rounded-lg text-[10px] font-black tracking-widest uppercase">
                                                        <Tag size={10} />
                                                        {product.category?.name || 'Uncategorized'}
                                                    </span>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div>
                                                        <p className="text-sm font-black text-slate-800">
                                                            {new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(product.selling_price)}
                                                        </p>
                                                        {product.cost_price && (
                                                            <p className="text-[10px] font-bold text-slate-400 mt-0.5 italic">Cost: {product.cost_price}</p>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex justify-center">
                                                        {product.is_inventory_tracked ? (
                                                            <div className="p-1.5 bg-emerald-50 text-emerald-600 rounded-lg" title="Inventory Tracked">
                                                                <CheckCircle2 size={16} />
                                                            </div>
                                                        ) : (
                                                            <div className="p-1.5 bg-slate-100 text-slate-400 rounded-lg" title="Not Tracked">
                                                                <AlertCircle size={16} />
                                                            </div>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex justify-center">
                                                        <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border ${
                                                            product.status === 'active'
                                                                ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                                                                : 'bg-rose-50 text-rose-600 border-rose-100'
                                                        }`}>
                                                            {product.status}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-8 py-5">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Link
                                                            href={route('admin.products.edit', product.id)}
                                                            className="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-xl transition-all"
                                                        >
                                                            <Edit2 size={18} />
                                                        </Link>
                                                        <button
                                                            onClick={() => deleteProduct(product)}
                                                            className="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all"
                                                        >
                                                            <Trash2 size={18} />
                                                        </button>
                                                        <div className="h-4 w-px bg-slate-100 mx-1" />
                                                        <button className="p-2 text-slate-400 hover:text-slate-600 rounded-xl transition-all">
                                                            <MoreVertical size={18} />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="6" className="px-8 py-20 text-center">
                                                <div className="flex flex-col items-center justify-center">
                                                    <div className="p-6 bg-slate-50 rounded-full mb-4">
                                                        <Package size={40} className="text-slate-300" />
                                                    </div>
                                                    <h3 className="text-lg font-bold text-slate-800">Catalogue is empty</h3>
                                                    <p className="text-slate-500 text-sm mt-1 max-w-xs mx-auto">
                                                        No products match your current filters. Clear filters or add a new product.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        
                        {products.links && (
                            <div className="px-8 py-6 border-t border-slate-50 bg-slate-50/30">
                                <Pagination links={products.links} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
            {deleteConfirmOpen && (
                <PremiumDialog
                    isOpen={deleteConfirmOpen}
                    type="danger"
                    title="Delete Product"
                    message={`Are you sure you want to delete ${pendingDeleteProduct?.name}? This action is terminal and will permanently remove the product from the catalog.`}
                    confirmLabel="Delete Product"
                    onConfirm={handleConfirmDelete}
                    onCancel={() => {
                        setDeleteConfirmOpen(false);
                        setPendingDeleteProduct(null);
                    }}
                />
            )}

            <Modal show={isImportModalOpen} onClose={() => setIsImportModalOpen(false)} maxWidth="2xl">
                <form onSubmit={submitImportPreview} className="p-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h3 className="text-xl font-black text-slate-800">Product Import Preview</h3>
                            <p className="text-sm text-slate-500 font-medium mt-1">Upload a CSV for validation-only preview. No products will be created or updated.</p>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 mb-6">
                        <div className="flex items-start gap-3">
                            <FileSpreadsheet size={18} className="text-indigo-600 mt-0.5" />
                            <div>
                                <p className="text-[11px] font-black uppercase tracking-widest text-slate-500">Template And Preview</p>
                                <p className="mt-2 text-sm text-slate-600">Download the current template, populate your rows, then upload the CSV to preview validation results only.</p>
                                <a
                                    href={route('admin.products.import.template')}
                                    className="mt-3 inline-flex items-center gap-2 text-sm font-bold text-indigo-600 hover:text-indigo-500"
                                >
                                    <Download size={16} />
                                    Download Product Template
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
        </AuthenticatedLayout>
    );
}
