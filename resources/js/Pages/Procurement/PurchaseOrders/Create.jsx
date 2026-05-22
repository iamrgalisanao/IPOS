import React, { useState, useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { 
    ArrowLeft,
    Save,
    Trash2,
    Plus,
    Building2,
    Truck,
    Calendar,
    FileText,
    DollarSign,
    Calculator,
    Package
} from 'lucide-react';

export default function Create({ auth, suppliers, branches, products }) {
    const [selectedProduct, setSelectedProduct] = useState('');
    const [lineQty, setLineQty] = useState(1);
    const [lineCost, setLineCost] = useState('');

    const { data, setData, post, processing, errors } = useForm({
        supplier_id: '',
        branch_id: branches.length === 1 ? branches[0].id : '',
        order_date: new Date().toISOString().split('T')[0],
        expected_delivery_date: '',
        notes: '',
        lines: []
    });

    const [frontendTotal, setFrontendTotal] = useState(0);

    // Calculate total on lines update
    useEffect(() => {
        const sum = data.lines.reduce((acc, line) => acc + (line.ordered_quantity * line.unit_cost), 0);
        setFrontendTotal(sum);
    }, [data.lines]);

    const handleAddLine = () => {
        if (!selectedProduct) return;
        const prod = products.find(p => p.id === selectedProduct);
        if (!prod) return;

        // Check if product already in lines
        const exists = data.lines.find(l => l.product_id === prod.id);
        if (exists) {
            alert('Product is already added. Please update its quantity directly.');
            return;
        }

        const cost = lineCost !== '' ? parseFloat(lineCost) : parseFloat(prod.cost_price || 0);

        const newLine = {
            product_id: prod.id,
            sku: prod.sku,
            name: prod.name,
            ordered_quantity: parseFloat(lineQty),
            unit_cost: cost
        };

        setData('lines', [...data.lines, newLine]);
        setSelectedProduct('');
        setLineQty(1);
        setLineCost('');
    };

    const handleRemoveLine = (index) => {
        const updatedLines = [...data.lines];
        updatedLines.splice(index, 1);
        setData('lines', updatedLines);
    };

    const handleLineChange = (index, field, value) => {
        const updatedLines = [...data.lines];
        updatedLines[index][field] = parseFloat(value) || 0;
        setData('lines', updatedLines);
    };

    // Auto-fill cost when product selected
    const handleProductSelect = (e) => {
        const prodId = e.target.value;
        setSelectedProduct(prodId);
        const prod = products.find(p => p.id === prodId);
        if (prod) {
            setLineCost(prod.cost_price || '0.00');
        } else {
            setLineCost('');
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('procurement.purchase-orders.store'));
    };

    const formatCurrency = (val) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 4,
            maximumFractionDigits: 4,
        }).format(val);
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center gap-3">
                    <Link
                        href={route('procurement.purchase-orders.index')}
                        className="p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl transition-all"
                    >
                        <ArrowLeft size={16} />
                    </Link>
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">Create Purchase Order</h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">Draft a new purchase order commitment. Inventory valuations are untouched until receiving occurs.</p>
                    </div>
                </div>
            }
        >
            <Head title="Create Purchase Order" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <form onSubmit={handleSubmit} className="space-y-8">
                        {/* Header fields card */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-8 shadow-sm">
                            <h3 className="font-extrabold text-slate-800 text-base mb-6 flex items-center gap-2">
                                <FileText size={18} className="text-indigo-600" />
                                Order Details
                            </h3>

                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                                {/* Branch */}
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1">
                                        <Building2 size={12} />
                                        Target Branch
                                    </label>
                                    <select
                                        className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                        value={data.branch_id}
                                        onChange={(e) => setData('branch_id', e.target.value)}
                                        disabled={branches.length === 1}
                                    >
                                        <option value="">Select Branch</option>
                                        {branches.map(b => (
                                            <option key={b.id} value={b.id}>{b.name}</option>
                                        ))}
                                    </select>
                                    {errors.branch_id && <div className="text-rose-500 text-xs font-bold mt-1.5">{errors.branch_id}</div>}
                                </div>

                                {/* Supplier */}
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1">
                                        <Truck size={12} />
                                        Supplier
                                    </label>
                                    <select
                                        className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                        value={data.supplier_id}
                                        onChange={(e) => setData('supplier_id', e.target.value)}
                                    >
                                        <option value="">Select Supplier</option>
                                        {suppliers.map(s => (
                                            <option key={s.id} value={s.id}>{s.name} ({s.code})</option>
                                        ))}
                                    </select>
                                    {errors.supplier_id && <div className="text-rose-500 text-xs font-bold mt-1.5">{errors.supplier_id}</div>}
                                </div>

                                {/* Order Date */}
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1">
                                        <Calendar size={12} />
                                        Order Date
                                    </label>
                                    <input
                                        type="date"
                                        className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                        value={data.order_date}
                                        onChange={(e) => setData('order_date', e.target.value)}
                                    />
                                    {errors.order_date && <div className="text-rose-500 text-xs font-bold mt-1.5">{errors.order_date}</div>}
                                </div>

                                {/* Expected Delivery Date */}
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1">
                                        <Calendar size={12} />
                                        Expected Delivery
                                    </label>
                                    <input
                                        type="date"
                                        className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                        value={data.expected_delivery_date}
                                        onChange={(e) => setData('expected_delivery_date', e.target.value)}
                                    />
                                    {errors.expected_delivery_date && <div className="text-rose-500 text-xs font-bold mt-1.5">{errors.expected_delivery_date}</div>}
                                </div>
                            </div>
                        </div>

                        {/* Interactive Line Builder */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-8 shadow-sm">
                            <h3 className="font-extrabold text-slate-800 text-base mb-6 flex items-center gap-2">
                                <Package size={18} className="text-indigo-600" />
                                Add Procurement Line Items
                            </h3>

                            {/* Live line injector */}
                            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 bg-slate-50 p-6 rounded-2xl mb-8 items-end border border-slate-100">
                                <div className="md:col-span-2">
                                    <label className="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Select Inventory Product</label>
                                    <select
                                        className="w-full py-2.5 px-4 bg-white border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                        value={selectedProduct}
                                        onChange={handleProductSelect}
                                    >
                                        <option value="">Choose product...</option>
                                        {products.map(p => (
                                            <option key={p.id} value={p.id}>{p.name} ({p.sku})</option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Quantity</label>
                                    <input
                                        type="number"
                                        min="0.0001"
                                        step="any"
                                        className="w-full py-2.5 px-4 bg-white border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all text-center"
                                        value={lineQty}
                                        onChange={(e) => setLineQty(e.target.value)}
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Unit Cost (PHP)</label>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        className="w-full py-2.5 px-4 bg-white border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all text-right"
                                        value={lineCost}
                                        onChange={(e) => setLineCost(e.target.value)}
                                    />
                                </div>

                                <div className="md:col-span-4 flex justify-end mt-4">
                                    <button
                                        type="button"
                                        onClick={handleAddLine}
                                        disabled={!selectedProduct}
                                        className="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white font-bold text-xs uppercase tracking-widest rounded-xl hover:bg-slate-800 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                    >
                                        <Plus size={14} />
                                        Inject Line Item
                                    </button>
                                </div>
                            </div>

                            {/* Added lines grid */}
                            {errors.lines && <div className="text-rose-500 text-sm font-bold mb-4">{errors.lines}</div>}
                            
                            <div className="border border-slate-100 rounded-[2rem] overflow-hidden mb-6">
                                <table className="w-full text-left border-collapse">
                                    <thead>
                                        <tr className="bg-slate-50/50">
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">SKU</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Product Name</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center w-[150px]">Ordered Qty</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right w-[180px]">Unit Cost</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Line Total</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right w-[80px]"></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-50">
                                        {data.lines.length > 0 ? (
                                            data.lines.map((line, idx) => (
                                                <tr key={idx} className="hover:bg-slate-50/10">
                                                    <td className="px-6 py-4 text-xs font-black text-slate-500">{line.sku}</td>
                                                    <td className="px-6 py-4 text-sm font-bold text-slate-700">{line.name}</td>
                                                    <td className="px-6 py-4 text-center">
                                                        <input
                                                            type="number"
                                                            min="0.0001"
                                                            step="any"
                                                            className="w-full py-1.5 px-3 border border-slate-200 rounded-lg text-sm text-center font-bold text-slate-700 focus:ring-2 focus:ring-indigo-500/20"
                                                            value={line.ordered_quantity}
                                                            onChange={(e) => handleLineChange(idx, 'ordered_quantity', e.target.value)}
                                                        />
                                                    </td>
                                                    <td className="px-6 py-4 text-right">
                                                        <input
                                                            type="number"
                                                            step="0.0001"
                                                            className="w-full py-1.5 px-3 border border-slate-200 rounded-lg text-sm text-right font-bold text-slate-700 focus:ring-2 focus:ring-indigo-500/20"
                                                            value={line.unit_cost}
                                                            onChange={(e) => handleLineChange(idx, 'unit_cost', e.target.value)}
                                                        />
                                                    </td>
                                                    <td className="px-6 py-4 text-sm font-black text-slate-800 text-right">
                                                        {formatCurrency(line.ordered_quantity * line.unit_cost)}
                                                    </td>
                                                    <td className="px-6 py-4 text-right">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRemoveLine(idx)}
                                                            className="p-1.5 text-slate-300 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-all"
                                                        >
                                                            <Trash2 size={16} />
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="6" className="px-6 py-12 text-center text-sm font-medium text-slate-400">
                                                    No lines added yet. Build the PO inventory contents using the panel above.
                                                </td>
                                            </tr>
                                        )}
                                        {data.lines.length > 0 && (
                                            <tr className="bg-slate-50/50">
                                                <td colSpan="4" className="px-6 py-4 text-right text-xs font-black uppercase text-slate-400 tracking-wider">
                                                    Estimated Total
                                                </td>
                                                <td className="px-6 py-4 text-right font-black text-base text-indigo-600">
                                                    {formatCurrency(frontendTotal)}
                                                </td>
                                                <td></td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Notes and Form Submission Controls */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div className="bg-white rounded-3xl border border-slate-100 p-8 shadow-sm md:col-span-2">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Order Notes</label>
                                <textarea
                                    className="w-full min-h-[120px] p-4 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all resize-y"
                                    placeholder="Enter PO instructions, special delivery instructions, or notes for approval..."
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                />
                                {errors.notes && <div className="text-rose-500 text-xs font-bold mt-1">{errors.notes}</div>}
                            </div>

                            <div className="bg-white rounded-3xl border border-slate-100 p-8 shadow-sm flex flex-col justify-between items-stretch">
                                <div>
                                    <h4 className="font-extrabold text-slate-800 text-sm uppercase tracking-wider flex items-center gap-2 mb-3">
                                        <Calculator size={16} className="text-indigo-600" />
                                        Summary Summary
                                    </h4>
                                    <div className="space-y-2 mt-4 text-xs font-bold text-slate-500 uppercase tracking-wider">
                                        <div className="flex justify-between">
                                            <span>Subtotal</span>
                                            <span className="text-slate-800">{formatCurrency(frontendTotal)}</span>
                                        </div>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing || data.lines.length === 0}
                                    className="mt-8 inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-2xl font-bold text-sm shadow-lg shadow-indigo-600/20 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                >
                                    <Save size={18} />
                                    Save Purchase Order
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
