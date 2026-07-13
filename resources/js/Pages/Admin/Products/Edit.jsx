import React, { useState, useRef, useCallback } from 'react';
import { Head, useForm, Link, router } from '@inertiajs/react';
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
    Store,
    Plus,
    X,
    MapPin,
    Trash2,
    Search,
    Layers,
    Coffee,
    AlertCircle,
    CheckCircle2,
    Calculator,
    TrendingUp,
    AlertTriangle
} from 'lucide-react';

export default function Edit({ auth, product, categories, taxCategories, branches, branchPrices, allProducts, uomOptions, productTypes }) {
    const [isPricingModalOpen, setIsPricingModalOpen] = useState(false);
    const searchInputRef = useRef(null);
    const [branchPricingFeedback, setBranchPricingFeedback] = useState(null);
    const [recipeFeedback, setRecipeFeedback] = useState(null);
    const [recipeCost, setRecipeCost] = useState(null);
    const [costBranchId, setCostBranchId] = useState('');
    const [costLoading, setCostLoading] = useState(false);
    const [costError, setCostError] = useState(null);
    
    // Product Metadata Form
    const { data, setData, put, processing, errors, isDirty, recentlySuccessful } = useForm({
        product_category_id: product.product_category_id,
        tax_category_id: product.tax_category_id || '',
        name: product.name,
        sku: product.sku,
        barcode: product.barcode || '',
        description: product.description || '',
        unit_of_measure: product.unit_of_measure,
        selling_price: product.selling_price,
        cost_price: product.cost_price || '',
        is_taxable: product.is_taxable,
        is_inventory_tracked: product.is_inventory_tracked,
        is_discountable: product.is_discountable,
        status: product.status,
        product_type: product.product_type || 'finished_good',
        is_sellable: product.is_sellable ?? true,
    });

    // Branch Pricing Form
    const branchPricingForm = useForm({
        branch_id: '',
        selling_price: '',
        is_active: true,
    });

    // Recipe Form
    const recipeForm = useForm({
        ingredients: product.recipes ? product.recipes.map(r => ({
            ingredient_id: r.ingredient_id,
            name: r.ingredient.name,
            sku: r.ingredient.sku,
            quantity: r.quantity,
            unit: r.unit
        })) : []
    });

    const [ingredientSearch, setIngredientSearch] = useState('');

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.products.update', product.id), { preserveScroll: true });
    };

    const submitBranchPricing = (e) => {
        e.preventDefault();
        branchPricingForm.post(route('admin.products.branch-pricing.update', product.id), {
            preserveScroll: true,
            onSuccess: () => {
                const selectedBranch = branches.find(b => String(b.id) === String(branchPricingForm.data.branch_id));
                setBranchPricingFeedback({
                    type: 'success',
                    message: selectedBranch
                        ? `Override saved for ${selectedBranch.name}.`
                        : 'Branch override saved successfully.'
                });
                setIsPricingModalOpen(false);
                branchPricingForm.reset();
            },
            onError: () => {
                setBranchPricingFeedback({
                    type: 'error',
                    message: 'Could not save branch override. Review the highlighted fields and try again.'
                });
            }
        });
    };

    const deleteBranchPricing = (id) => {
        if (confirm('Are you sure you want to delete this branch pricing override?')) {
            const removed = branchPrices.find(bp => bp.id === id);
            router.delete(route('admin.products.branch-pricing.destroy', [product.id, id]), {
                preserveScroll: true,
                onSuccess: () => {
                    setBranchPricingFeedback({
                        type: 'success',
                        message: removed
                            ? `Override removed for ${removed.branch.name}. Global price now applies.`
                            : 'Branch override removed. Global price now applies.'
                    });
                },
                onError: () => {
                    setBranchPricingFeedback({
                        type: 'error',
                        message: 'Could not remove branch override. Please retry.'
                    });
                }
            });
        }
    };

    const submitRecipe = (e) => {
        e.preventDefault();
        recipeForm.post(route('admin.products.recipe.update', product.id), {
            preserveScroll: true,
            onSuccess: () => {
                setRecipeFeedback({
                    type: 'success',
                    message: 'Recipe changes saved successfully.'
                });
            },
            onError: () => {
                setRecipeFeedback({
                    type: 'error',
                    message: 'Recipe save failed. Review the highlighted rows and try again.'
                });
            }
        });
    };

    const addIngredient = (ing) => {
        if (recipeForm.data.ingredients.some(i => i.ingredient_id === ing.id)) {
            setRecipeFeedback({
                type: 'info',
                message: `${ing.name} is already included in this recipe.`
            });
            return;
        }
        
        recipeForm.setData('ingredients', [
            ...recipeForm.data.ingredients,
            {
                ingredient_id: ing.id,
                name: ing.name,
                sku: ing.sku,
                quantity: 1,
                unit: ing.unit_of_measure
            }
        ]);
        setRecipeFeedback({
            type: 'info',
            message: `${ing.name} added to the recipe workspace. Set quantity and unit, then save.`
        });
        setIngredientSearch('');
    };

    const removeIngredient = (id) => {
        recipeForm.setData('ingredients', recipeForm.data.ingredients.filter(i => i.ingredient_id !== id));
        setRecipeFeedback({
            type: 'info',
            message: 'Ingredient row removed from the unsaved recipe workspace.'
        });
    };

    const updateIngredientQty = (id, qty) => {
        recipeForm.setData('ingredients', recipeForm.data.ingredients.map(i => 
            i.ingredient_id === id ? { ...i, quantity: qty } : i
        ));
    };

    const updateIngredientUnit = (id, unit) => {
        recipeForm.setData('ingredients', recipeForm.data.ingredients.map(i => 
            i.ingredient_id === id ? { ...i, unit: unit } : i
        ));
    };

    const filteredIngredients = allProducts.filter(p => 
        p.id !== product.id && 
        (p.name.toLowerCase().includes(ingredientSearch.toLowerCase()) || 
         p.sku.toLowerCase().includes(ingredientSearch.toLowerCase()))
    ).sort((a, b) => {
        const priority = { 'raw_material': 0, 'semi_finished': 1, 'finished_good': 2 };
        return priority[a.product_type] - priority[b.product_type];
    }).slice(0, 6);

    const getRecipeFieldError = (index, field) => recipeForm.errors[`ingredients.${index}.${field}`];
    const recipeErrorCount = Object.keys(recipeForm.errors).filter((key) => key.startsWith('ingredients.')).length;

    const fetchRecipeCost = useCallback(async () => {
        setCostLoading(true);
        setCostError(null);
        setRecipeCost(null);
        try {
            const url = new URL(route('admin.products.recipe.cost', product.id), window.location.origin);
            if (costBranchId) url.searchParams.set('branch_id', costBranchId);
            const res = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });
            if (!res.ok) throw new Error(`Server responded with ${res.status}`);
            const json = await res.json();
            setRecipeCost(json);
        } catch (e) {
            setCostError('Failed to calculate recipe cost. Please try again.');
        } finally {
            setCostLoading(false);
        }
    }, [product.id, costBranchId]);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link
                            href={route('admin.products.index')}
                            className="inline-flex items-center gap-2 px-3 py-2.5 bg-white border border-slate-100 rounded-xl text-slate-500 hover:text-indigo-600 hover:shadow-md transition-all text-xs font-black uppercase tracking-widest h-11"
                        >
                            <ArrowLeft size={20} />
                            Back to Products
                        </Link>
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Edit Product</h2>
                                <span className="px-2 py-0.5 bg-slate-100 text-slate-500 text-[10px] font-black uppercase rounded-lg">v2.1</span>
                                {isDirty && (
                                    <span className="px-2 py-0.5 bg-amber-100 text-amber-800 text-[10px] font-black uppercase rounded-lg animate-pulse">
                                        Unsaved Changes
                                    </span>
                                )}
                            </div>
                            <p className="text-sm text-slate-500 font-medium mt-1">Refining master record for <span className="text-indigo-600 font-bold">{product.name}</span></p>
                        </div>
                    </div>
                </div>
            }
        >
            <Head title={`Edit ${product.name}`} />

            <div className="py-8 pb-40">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <form onSubmit={submit}>
                        {Object.keys(errors).length > 0 ? (
                            <div className="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 flex items-center gap-3">
                                <AlertCircle size={16} className="text-rose-600 shrink-0" />
                                <p className="text-[10px] font-black uppercase tracking-widest text-rose-700">
                                    {Object.keys(errors).length} field{Object.keys(errors).length > 1 ? 's' : ''} require attention — review highlighted fields below.
                                </p>
                            </div>
                        ) : (
                            <div className="mb-6 rounded-2xl border border-indigo-100 bg-indigo-50/50 px-4 py-3">
                                <p className="text-[10px] font-black uppercase tracking-widest text-indigo-700">Required fields are marked with *</p>
                            </div>
                        )}

                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 grid-flow-row-dense">
                            
                            {/* Card 1: Product Details */}
                            <section className="order-1 lg:col-span-7 bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm relative overflow-hidden">
                                <div className="flex items-center gap-3 mb-8">
                                    <div className="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl">
                                        <Package size={20} />
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-black text-slate-800 uppercase tracking-widest">Product Details</h3>
                                        <p className="text-[10px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5">Core Product Identification & Details</p>
                                    </div>
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
                                            placeholder="e.g. Classic Cappuccino"
                                        />
                                        <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">Displayed across POS, receipts, and reports. Use a clear, recognizable name.</p>
                                        <InputError message={errors.name} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="sku" value="SKU *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                        <TextInput
                                            id="sku"
                                            className={`block w-full ${errors.sku ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-black uppercase`}
                                            value={data.sku}
                                            onChange={(e) => setData('sku', e.target.value.toUpperCase())}
                                            required
                                            placeholder="e.g. CAP-001"
                                        />
                                        <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">Unique item code per store. Must be uppercase. Changing SKU may affect existing reports.</p>
                                        <InputError message={errors.sku} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="barcode" value="Barcode" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
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
                                        <InputLabel htmlFor="unit_of_measure" value="Unit of Measure *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                        <select
                                            id="unit_of_measure"
                                            className={`block w-full ${errors.unit_of_measure ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-bold h-11`}
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

                                    <div className="md:col-span-2">
                                        <InputLabel htmlFor="description" value="Description" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                        <textarea
                                            id="description"
                                            className="block w-full border-slate-100 bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-medium h-24"
                                            value={data.description}
                                            onChange={(e) => setData('description', e.target.value)}
                                            placeholder="Product details, ingredients, or service scope..."
                                        />
                                        <InputError message={errors.description} className="mt-2" />
                                    </div>
                                </div>
                            </section>

                            {/* Card 2: Classification & Status */}
                            <section className="order-2 lg:col-span-5 bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                                <div className="flex items-center gap-3 mb-8">
                                    <div className="p-2.5 bg-slate-50 text-slate-600 rounded-xl">
                                        <Settings size={20} />
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-black text-slate-800 uppercase tracking-widest">Classification & Status</h3>
                                        <p className="text-[10px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5">Product Type, Routing & POS Visibility</p>
                                    </div>
                                </div>

                                <div className="space-y-6">
                                    <div>
                                        <InputLabel htmlFor="product_type" value="Product Type *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                        <select
                                            id="product_type"
                                            className={`block w-full ${errors.product_type ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-black uppercase h-11`}
                                            value={data.product_type}
                                            onChange={(e) => {
                                                const val = e.target.value;
                                                setData(d => ({
                                                    ...d,
                                                    product_type: val,
                                                    is_sellable: val === 'finished_good'
                                                }));
                                            }}
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
                                        <InputLabel htmlFor="product_category_id" value="Category *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                        <select
                                            id="product_category_id"
                                            className={`block w-full ${errors.product_category_id ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-black uppercase h-11`}
                                            value={data.product_category_id}
                                            onChange={(e) => setData('product_category_id', e.target.value)}
                                            required
                                        >
                                            <option value="" disabled>Select Category</option>
                                            {categories.map(cat => (
                                                <option key={cat.id} value={cat.id}>{cat.name}</option>
                                            ))}
                                        </select>
                                        <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">
                                            Used for POS grouping and product reporting.
                                        </p>
                                        <InputError message={errors.product_category_id} className="mt-2" />
                                    </div>

                                    <div className="space-y-4">
                                        <label className="flex items-center justify-between p-4 bg-slate-50/50 rounded-2xl border border-slate-50 cursor-pointer group hover:bg-slate-50 transition-all h-11">
                                            <div className="flex items-center gap-3">
                                                <div className={`p-1.5 rounded-lg ${data.is_sellable ? 'bg-indigo-100 text-indigo-600' : 'bg-slate-200 text-slate-400'}`}>
                                                    <Tag size={14} />
                                                </div>
                                                <div>
                                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-600 block">Sellable on POS</span>
                                                </div>
                                            </div>
                                            <input
                                                type="checkbox"
                                                className="w-5 h-5 rounded-lg border-slate-200 text-indigo-600 focus:ring-indigo-500/20 cursor-pointer"
                                                checked={data.is_sellable}
                                                onChange={(e) => setData('is_sellable', e.target.checked)}
                                            />
                                        </label>

                                        <div>
                                            <InputLabel htmlFor="status" value="Market Status *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                            <div className="grid grid-cols-2 gap-3">
                                                <button
                                                    type="button"
                                                    onClick={() => setData('status', 'active')}
                                                    className={`h-11 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all ${
                                                        data.status === 'active' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-100 bg-slate-50 text-slate-400'
                                                    }`}
                                                >
                                                    Active
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setData('status', 'inactive')}
                                                    className={`h-11 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all ${
                                                        data.status === 'inactive' ? 'border-rose-500 bg-rose-50 text-rose-700' : 'border-slate-100 bg-slate-50 text-slate-400'
                                                    }`}
                                                >
                                                    Inactive
                                                </button>
                                            </div>
                                            <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">
                                                Active products remain available to configured flows; archived products are hidden from normal selection.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            {/* Card 3: Pricing & Tax */}
                            <section className="order-3 lg:col-span-7 bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                                <div className="flex items-center gap-3 mb-8">
                                    <div className="p-2.5 bg-emerald-50 text-emerald-600 rounded-xl">
                                        <DollarSign size={20} />
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-black text-slate-800 uppercase tracking-widest">Pricing & Tax</h3>
                                        <p className="text-[10px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5">Global Price Points, Costing & VAT compliance</p>
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <InputLabel htmlFor="selling_price" value="Global Selling Price *" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                        <TextInput
                                            id="selling_price"
                                            type="number"
                                            step="0.01"
                                            className={`block w-full ${errors.selling_price ? 'border-rose-400' : 'border-slate-100'} bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-black`}
                                            value={data.selling_price}
                                            onChange={(e) => setData('selling_price', e.target.value)}
                                            required
                                        />
                                        <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">Final customer-facing price. For VAT items, this is treated as VAT-inclusive at checkout.</p>
                                        <InputError message={errors.selling_price} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="cost_price" value="Base Cost" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                        <TextInput
                                            id="cost_price"
                                            type="number"
                                            step="0.01"
                                            className="block w-full border-slate-100 bg-slate-50/50 rounded-2xl py-3.5 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-medium"
                                            value={data.cost_price}
                                            onChange={(e) => setData('cost_price', e.target.value)}
                                        />
                                        <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">Optional. Used for margin preview and cost reporting only; does not affect checkout pricing.</p>
                                        <InputError message={errors.cost_price} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="tax_category_id" value="Tax Category" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                        <select
                                            id="tax_category_id"
                                            className={`block w-full border-slate-100 bg-slate-50/50 rounded-2xl py-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-none transition-all text-sm font-bold h-11 ${!data.is_taxable ? 'opacity-50 cursor-not-allowed bg-slate-100' : ''}`}
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
                                            Select a tax category only for taxable items; keep empty for exempt/non-vat items.
                                        </p>
                                    </div>

                                    <div className="flex flex-col justify-end pb-3">
                                        <label className="flex items-center gap-3 cursor-pointer group h-11">
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
                                            <span className="text-xs font-black uppercase tracking-widest text-slate-500 group-hover:text-indigo-600 transition-colors">Include in compliance computation</span>
                                        </label>
                                        <p className="text-[10px] text-slate-400 font-semibold uppercase mt-1 leading-normal pl-8">
                                            Used for VAT, exemption, statutory discount, and reporting calculations.
                                        </p>
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
                                                    Enter global selling price and base cost to preview VAT, margin, and statutory compliance values.
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
                                                    {data.tax_category_id === '' ? (
                                                        <span><span className="font-bold">Exempt / Non-VAT compliance:</span> Although this item is tax-exempt, sales are still recorded for compliance reporting and statutory audit trails.</span>
                                                    ) : (
                                                        <span><span className="font-bold">Advisory margin preview:</span> Selling Price is treated as VAT-inclusive for VATable items under the current 12% rules. For guidance only. Official tax computation is performed during checkout and sale posting.</span>
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    );
                                })()}
                            </section>

                            {/* Card 4: Recipe / BOM */}
                            <section className="order-4 lg:col-span-7 bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm relative">
                                <div className="absolute top-4 right-6 px-2 py-1 rounded-lg bg-orange-50 text-orange-600 text-[9px] font-black uppercase tracking-widest">
                                    Recipe Section
                                </div>
                                <div className="flex items-center justify-between mb-8">
                                    <div className="flex items-center gap-3">
                                        <div className="p-2.5 bg-orange-50 text-orange-600 rounded-xl">
                                            <Coffee size={20} />
                                        </div>
                                        <div>
                                            <h3 className="text-lg font-black text-slate-800 uppercase tracking-widest">Recipe / Ingredients Entry Point</h3>
                                            <p className="text-[10px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5">Manage ingredient rows for this product only. Computation rules remain unchanged.</p>
                                        </div>
                                    </div>
                                    
                                    <div className="flex flex-col items-end gap-1">
                                        <button 
                                            type="button"
                                            onClick={submitRecipe}
                                            disabled={recipeForm.processing}
                                            className={`px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg h-11 ${
                                                recipeForm.processing
                                                    ? 'bg-orange-300 text-white shadow-none cursor-not-allowed'
                                                    : 'bg-orange-600 text-white hover:bg-orange-500 shadow-orange-600/20'
                                            }`}
                                        >
                                            {recipeForm.processing ? 'Saving recipe...' : 'Save Recipe Changes'}
                                        </button>
                                        <span className="text-[8px] text-slate-400 font-black uppercase tracking-widest mt-1 block">
                                            {recipeForm.processing
                                                ? 'Saving uses the existing recipe update endpoint with no pricing or inventory rule changes.'
                                                : 'Updates only recipe rows. Global pricing, tax, and inventory engines are not changed here.'}
                                        </span>
                                    </div>
                                </div>

                                {(recipeFeedback || recipeErrorCount > 0) && (
                                    <div className={`mb-6 rounded-2xl border px-4 py-3 ${
                                        recipeErrorCount > 0 || recipeFeedback?.type === 'error'
                                            ? 'border-rose-200 bg-rose-50'
                                            : recipeFeedback?.type === 'success'
                                                ? 'border-emerald-200 bg-emerald-50'
                                                : 'border-orange-200 bg-orange-50'
                                    }`}>
                                        <p className={`text-[10px] font-black uppercase tracking-widest ${
                                            recipeErrorCount > 0 || recipeFeedback?.type === 'error'
                                                ? 'text-rose-700'
                                                : recipeFeedback?.type === 'success'
                                                    ? 'text-emerald-700'
                                                    : 'text-orange-700'
                                        }`}>
                                            {recipeErrorCount > 0
                                                ? `${recipeErrorCount} recipe field${recipeErrorCount > 1 ? 's' : ''} require attention.`
                                                : recipeFeedback?.message}
                                        </p>
                                        {recipeErrorCount > 0 && recipeFeedback?.type === 'error' && (
                                            <p className="mt-1 text-[11px] text-rose-600 font-semibold leading-relaxed">
                                                {recipeFeedback.message}
                                            </p>
                                        )}
                                    </div>
                                )}

                                <div className="mb-6 rounded-3xl border border-orange-100 bg-orange-50/40 p-5">
                                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div>
                                            <p className="text-[10px] font-black uppercase tracking-widest text-orange-700">Recipe Workspace Guide</p>
                                            <p className="mt-2 text-sm font-bold text-slate-700">
                                                Use this section to define what ingredient rows are consumed each time this product is sold.
                                            </p>
                                            <p className="mt-1 text-[11px] leading-relaxed text-slate-500">
                                                Quantities and units here describe recipe usage only. Recipe computation, stock deduction rules, and costing behavior remain unchanged.
                                            </p>
                                        </div>
                                        <div className="rounded-2xl border border-orange-200 bg-white px-4 py-3 min-w-[180px]">
                                            <p className="text-[9px] font-black uppercase tracking-widest text-orange-600">Current Recipe Rows</p>
                                            <p className="mt-1 text-2xl font-black text-slate-800">{recipeForm.data.ingredients.length}</p>
                                            <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400 mt-1">Ingredient line items</p>
                                        </div>
                                    </div>
                                </div>

                                {/* WAC Recipe Cost Estimator (Story 35.4) */}
                                <div className="mb-6 rounded-3xl border border-indigo-100 bg-indigo-50/30 p-5">
                                    <div className="flex items-center gap-2 mb-4">
                                        <div className="p-1.5 bg-indigo-100 text-indigo-600 rounded-lg">
                                            <Calculator size={15} />
                                        </div>
                                        <p className="text-[10px] font-black uppercase tracking-widest text-indigo-700">WAC Recipe Cost Estimator</p>
                                    </div>
                                    <p className="text-[11px] text-slate-500 leading-relaxed mb-4">
                                        Estimates the total ingredient cost per unit sold using Weighted Average Cost (WAC) from branch inventory. Select a branch for branch-specific WAC; leave blank for catalog cost fallback.
                                    </p>
                                    <div className="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
                                        <select
                                            id="cost-branch-select"
                                            className="flex-1 h-11 px-3 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:ring-indigo-500/20 focus:border-indigo-500"
                                            value={costBranchId}
                                            onChange={(e) => { setCostBranchId(e.target.value); setRecipeCost(null); }}
                                        >
                                            <option value="">No branch — use catalog cost</option>
                                            {branches.map(b => (
                                                <option key={b.id} value={b.id}>{b.name}</option>
                                            ))}
                                        </select>
                                        <button
                                            type="button"
                                            id="btn-calculate-recipe-cost"
                                            onClick={fetchRecipeCost}
                                            disabled={costLoading || recipeForm.data.ingredients.length === 0}
                                            className={`px-4 h-11 flex items-center gap-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all whitespace-nowrap ${
                                                costLoading || recipeForm.data.ingredients.length === 0
                                                    ? 'bg-slate-200 text-slate-400 cursor-not-allowed'
                                                    : 'bg-indigo-600 text-white hover:bg-indigo-500 shadow-lg shadow-indigo-600/20'
                                            }`}
                                        >
                                            <TrendingUp size={14} />
                                            {costLoading ? 'Calculating...' : 'Calculate WAC Cost'}
                                        </button>
                                    </div>

                                    {costError && (
                                        <div className="mt-4 flex items-center gap-2 text-rose-600">
                                            <AlertTriangle size={14} />
                                            <p className="text-[10px] font-black uppercase tracking-widest">{costError}</p>
                                        </div>
                                    )}

                                    {recipeCost && (
                                        <div className="mt-4 space-y-4 animate-in fade-in slide-in-from-bottom-2 duration-300">
                                            {/* Summary row */}
                                            <div className="flex flex-wrap gap-4">
                                                <div className="flex-1 min-w-[160px] bg-white border border-indigo-200 rounded-2xl p-4">
                                                    <p className="text-[9px] font-black uppercase tracking-widest text-indigo-600">Estimated Total Cost</p>
                                                    {recipeCost.total_cost !== null ? (
                                                        <p className="text-xl font-black text-slate-800 mt-1">₱{parseFloat(recipeCost.total_cost).toFixed(4)}</p>
                                                    ) : (
                                                        <p className="text-sm font-black text-amber-600 mt-1">Incomplete</p>
                                                    )}
                                                    <p className="text-[9px] font-semibold text-slate-400 uppercase tracking-wider mt-1">
                                                        {recipeCost.branch_id ? 'Using branch WAC' : 'Using catalog cost'}
                                                    </p>
                                                </div>
                                                {recipeCost.total_cost !== null && parseFloat(data.selling_price) > 0 && (
                                                    <div className="flex-1 min-w-[160px] bg-white border border-emerald-200 rounded-2xl p-4">
                                                        <p className="text-[9px] font-black uppercase tracking-widest text-emerald-600">Recipe Gross Margin</p>
                                                        <p className={`text-xl font-black mt-1 ${
                                                            (parseFloat(data.selling_price) - parseFloat(recipeCost.total_cost)) >= 0 ? 'text-emerald-700' : 'text-rose-600'
                                                        }`}>
                                                            ₱{(parseFloat(data.selling_price) - parseFloat(recipeCost.total_cost)).toFixed(4)}
                                                        </p>
                                                        <p className="text-[9px] font-semibold text-slate-400 uppercase tracking-wider mt-1">
                                                            Selling ₱{parseFloat(data.selling_price).toFixed(2)} − Recipe cost
                                                        </p>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Warnings */}
                                            {(recipeCost.has_missing_costs || recipeCost.has_missing_conversions) && (
                                                <div className="flex items-start gap-2 p-3 rounded-xl bg-amber-50 border border-amber-200">
                                                    <AlertTriangle size={14} className="text-amber-600 shrink-0 mt-0.5" />
                                                    <p className="text-[10px] font-semibold text-amber-800 leading-relaxed">
                                                        {recipeCost.has_missing_conversions && 'One or more ingredients could not be unit-converted (missing conversion rule). '}
                                                        {recipeCost.has_missing_costs && 'One or more ingredients have no WAC or catalog cost available. '}
                                                        Total cost is marked incomplete. Update missing WAC via stock receiving or set catalog cost per ingredient.
                                                    </p>
                                                </div>
                                            )}

                                            {/* Per-ingredient breakdown */}
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-xs">
                                                    <thead>
                                                        <tr className="border-b border-slate-100">
                                                            <th className="text-left py-2 text-[9px] font-black uppercase tracking-widest text-slate-400 pb-3">Ingredient</th>
                                                            <th className="text-right py-2 text-[9px] font-black uppercase tracking-widest text-slate-400 pb-3">Qty (Recipe)</th>
                                                            <th className="text-right py-2 text-[9px] font-black uppercase tracking-widest text-slate-400 pb-3">Converted Qty</th>
                                                            <th className="text-right py-2 text-[9px] font-black uppercase tracking-widest text-slate-400 pb-3">Unit Cost</th>
                                                            <th className="text-right py-2 text-[9px] font-black uppercase tracking-widest text-slate-400 pb-3">Line Cost</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-50">
                                                        {recipeCost.ingredients.map(ing => (
                                                            <tr key={ing.ingredient_id} className={`${
                                                                ing.conversion_missing || ing.line_cost === null ? 'bg-amber-50/50' : ''
                                                            }`}>
                                                                <td className="py-2.5 pr-4">
                                                                    <p className="font-bold text-slate-800">{ing.name}</p>
                                                                    <p className="text-[9px] font-black text-slate-400 uppercase">{ing.sku}</p>
                                                                    <span className={`inline-block mt-1 px-1.5 py-0.5 rounded text-[8px] font-black uppercase tracking-widest ${
                                                                        ing.cost_source === 'branch_wac' ? 'bg-indigo-100 text-indigo-700' :
                                                                        ing.cost_source === 'catalog_cost' ? 'bg-slate-100 text-slate-600' :
                                                                        'bg-amber-100 text-amber-700'
                                                                    }`}>
                                                                        {ing.cost_source === 'branch_wac' ? 'Branch WAC' :
                                                                         ing.cost_source === 'catalog_cost' ? 'Catalog Cost' : 'No Cost'}
                                                                    </span>
                                                                </td>
                                                                <td className="py-2.5 text-right font-medium text-slate-600">
                                                                    {ing.recipe_quantity} {ing.recipe_unit}
                                                                </td>
                                                                <td className="py-2.5 text-right">
                                                                    {ing.conversion_missing ? (
                                                                        <span className="text-amber-600 font-black text-[10px]">No Rule</span>
                                                                    ) : (
                                                                        <span className="font-medium text-slate-600">{ing.converted_quantity} {ing.ingredient_uom}</span>
                                                                    )}
                                                                </td>
                                                                <td className="py-2.5 text-right font-medium text-slate-600">
                                                                    {ing.unit_cost !== null ? `₱${parseFloat(ing.unit_cost).toFixed(4)}` : <span className="text-amber-600 font-black text-[10px]">—</span>}
                                                                </td>
                                                                <td className="py-2.5 text-right font-black">
                                                                    {ing.line_cost !== null ? (
                                                                        <span className="text-slate-800">₱{parseFloat(ing.line_cost).toFixed(4)}</span>
                                                                    ) : (
                                                                        <span className="text-amber-600 text-[10px]">—</span>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                    <tfoot>
                                                        <tr className="border-t-2 border-indigo-200">
                                                            <td colSpan={4} className="pt-3 text-[10px] font-black uppercase tracking-widest text-indigo-700">Estimated Recipe Total</td>
                                                            <td className="pt-3 text-right font-black text-slate-800">
                                                                {recipeCost.total_cost !== null
                                                                    ? `₱${parseFloat(recipeCost.total_cost).toFixed(4)}`
                                                                    : <span className="text-amber-600">Incomplete</span>}
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                <div className="space-y-6">
                                    {/* Ingredient Search */}
                                    <div className="rounded-3xl border border-slate-100 bg-slate-50/60 p-5">
                                        <div className="flex items-center gap-2 mb-2">
                                            <Search size={16} className="text-orange-500" />
                                            <p className="text-[10px] font-black uppercase tracking-widest text-slate-500">Ingredient Search And Selection</p>
                                        </div>
                                        <p className="text-[11px] text-slate-500 leading-relaxed mb-4">
                                            Search by ingredient name or SKU, then select a result to add one row. Raw materials appear first where available.
                                        </p>
                                        {ingredientSearch.length > 0 && (
                                            <p className="text-[10px] text-orange-700 font-black uppercase tracking-wide mb-3">
                                                {filteredIngredients.length > 0
                                                    ? `${filteredIngredients.length} matching ingredient${filteredIngredients.length > 1 ? 's' : ''} ready to add`
                                                    : `No ingredient matches found for "${ingredientSearch}"`}
                                            </p>
                                        )}
                                        <div className="relative">
                                            <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                                <Search size={16} />
                                            </div>
                                            <input
                                                ref={searchInputRef}
                                                type="text"
                                                placeholder="Search ingredient by name or SKU, then add to recipe"
                                                className="block w-full pl-11 pr-4 py-3 bg-white border border-slate-100 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-orange-500/20 transition-all h-11"
                                                value={ingredientSearch}
                                                onChange={(e) => setIngredientSearch(e.target.value)}
                                            />
                                            
                                            {ingredientSearch.length > 0 && (
                                                <div className="absolute z-10 w-full mt-2 bg-white border border-slate-100 rounded-2xl shadow-xl overflow-hidden">
                                                    {filteredIngredients.length > 0 ? (
                                                        filteredIngredients.map(ing => (
                                                            <button
                                                                key={ing.id}
                                                                onClick={() => addIngredient(ing)}
                                                                className="w-full flex items-center justify-between p-4 hover:bg-slate-50 text-left transition-colors"
                                                                type="button"
                                                            >
                                                                <div>
                                                                    <div className="flex items-center gap-2">
                                                                        <p className="text-sm font-bold text-slate-800">{ing.name}</p>
                                                                        <span className={`px-1.5 py-0.5 rounded-md text-[8px] font-black uppercase tracking-widest ${
                                                                            ing.product_type === 'raw_material' ? 'bg-amber-100 text-amber-600' : 
                                                                            ing.product_type === 'semi_finished' ? 'bg-indigo-100 text-indigo-600' :
                                                                            'bg-slate-100 text-slate-400'
                                                                        }`}>
                                                                            {ing.product_type?.replace('_', ' ')}
                                                                        </span>
                                                                    </div>
                                                                    <p className="text-[10px] font-black text-slate-400 uppercase">{ing.sku}</p>
                                                                </div>
                                                                <Plus size={16} className="text-slate-300" />
                                                            </button>
                                                        ))
                                                    ) : (
                                                        <div className="p-4 text-center">
                                                            <p className="text-xs text-slate-500 font-bold italic">No matching ingredients found.</p>
                                                            <p className="text-[10px] text-slate-400 font-semibold uppercase tracking-wide mt-2">Try a product name, SKU, or shorter search term.</p>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                        <p className="text-[10px] text-slate-400 font-semibold uppercase tracking-wide mt-3">
                                            Add only ingredients already present in the product catalog. Duplicate ingredient rows are blocked automatically.
                                        </p>
                                    </div>

                                    {/* Ingredient List */}
                                    <div className="space-y-3">
                                        {recipeForm.data.ingredients.length > 0 && (
                                            <div className="hidden md:grid md:grid-cols-[minmax(0,1.5fr)_minmax(0,0.8fr)_minmax(0,0.7fr)_56px] gap-4 px-4 text-[9px] font-black uppercase tracking-widest text-slate-400">
                                                <span>Ingredient</span>
                                                <span>Quantity Per Sale</span>
                                                <span>Unit</span>
                                                <span className="text-center">Remove</span>
                                            </div>
                                        )}
                                        {recipeForm.data.ingredients.length > 0 ? (
                                            recipeForm.data.ingredients.map((item, idx) => (
                                                <div key={item.ingredient_id} className="grid grid-cols-1 md:grid-cols-[minmax(0,1.5fr)_minmax(0,0.8fr)_minmax(0,0.7fr)_56px] gap-4 p-4 bg-slate-50 rounded-2xl border border-slate-100 group animate-in slide-in-from-left-2 duration-200 items-center">
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2">
                                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-white border border-slate-200 text-[10px] font-black text-slate-500 shrink-0">
                                                                {idx + 1}
                                                            </span>
                                                            <div className="min-w-0">
                                                                <p className="text-sm font-bold text-slate-800 truncate">{item.name}</p>
                                                                <p className="text-[10px] font-black text-slate-400 uppercase">{item.sku}</p>
                                                            </div>
                                                        </div>
                                                        <p className="text-[10px] text-slate-400 font-semibold uppercase tracking-wide mt-2 md:hidden">
                                                            Quantity and unit below apply per sale of this product.
                                                        </p>
                                                    </div>
                                                    
                                                    <div>
                                                        <label className="md:hidden mb-2 block text-[9px] font-black uppercase tracking-widest text-slate-400">Quantity Per Sale</label>
                                                        <input
                                                            type="number"
                                                            step={item.unit === 'piece' ? "1" : "0.0001"}
                                                            min={item.unit === 'piece' ? "1" : "0.0001"}
                                                            className={`w-full md:w-24 h-11 px-3 bg-white rounded-lg text-xs font-black text-center focus:ring-orange-500/20 focus:border-orange-500 ${getRecipeFieldError(idx, 'quantity') ? 'border-rose-400' : 'border-slate-200'}`}
                                                            value={item.quantity}
                                                            onChange={(e) => updateIngredientQty(item.ingredient_id, e.target.value)}
                                                        />
                                                        <p className="text-[10px] text-slate-400 font-semibold uppercase tracking-wide mt-2">Consumed each sale</p>
                                                        <InputError message={getRecipeFieldError(idx, 'quantity')} className="mt-2" />
                                                    </div>
                                                    <div>
                                                        <label className="md:hidden mb-2 block text-[9px] font-black uppercase tracking-widest text-slate-400">Unit</label>
                                                        <select
                                                            className={`w-full md:w-24 h-11 px-2 bg-white rounded-lg text-[10px] font-black uppercase focus:ring-orange-500/20 ${getRecipeFieldError(idx, 'unit') ? 'border-rose-400' : 'border-slate-200'}`}
                                                            value={item.unit}
                                                            onChange={(e) => updateIngredientUnit(item.ingredient_id, e.target.value)}
                                                        >
                                                            {uomOptions.map(uom => (
                                                                <option key={uom} value={uom}>{uom}</option>
                                                            ))}
                                                        </select>
                                                        <p className="text-[10px] text-slate-400 font-semibold uppercase tracking-wide mt-2">Recipe unit label</p>
                                                        <InputError message={getRecipeFieldError(idx, 'unit')} className="mt-2" />
                                                    </div>

                                                    <button 
                                                        onClick={() => removeIngredient(item.ingredient_id)}
                                                        className="w-11 h-11 flex items-center justify-center text-slate-300 hover:text-rose-500 hover:bg-rose-50 rounded-lg transition-all md:justify-self-center"
                                                        type="button"
                                                        title="Remove ingredient row"
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="py-12 text-center border-2 border-dashed border-slate-100 rounded-[2.5rem]">
                                                <div className="flex flex-col items-center max-w-sm mx-auto px-4">
                                                    <div className="p-4 bg-orange-50 text-orange-500 rounded-full mb-3">
                                                        <Layers size={32} />
                                                    </div>
                                                    <p className="text-sm font-bold text-slate-700">No recipe ingredients configured yet.</p>
                                                    <p className="text-[11px] text-slate-500 mt-2 leading-relaxed text-center">
                                                        Add ingredients to define what is consumed per sale. This does not change pricing or tax setup.
                                                    </p>
                                                    <button
                                                        type="button"
                                                        onClick={() => searchInputRef.current?.focus()}
                                                        className="mt-4 px-4 py-2 bg-orange-50 text-orange-600 hover:bg-orange-100 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all h-11 flex items-center justify-center"
                                                    >
                                                        Add First Ingredient
                                                    </button>
                                                    <p className="text-[10px] text-slate-400 font-semibold uppercase tracking-wide mt-3">
                                                        Search and select ingredients above, then save recipe changes when ready.
                                                    </p>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </section>

                            {/* Card 5: Branch Pricing */}
                            <section className="order-5 lg:col-span-5 bg-slate-900 text-white p-8 rounded-[2.5rem] shadow-2xl relative overflow-hidden">
                                <div className="absolute top-0 right-0 p-8 text-white opacity-5 pointer-events-none">
                                    <MapPin size={120} />
                                </div>
                                <div className="absolute top-4 left-8 px-2 py-1 rounded-lg bg-white/10 text-slate-200 text-[9px] font-black uppercase tracking-widest">
                                    Branch Pricing Section
                                </div>

                                <div className="flex items-center justify-between mb-8">
                                    <div className="flex items-center gap-3">
                                        <div className="p-2.5 bg-white/10 text-white rounded-xl">
                                            <Store size={20} />
                                        </div>
                                        <div>
                                            <h3 className="text-lg font-black uppercase tracking-widest">Branch Pricing Entry Point</h3>
                                            <p className="text-[10px] text-slate-400 font-bold uppercase tracking-tighter mt-0.5">Set per-branch override rows only. Core pricing logic stays unchanged.</p>
                                        </div>
                                    </div>
                                    <button 
                                        onClick={() => setIsPricingModalOpen(true)}
                                        className="px-3 h-11 flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl transition-all shadow-lg shadow-indigo-600/20 text-[10px] font-black uppercase tracking-widest"
                                        type="button"
                                    >
                                        <Plus size={18} />
                                        Add Override
                                    </button>
                                </div>

                                <div className="space-y-4 max-h-[400px] overflow-y-auto pr-2 custom-scrollbar">
                                    <div className="p-4 rounded-2xl border border-white/15 bg-white/5">
                                        <p className="text-[9px] font-black uppercase tracking-widest text-slate-400">Global Default Price</p>
                                        <p className="text-xl font-black text-white mt-1">₱{(parseFloat(data.selling_price) || 0).toFixed(2)}</p>
                                        <p className="text-[10px] text-slate-400 font-semibold mt-1 leading-relaxed uppercase">
                                            Branch overrides below only affect selected branches and do not modify global pricing rules.
                                        </p>
                                    </div>

                                    {branchPricingFeedback && (
                                        <div className={`rounded-2xl border px-3 py-2 ${
                                            branchPricingFeedback.type === 'success'
                                                ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'
                                                : 'border-rose-500/30 bg-rose-500/10 text-rose-300'
                                        }`}>
                                            <p className="text-[10px] font-black uppercase tracking-widest">{branchPricingFeedback.message}</p>
                                        </div>
                                    )}

                                    {branchPrices.length > 0 ? (
                                        branchPrices.map((bp) => (
                                            <div key={bp.id} className="p-4 bg-white/5 border border-white/10 rounded-2xl flex items-center justify-between group hover:bg-white/10 transition-all">
                                                <div>
                                                    <p className="text-xs font-black uppercase tracking-widest text-slate-300">{bp.branch.name}</p>
                                                    <p className="text-lg font-black mt-1">₱{parseFloat(bp.selling_price).toFixed(2)}</p>
                                                    <p className="text-[10px] text-slate-400 font-semibold uppercase tracking-wider mt-1">
                                                        {(() => {
                                                            const delta = (parseFloat(bp.selling_price) || 0) - (parseFloat(data.selling_price) || 0);
                                                            if (Math.abs(delta) < 0.0001) return 'Matches global default';
                                                            return `${delta > 0 ? '+' : '-'}₱${Math.abs(delta).toFixed(2)} vs global default`;
                                                        })()}
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <span className={`px-2 py-0.5 rounded-lg text-[8px] font-black uppercase tracking-widest ${
                                                        bp.status === 'active' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400'
                                                    }`}>
                                                        {bp.status}
                                                    </span>
                                                    <button 
                                                        className="w-11 h-11 flex items-center justify-center text-white/20 group-hover:text-white/60 hover:text-rose-400 transition-all rounded-xl hover:bg-white/5" 
                                                        type="button"
                                                        title="Remove branch override"
                                                        onClick={() => deleteBranchPricing(bp.id)}
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                </div>
                                            </div>
                                        ))
                                    ) : (
                                        <div className="py-12 text-center border-2 border-dashed border-white/10 rounded-3xl px-4">
                                            <p className="text-sm font-bold text-slate-300">No branch pricing overrides configured.</p>
                                            <p className="text-[10px] text-slate-500 uppercase tracking-widest mt-2 leading-relaxed">
                                                All branches currently use the global default price. Add an override to set branch-specific price behavior.
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() => setIsPricingModalOpen(true)}
                                                className="mt-4 px-4 py-2 bg-white/10 hover:bg-white/20 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all h-11 flex items-center justify-center"
                                            >
                                                Add First Branch Override
                                            </button>
                                        </div>
                                    )}
                                </div>
                            </section>

                        </div>

                        {/* Sticky Footer Actions for Main Product Form */}
                        <div className="fixed bottom-0 left-0 right-0 z-40 bg-slate-900/90 backdrop-blur-md border-t border-slate-800/60 py-4 px-6 shadow-2xl">
                            <div className="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
                                <div className="flex items-center gap-3">
                                    <div className={`p-2 rounded-xl ${
                                        recentlySuccessful && !isDirty && Object.keys(errors).length === 0
                                            ? 'bg-emerald-500/10 text-emerald-400'
                                            : 'bg-indigo-500/10 text-indigo-400'
                                    }`}>
                                        {recentlySuccessful && !isDirty && Object.keys(errors).length === 0 ? (
                                            <CheckCircle2 size={16} />
                                        ) : (
                                            <Info size={16} />
                                        )}
                                    </div>
                                    <div>
                                        <p className="text-xs font-bold text-slate-300">
                                            {recentlySuccessful && !isDirty && Object.keys(errors).length === 0
                                                ? 'Product changes were saved.'
                                                : 'Modifying this product updates the master global record.'}
                                        </p>
                                        {Object.keys(errors).length > 0 ? (
                                            <p className="text-[10px] text-rose-400 font-semibold uppercase mt-0.5 tracking-wider">
                                                {Object.keys(errors).length} field{Object.keys(errors).length > 1 ? 's' : ''} require attention
                                            </p>
                                        ) : isDirty ? (
                                            <p className="text-[10px] text-amber-500 font-semibold uppercase mt-0.5 tracking-wider animate-pulse">Unsaved changes detected</p>
                                        ) : recentlySuccessful ? (
                                            <p className="text-[10px] text-emerald-400 font-semibold uppercase mt-0.5 tracking-wider">All changes saved successfully</p>
                                        ) : (
                                            <p className="text-[10px] text-slate-400 font-semibold uppercase mt-0.5 tracking-wider">No pending modifications</p>
                                        )}
                                    </div>
                                </div>
                                <div className="flex items-center gap-4">
                                    <Link
                                        href={route('admin.products.index')}
                                        className="px-6 py-2.5 text-xs font-black uppercase tracking-widest text-slate-400 hover:text-white transition-colors"
                                    >
                                        {recentlySuccessful && !isDirty ? 'View Product List' : 'Back to Products'}
                                    </Link>
                                    <PrimaryButton 
                                        type="submit"
                                        disabled={processing || !isDirty}
                                        className={`px-8 py-3 rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg transition-all active:scale-95 flex items-center gap-2 h-11 ${
                                            processing || !isDirty
                                                ? 'bg-slate-800 text-slate-500 border border-slate-700/50 cursor-not-allowed shadow-none'
                                                : 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-indigo-600/20'
                                        }`}
                                    >
                                        <Save size={16} />
                                        {processing ? 'Saving changes...' : recentlySuccessful && !isDirty ? 'Saved' : 'Save Product'}
                                    </PrimaryButton>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            {/* Branch Pricing Modal */}
            {isPricingModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
                    <div className="bg-white w-full max-w-md rounded-[2.5rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
                        <form onSubmit={submitBranchPricing} className="p-8">
                            <div className="flex items-center justify-between mb-8">
                                <h3 className="text-xl font-black text-slate-800">New Branch Override</h3>
                                <button type="button" onClick={() => setIsPricingModalOpen(false)} className="text-slate-400 hover:text-slate-600 transition-all">
                                    <X size={24} />
                                </button>
                            </div>

                            <div className="space-y-6">
                                <div>
                                    <InputLabel value="Select Target Branch" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                    <select
                                        className="block w-full border-slate-100 bg-slate-50 rounded-2xl py-2 focus:ring-indigo-500/20 text-sm font-bold h-11"
                                        value={branchPricingForm.data.branch_id}
                                        onChange={(e) => branchPricingForm.setData('branch_id', e.target.value)}
                                        required
                                    >
                                        <option value="">Choose a branch...</option>
                                        {branches.map(branch => (
                                            <option key={branch.id} value={branch.id}>{branch.name}</option>
                                        ))}
                                    </select>
                                    <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">
                                        Override applies only to the selected branch. Other branches keep the global default price.
                                    </p>
                                    <InputError message={branchPricingForm.errors.branch_id} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel value="Branch Selling Price" className="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2" />
                                    <div className="relative">
                                        <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 font-bold">
                                            ₱
                                        </div>
                                        <TextInput
                                            type="number"
                                            step="0.01"
                                            className="block w-full pl-10 border-slate-100 bg-slate-50 rounded-2xl py-3.5 focus:ring-indigo-500/20 text-sm font-black"
                                            value={branchPricingForm.data.selling_price}
                                            onChange={(e) => branchPricingForm.setData('selling_price', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <p className="text-[10px] text-slate-400 font-semibold mt-1.5 leading-normal uppercase">
                                        This sets the branch override amount only. Global product price remains unchanged.
                                    </p>
                                    <InputError message={branchPricingForm.errors.selling_price} className="mt-2" />
                                </div>
                            </div>

                            <div className="mt-10 flex gap-4">
                                <SecondaryButton 
                                    onClick={() => setIsPricingModalOpen(false)}
                                    className="flex-1 justify-center rounded-2xl border-none bg-slate-100 font-black py-4 uppercase text-[10px] tracking-widest h-11"
                                >
                                    Cancel
                                </SecondaryButton>
                                <PrimaryButton 
                                    disabled={branchPricingForm.processing}
                                    className="flex-1 justify-center rounded-2xl bg-indigo-600 hover:bg-indigo-500 font-black py-4 uppercase text-[10px] tracking-widest shadow-lg shadow-indigo-600/20 h-11"
                                >
                                    {branchPricingForm.processing ? 'Applying override...' : 'Apply Branch Override'}
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
