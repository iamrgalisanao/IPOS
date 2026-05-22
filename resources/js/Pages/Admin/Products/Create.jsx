import React from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { 
    ArrowLeft, 
    Save, 
    Package, 
    Tag, 
    DollarSign, 
    Settings, 
    Info,
    CheckCircle2,
    XCircle,
    Hash,
    AlertCircle
} from 'lucide-react';

export default function Create({ auth, categories, taxCategories, uomOptions, productTypes }) {
    const { data, setData, post, processing, errors } = useForm({
        product_category_id: '',
        tax_category_id: '',
        name: '',
        sku: '',
        barcode: '',
        description: '',
        unit_of_measure: 'piece',
        selling_price: '0.00',
        cost_price: '',
        is_taxable: true,
        is_inventory_tracked: true,
        is_discountable: true,
        status: 'active',
        product_type: 'finished_good',
        is_sellable: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.products.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link
                            href={route('admin.products.index')}
                            className="inline-flex items-center gap-2 px-3 py-2.5 bg-white border border-slate-100 rounded-xl text-slate-500 hover:text-indigo-600 hover:shadow-md transition-all text-xs font-black uppercase tracking-widest"
                        >
                            <ArrowLeft size={20} />
                            Back to Products
                        </Link>
                        <div>
                            <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Add New Product</h2>
                            <p className="text-sm text-slate-500 font-medium mt-1">Populate your catalog with fresh inventory master records.</p>
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Create Product" />

            <div className="py-8">
                <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit} className="space-y-8">
                        {Object.keys(errors).length > 0 ? (
                            <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 flex items-center gap-3">
                                <AlertCircle size={16} className="text-rose-600 shrink-0" />
                                <p className="text-[10px] font-black uppercase tracking-widest text-rose-700">
                                    {Object.keys(errors).length} field{Object.keys(errors).length > 1 ? 's' : ''} require attention — review highlighted fields below.
                                </p>
                            </div>
                        ) : (
                            <div className="rounded-2xl border border-indigo-100 bg-indigo-50/50 px-4 py-3">
                                <p className="text-[10px] font-black uppercase tracking-widest text-indigo-700">Required fields are marked with *</p>
                            </div>
                        )}

                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                            {/* Left Column: General Info */}
                            <div className="lg:col-span-2 space-y-8">
                                <section className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm relative overflow-hidden">
                                    <div className="absolute top-0 right-0 p-8 text-slate-50 opacity-10 pointer-events-none">
                                        <Package size={120} />
                                    </div>
                                    
                                    <div className="flex items-center gap-3 mb-8">
                                        <div className="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl">
                                            <Info size={20} />
                                        </div>
                                        <h3 className="text-lg font-black text-slate-800 uppercase tracking-widest">General Information</h3>
                                    </div>

                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div className="md:col-span-2">
                                            <InputLabel htmlFor="name" value="Product Name *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <TextInput
                                                id="name"
                                                className={`block w-full ${errors.name ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-bold`}
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                required
                                                autoFocus
                                                placeholder="e.g. Classic Cappuccino"
                                            />
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">Displayed across POS, receipts, and reports. Use a clear, recognizable name.</p>
                                            <InputError message={errors.name} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="sku" value="SKU / Item Code *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <div className="relative">
                                                <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-300">
                                                    <Hash size={16} />
                                                </div>
                                                <TextInput
                                                    id="sku"
                                                    className={`block w-full pl-11 ${errors.sku ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-black uppercase`}
                                                    value={data.sku}
                                                    onChange={(e) => setData('sku', e.target.value.toUpperCase())}
                                                    required
                                                    placeholder="CAP-001"
                                                />
                                            </div>
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">Unique item code per store. Auto-forced to uppercase. Used for POS lookup and inventory tracking.</p>
                                            <InputError message={errors.sku} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="barcode" value="Barcode (Optional)" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <TextInput
                                                id="barcode"
                                                className="block w-full border-slate-100 bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-medium"
                                                value={data.barcode}
                                                onChange={(e) => setData('barcode', e.target.value)}
                                                placeholder="EAN-13, UPC-A, Code 128, etc."
                                            />
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">Optional. Used for barcode scanner lookup at checkout. Leave blank if no physical barcode.</p>
                                            <InputError message={errors.barcode} className="mt-2" />
                                        </div>

                                        <div className="md:col-span-2">
                                            <InputLabel htmlFor="description" value="Description" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <textarea
                                                id="description"
                                                className="block w-full border-slate-100 bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-medium h-32"
                                                value={data.description}
                                                onChange={(e) => setData('description', e.target.value)}
                                                placeholder="Product details, ingredients, or service scope..."
                                            />
                                            <InputError message={errors.description} className="mt-2" />
                                        </div>
                                    </div>
                                </section>

                                <section className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                                    <div className="flex items-center gap-3 mb-8">
                                        <div className="p-2.5 bg-emerald-50 text-emerald-600 rounded-xl">
                                            <DollarSign size={20} />
                                        </div>
                                        <h3 className="text-lg font-black text-slate-800 uppercase tracking-widest">Base Pricing & Compliance</h3>
                                    </div>

                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <InputLabel htmlFor="selling_price" value="Base Selling Price *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <div className="relative">
                                                <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 font-bold">
                                                    $
                                                </div>
                                                <TextInput
                                                    id="selling_price"
                                                    type="number"
                                                    step="0.01"
                                                    className={`block w-full pl-10 ${errors.selling_price ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-black`}
                                                    value={data.selling_price}
                                                    onChange={(e) => setData('selling_price', e.target.value)}
                                                    required
                                                />
                                            </div>
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">Enter the final customer-facing price. For VAT items, this is treated as VAT-inclusive.</p>
                                            <InputError message={errors.selling_price} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="cost_price" value="Base Cost Price (Optional)" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <div className="relative">
                                                <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-300 font-bold">
                                                    $
                                                </div>
                                                <TextInput
                                                    id="cost_price"
                                                    type="number"
                                                    step="0.01"
                                                    className="block w-full pl-10 border-slate-100 bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-medium"
                                                    value={data.cost_price}
                                                    onChange={(e) => setData('cost_price', e.target.value)}
                                                />
                                            </div>
                                            <InputError message={errors.cost_price} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="tax_category_id" value="Tax Category" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <select
                                                id="tax_category_id"
                                                className={`block w-full border-slate-100 bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-bold ${!data.is_taxable ? 'opacity-50 cursor-not-allowed bg-slate-100' : ''}`}
                                                value={data.tax_category_id}
                                                onChange={(e) => setData('tax_category_id', e.target.value)}
                                                disabled={!data.is_taxable}
                                            >
                                                <option value="">No Tax / Exempt</option>
                                                {taxCategories.map(tax => (
                                                    <option key={tax.id} value={tax.id}>{tax.name} ({tax.rate}%)</option>
                                                ))}
                                            </select>
                                            <InputError message={errors.tax_category_id} className="mt-2" />
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">
                                                Select only when this product is taxable; leave empty for exempt/non-vat items.
                                            </p>
                                        </div>

                                        <div className="flex items-end gap-6 h-full pb-3">
                                            <label className="flex items-center gap-3 cursor-pointer group">
                                                <input
                                                    type="checkbox"
                                                    className="w-5 h-5 rounded-lg border-slate-200 text-indigo-600 focus:ring-indigo-500/20 transition-all cursor-pointer"
                                                    checked={data.is_taxable}
                                                    onChange={(e) => {
                                                        const checked = e.target.checked;
                                                        setData(d => ({
                                                            ...d,
                                                            is_taxable: checked,
                                                            tax_category_id: checked ? d.tax_category_id : ''
                                                        }));
                                                    }}
                                                />
                                                <span className="text-xs font-black uppercase tracking-widest text-slate-500 group-hover:text-indigo-600 transition-colors">Include in tax/compliance computation</span>
                                            </label>
                                        </div>
                                    </div>

                                    {/* SC/PWD Statutory Discount Eligibility */}
                                    <div className="mt-6">
                                        <label className="flex items-start justify-between p-4 bg-slate-50/50 rounded-2xl border border-slate-100 cursor-pointer group hover:bg-slate-50 transition-all">
                                            <div className="flex gap-3">
                                                <div className={`p-1.5 rounded-lg shrink-0 mt-0.5 ${data.is_discountable ? 'bg-indigo-100 text-indigo-600' : 'bg-slate-200 text-slate-400'}`}>
                                                    <Tag size={14} />
                                                </div>
                                                <div>
                                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-600 block">Eligible for SC/PWD statutory discount</span>
                                                    <span className="text-[9px] font-semibold text-slate-400 uppercase tracking-tighter block mt-1 leading-normal max-w-md">
                                                        When enabled, this item may be included in Senior Citizen/PWD statutory discount computation, subject to applicable Philippine rules and transaction context.
                                                    </span>
                                                </div>
                                            </div>
                                            <input
                                                type="checkbox"
                                                className="w-5 h-5 rounded-lg border-slate-200 text-indigo-600 focus:ring-indigo-500/20 cursor-pointer"
                                                checked={data.is_discountable}
                                                onChange={(e) => setData('is_discountable', e.target.checked)}
                                            />
                                        </label>
                                    </div>

                                    {/* Advisory Compliance & Margin Preview Card */}
                                    {(() => {
                                        const sellingPrice = parseFloat(data.selling_price) || 0;
                                        const costPrice = parseFloat(data.cost_price) || 0;
                                        
                                        const selectedTaxCategory = taxCategories.find(tc => String(tc.id) === String(data.tax_category_id));
                                        const isTaxable = !!data.is_taxable;
                                        
                                        let taxRate = 0;
                                        let taxType = 'exempt';
                                        let taxName = 'Exempt / Non-VAT';
                                        
                                        if (isTaxable && selectedTaxCategory) {
                                            taxRate = parseFloat(selectedTaxCategory.rate) || 0;
                                            taxType = selectedTaxCategory.tax_type;
                                            taxName = selectedTaxCategory.name;
                                        }
                                        
                                        let netOfVat = sellingPrice;
                                        let vatAmount = 0;
                                        
                                        if (taxType === 'vatable') {
                                            netOfVat = sellingPrice / (1.00 + (taxRate / 100.0));
                                            vatAmount = sellingPrice - netOfVat;
                                        }
                                        
                                        const grossMargin = sellingPrice - costPrice;
                                        const netOfVatMargin = netOfVat - costPrice;
                                        const marginPercentage = netOfVat > 0 ? (netOfVatMargin / netOfVat) * 100 : 0;

                                        if (sellingPrice === 0) {
                                            return (
                                                <div className="mt-8 p-6 bg-slate-50 border border-slate-100 rounded-3xl flex gap-3 items-start">
                                                    <Info size={16} className="text-indigo-600 shrink-0 mt-0.5" />
                                                    <p className="text-[11px] text-indigo-900 font-semibold leading-normal">
                                                        Enter a global selling price and base cost above to dynamically preview VAT deductions, gross margins, and statutory compliance values.
                                                    </p>
                                                </div>
                                            );
                                        }

                                        return (
                                            <div className="mt-8 p-6 bg-slate-50 border border-slate-100 rounded-3xl space-y-4">
                                                <div className="flex items-center justify-between pb-3 border-b border-slate-200/60">
                                                    <div className="flex items-center gap-2">
                                                        <div className="p-1.5 bg-indigo-100/80 text-indigo-700 rounded-lg">
                                                            <Info size={14} className="stroke-[2.5]" />
                                                        </div>
                                                        <span className="text-xs font-black uppercase tracking-widest text-slate-700">Compliance & Margin Preview</span>
                                                    </div>
                                                    <span className={`px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest ${
                                                        taxType === 'vatable' ? 'bg-indigo-100 text-indigo-700' :
                                                        taxType === 'exempt' ? 'bg-emerald-100 text-emerald-700' :
                                                        taxType === 'zero-rated' ? 'bg-amber-100 text-amber-700' :
                                                        'bg-slate-200 text-slate-600'
                                                    }`}>
                                                        {taxType === 'vatable' ? `${taxRate}% VAT Inclusive` : taxName}
                                                    </span>
                                                </div>

                                                <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                                                    <div className="p-3.5 bg-white border border-slate-100 rounded-2xl">
                                                        <p className="text-[9px] font-black text-slate-400 uppercase tracking-widest">Gross Price</p>
                                                        <p className="text-sm font-black text-slate-800 mt-1">₱{sellingPrice.toFixed(2)}</p>
                                                    </div>
                                                    <div className="p-3.5 bg-white border border-slate-100 rounded-2xl">
                                                        <p className="text-[9px] font-black text-slate-400 uppercase tracking-widest">Net of VAT Price</p>
                                                        <p className="text-sm font-black text-slate-800 mt-1">₱{netOfVat.toFixed(2)}</p>
                                                    </div>
                                                    <div className="p-3.5 bg-white border border-slate-100 rounded-2xl">
                                                        <p className="text-[9px] font-black text-slate-400 uppercase tracking-widest">VAT Amount</p>
                                                        <p className="text-sm font-black text-indigo-600 mt-1">₱{vatAmount.toFixed(2)}</p>
                                                    </div>
                                                    <div className="p-3.5 bg-white border border-slate-100 rounded-2xl">
                                                        <p className="text-[9px] font-black text-slate-500 uppercase tracking-widest">Base Cost</p>
                                                        <p className="text-sm font-bold text-slate-600 mt-1">₱{costPrice.toFixed(2)}</p>
                                                    </div>
                                                    <div className="p-3.5 bg-white border border-slate-100 rounded-2xl">
                                                        <p className="text-[9px] font-black text-slate-400 uppercase tracking-widest">Net-of-VAT Margin</p>
                                                        <p className={`text-sm font-black mt-1 ${netOfVatMargin >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                                                            ₱{netOfVatMargin.toFixed(2)}
                                                        </p>
                                                    </div>
                                                    <div className="p-3.5 bg-white border border-slate-100 rounded-2xl">
                                                        <p className="text-[9px] font-black text-slate-400 uppercase tracking-widest">Margin %</p>
                                                        <p className={`text-sm font-black mt-1 ${marginPercentage >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                                                            {marginPercentage.toFixed(2)}%
                                                        </p>
                                                    </div>
                                                </div>

                                                <div className="p-3.5 bg-indigo-50/50 border border-indigo-100/50 rounded-2xl flex gap-2">
                                                    <Info size={14} className="text-indigo-600 shrink-0 mt-0.5" />
                                                    <p className="text-[10px] text-indigo-900 font-semibold leading-relaxed">
                                                        <span className="font-bold">Advisory margin preview:</span> Selling Price is treated as VAT-inclusive for VATable items under the current 12% rules. For guidance only. Official tax computation is performed during checkout and sale posting.
                                                    </p>
                                                </div>
                                            </div>
                                        );
                                    })()}
                                </section>
                            </div>

                            {/* Right Column: Categorization & Settings */}
                            <div className="space-y-8">
                                <section className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                                    <div className="flex items-center gap-3 mb-8">
                                        <div className="p-2.5 bg-amber-50 text-amber-600 rounded-xl">
                                            <Tag size={20} />
                                        </div>
                                        <h3 className="text-lg font-black text-slate-800 uppercase tracking-widest">Organization</h3>
                                    </div>

                                    <div className="space-y-6">
                                        <div>
                                            <InputLabel htmlFor="product_type" value="Product Type *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <select
                                                id="product_type"
                                                className={`block w-full ${errors.product_type ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-black uppercase`}
                                                value={data.product_type}
                                                onChange={(e) => {
                                                    const val = e.target.value;
                                                    setData(d => ({
                                                        ...d,
                                                        product_type: val,
                                                        is_sellable: val === 'finished_good'
                                                    }));
                                                }}
                                                required
                                            >
                                                {productTypes.map(type => (
                                                    <option key={type.value} value={type.value}>{type.label}</option>
                                                ))}
                                            </select>
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">
                                                Controls whether this item is sold, stocked, or consumed as an ingredient.
                                            </p>
                                            <InputError message={errors.product_type} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="product_category_id" value="Primary Category *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <select
                                                id="product_category_id"
                                                className={`block w-full ${errors.product_category_id ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-black uppercase`}
                                                value={data.product_category_id}
                                                onChange={(e) => setData('product_category_id', e.target.value)}
                                                required
                                            >
                                                <option value="">Select Category</option>
                                                {categories.map(category => (
                                                    <option key={category.id} value={category.id}>{category.name}</option>
                                                ))}
                                            </select>
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">
                                                Used for POS grouping and product reporting.
                                            </p>
                                            <InputError message={errors.product_category_id} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="unit_of_measure" value="Unit of Measure *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <select
                                                id="unit_of_measure"
                                                className={`block w-full ${errors.unit_of_measure ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-bold capitalize`}
                                                value={data.unit_of_measure}
                                                onChange={(e) => setData('unit_of_measure', e.target.value)}
                                                required
                                            >
                                                {uomOptions.map(uom => (
                                                    <option key={uom} value={uom}>{uom}</option>
                                                ))}
                                            </select>
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">Determines the stock unit for inventory tracking and the quantity basis for recipe deductions.</p>
                                            <InputError message={errors.unit_of_measure} className="mt-2" />
                                        </div>
                                    </div>
                                </section>

                                <section className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                                    <div className="flex items-center gap-3 mb-8">
                                        <div className="p-2.5 bg-slate-50 text-slate-600 rounded-xl">
                                            <Settings size={20} />
                                        </div>
                                        <h3 className="text-lg font-black text-slate-800 uppercase tracking-widest">Configuration</h3>
                                    </div>

                                    <div className="space-y-6">
                                        <label className="flex items-center justify-between p-4 bg-slate-50/50 rounded-2xl border border-slate-50 cursor-pointer group hover:bg-slate-50 transition-all">
                                            <div className="flex items-center gap-3">
                                                <div className={`p-1.5 rounded-lg ${data.is_sellable ? 'bg-indigo-100 text-indigo-600' : 'bg-slate-200 text-slate-400'}`}>
                                                    <Tag size={14} />
                                                </div>
                                                <div>
                                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-600 block">Sellable on POS</span>
                                                    <span className="text-[9px] font-medium text-slate-400 uppercase tracking-tighter">Show this item in the checkout menu</span>
                                                </div>
                                            </div>
                                            <input
                                                type="checkbox"
                                                className="w-5 h-5 rounded-lg border-slate-200 text-indigo-600 focus:ring-indigo-500/20 cursor-pointer"
                                                checked={data.is_sellable}
                                                onChange={(e) => setData('is_sellable', e.target.checked)}
                                            />
                                        </label>

                                        <label className="flex items-center justify-between p-4 bg-slate-50/50 rounded-2xl border border-slate-50 cursor-pointer group hover:bg-slate-50 transition-all">
                                            <div className="flex items-center gap-3">
                                                <div className={`p-1.5 rounded-lg ${data.is_inventory_tracked ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-200 text-slate-400'}`}>
                                                    <CheckCircle2 size={14} />
                                                </div>
                                                <div>
                                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-600 block">Inventory Tracked</span>
                                                    <span className="text-[9px] font-medium text-slate-400 uppercase tracking-tighter">Enable stock deduction on sale</span>
                                                </div>
                                            </div>
                                            <input
                                                type="checkbox"
                                                className="w-5 h-5 rounded-lg border-slate-200 text-indigo-600 focus:ring-indigo-500/20 cursor-pointer"
                                                checked={data.is_inventory_tracked}
                                                onChange={(e) => setData('is_inventory_tracked', e.target.checked)}
                                            />
                                        </label>

                                        <div>
                                            <InputLabel value="Status *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <div className="grid grid-cols-2 gap-3 mt-1">
                                                <button
                                                    type="button"
                                                    onClick={() => setData('status', 'active')}
                                                    className={`py-3.5 rounded-2xl text-[10px] font-black uppercase tracking-widest border transition-all ${
                                                        data.status === 'active'
                                                            ? 'border-emerald-500 bg-emerald-50 text-emerald-700'
                                                            : 'border-slate-100 bg-slate-50 text-slate-400'
                                                    }`}
                                                >
                                                    Active
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setData('status', 'inactive')}
                                                    className={`py-3.5 rounded-2xl text-[10px] font-black uppercase tracking-widest border transition-all ${
                                                        data.status === 'inactive'
                                                            ? 'border-rose-500 bg-rose-50 text-rose-700'
                                                            : 'border-slate-100 bg-slate-50 text-slate-400'
                                                    }`}
                                                >
                                                    Inactive
                                                </button>
                                            </div>
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">
                                                Active products stay available to configured flows; inactive products are hidden from normal selection.
                                            </p>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </div>

                        {/* Sticky Footer Actions */}
                        <div className="fixed bottom-8 left-0 right-0 z-40 pointer-events-none">
                            <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                                <div className="bg-slate-900/90 backdrop-blur-md p-4 rounded-3xl border border-white/10 shadow-2xl flex items-center justify-between pointer-events-auto">
                                    <div className="flex items-center gap-3 px-4">
                                        {Object.keys(errors).length > 0 ? (
                                            <>
                                                <div className="p-2 bg-rose-500/20 text-rose-400 rounded-xl">
                                                    <AlertCircle size={16} />
                                                </div>
                                                <div>
                                                    <p className="text-xs font-bold text-rose-300">Save failed — review form errors.</p>
                                                    <p className="text-[10px] text-rose-400 font-semibold uppercase mt-0.5 tracking-wider">
                                                        {Object.keys(errors).length} field{Object.keys(errors).length > 1 ? 's' : ''} require attention
                                                    </p>
                                                </div>
                                            </>
                                        ) : (
                                            <>
                                                <div className="p-2 bg-white/10 text-white rounded-xl">
                                                    <Info size={16} />
                                                </div>
                                                <div>
                                                    <p className="text-xs font-bold text-slate-300">Create saves the product and returns you to the product list.</p>
                                                    <p className="text-[10px] text-slate-400 font-semibold uppercase mt-0.5 tracking-wider">
                                                        New products are added globally to all branches by default.
                                                    </p>
                                                </div>
                                            </>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-4">
                                        <Link
                                            href={route('admin.products.index')}
                                            className="px-6 py-2.5 text-xs font-black uppercase tracking-widest text-slate-400 hover:text-white transition-colors"
                                        >
                                            Back to Products
                                        </Link>
                                        <PrimaryButton 
                                            disabled={processing}
                                            className="px-8 py-3 bg-indigo-600 hover:bg-indigo-500 text-white rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg shadow-indigo-600/20 transition-all active:scale-95 flex items-center gap-2"
                                        >
                                            <Save size={16} />
                                            {processing ? 'Creating product...' : 'Create Product'}
                                        </PrimaryButton>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        {/* Buffer for sticky footer */}
                        <div className="h-24" />
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
