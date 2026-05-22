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
    Package,
    AlertTriangle
} from 'lucide-react';

export default function Create({ auth, suppliers, branches, products, purchaseOrder }) {
    const [selectedProduct, setSelectedProduct] = useState('');
    const [lineQty, setLineQty] = useState(1);
    const [lineCost, setLineCost] = useState('');
    const [lineLot, setLineLot] = useState('');
    const [lineExpiry, setLineExpiry] = useState('');
    const [lineRemarks, setLineRemarks] = useState('');

    const { data, setData, post, processing, errors } = useForm({
        supplier_id: purchaseOrder ? purchaseOrder.supplier_id : '',
        branch_id: purchaseOrder ? purchaseOrder.branch_id : (branches.length === 1 ? branches[0].id : ''),
        purchase_order_id: purchaseOrder ? purchaseOrder.id : '',
        received_at: new Date().toISOString().split('T')[0],
        delivery_ref_number: '',
        notes: '',
        lines: purchaseOrder ? purchaseOrder.lines.map(line => ({
            purchase_order_line_id: line.id,
            product_id: line.product_id,
            sku: line.product?.sku || '',
            name: line.product?.name || '',
            ordered_quantity: parseFloat(line.ordered_quantity),
            received_quantity: parseFloat(line.ordered_quantity) - parseFloat(line.received_quantity || 0),
            unit_cost: parseFloat(line.unit_cost),
            lot_number: '',
            expiry_date: '',
            remarks: ''
        })).filter(l => l.received_quantity > 0) : []
    });

    const [frontendTotal, setFrontendTotal] = useState(0);

    // Calculate total
    useEffect(() => {
        const sum = data.lines.reduce((acc, line) => acc + (line.received_quantity * line.unit_cost), 0);
        setFrontendTotal(sum);
    }, [data.lines]);

    const handleAddLine = () => {
        if (!selectedProduct) return;
        const prod = products.find(p => p.id === selectedProduct);
        if (!prod) return;

        // Check duplicate
        const exists = data.lines.find(l => l.product_id === prod.id);
        if (exists) {
            alert('Product is already added. Please update its received quantity directly.');
            return;
        }

        const cost = lineCost !== '' ? parseFloat(lineCost) : parseFloat(prod.cost_price || 0);

        const newLine = {
            product_id: prod.id,
            sku: prod.sku,
            name: prod.name,
            ordered_quantity: 0,
            received_quantity: parseFloat(lineQty),
            unit_cost: cost,
            lot_number: lineLot,
            expiry_date: lineExpiry,
            remarks: lineRemarks
        };

        setData('lines', [...data.lines, newLine]);
        setSelectedProduct('');
        setLineQty(1);
        setLineCost('');
        setLineLot('');
        setLineExpiry('');
        setLineRemarks('');
    };

    const handleRemoveLine = (index) => {
        if (purchaseOrder) {
            alert('Cannot remove items when receiving from a Purchase Order. Adjust received quantity to 0 instead.');
            return;
        }
        const updatedLines = [...data.lines];
        updatedLines.splice(index, 1);
        setData('lines', updatedLines);
    };

    const handleLineChange = (index, field, value) => {
        const updatedLines = [...data.lines];
        if (field === 'received_quantity' || field === 'unit_cost') {
            updatedLines[index][field] = parseFloat(value) || 0;
        } else {
            updatedLines[index][field] = value;
        }
        setData('lines', updatedLines);
    };

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
        post(route('procurement.receivings.store'));
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
                        href={route('procurement.receivings.index')}
                        className="p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl transition-all"
                    >
                        <ArrowLeft size={16} />
                    </Link>
                    <div>
                        <h2 className="font-extrabold text-2xl text-slate-800 leading-tight tracking-tight">
                            {purchaseOrder ? `Receive PO: ${purchaseOrder.po_number}` : 'Create Goods Receiving Voucher'}
                        </h2>
                        <p className="text-sm text-slate-500 font-medium mt-1">
                            {purchaseOrder 
                                ? `Ingest delivery shipments matching Purchase Order ${purchaseOrder.po_number}.` 
                                : 'Record standalone supplier deliveries directly without an upfront PO commitment.'
                            }
                        </p>
                    </div>
                </div>
            }
        >
            <Head title="Create Goods Receiving Voucher" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <form onSubmit={handleSubmit} className="space-y-8">
                        {/* Header Details */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-8 shadow-sm">
                            <h3 className="font-extrabold text-slate-800 text-base mb-6 flex items-center gap-2">
                                <FileText size={18} className="text-indigo-600" />
                                General Information
                            </h3>

                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                                {/* Branch */}
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1">
                                        <Building2 size={12} />
                                        Receiving Branch
                                    </label>
                                    <select
                                        className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all disabled:opacity-75 disabled:cursor-not-allowed"
                                        value={data.branch_id}
                                        onChange={(e) => setData('branch_id', e.target.value)}
                                        disabled={branches.length === 1 || !!purchaseOrder}
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
                                        className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all disabled:opacity-75 disabled:cursor-not-allowed"
                                        value={data.supplier_id}
                                        onChange={(e) => setData('supplier_id', e.target.value)}
                                        disabled={!!purchaseOrder}
                                    >
                                        <option value="">Select Supplier</option>
                                        {suppliers.map(s => (
                                            <option key={s.id} value={s.id}>{s.name} ({s.code})</option>
                                        ))}
                                    </select>
                                    {errors.supplier_id && <div className="text-rose-500 text-xs font-bold mt-1.5">{errors.supplier_id}</div>}
                                </div>

                                {/* Received At Date */}
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1">
                                        <Calendar size={12} />
                                        Received At Date
                                    </label>
                                    <input
                                        type="date"
                                        className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                        value={data.received_at}
                                        onChange={(e) => setData('received_at', e.target.value)}
                                    />
                                    {errors.received_at && <div className="text-rose-500 text-xs font-bold mt-1.5">{errors.received_at}</div>}
                                </div>

                                {/* Delivery Ref */}
                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2 flex items-center gap-1">
                                        <FileText size={12} />
                                        Delivery Reference No.
                                    </label>
                                    <input
                                        type="text"
                                        className="w-full py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                        placeholder="e.g. SI-12345"
                                        value={data.delivery_ref_number}
                                        onChange={(e) => setData('delivery_ref_number', e.target.value)}
                                    />
                                    {errors.delivery_ref_number && <div className="text-rose-500 text-xs font-bold mt-1.5">{errors.delivery_ref_number}</div>}
                                </div>
                            </div>
                        </div>

                        {/* Interactive Line Panel (Standalone only) */}
                        {!purchaseOrder && (
                            <div className="bg-white rounded-3xl border border-slate-100 p-8 shadow-sm">
                                <h3 className="font-extrabold text-slate-800 text-base mb-6 flex items-center gap-2">
                                    <Package size={18} className="text-indigo-600" />
                                    Add Custom Deliveries
                                </h3>

                                <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 bg-slate-50 p-6 rounded-2xl mb-8 items-end border border-slate-100">
                                    <div className="md:col-span-2 lg:col-span-3">
                                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Product</label>
                                        <select
                                            className="w-full py-2.5 px-4 bg-white border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
                                            value={selectedProduct}
                                            onChange={handleProductSelect}
                                        >
                                            <option value="">Select product...</option>
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

                                    <div>
                                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Lot/Batch No.</label>
                                        <input
                                            type="text"
                                            className="w-full py-2.5 px-4 bg-white border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20"
                                            placeholder="Batch ID"
                                            value={lineLot}
                                            onChange={(e) => setLineLot(e.target.value)}
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Expiry Date</label>
                                        <input
                                            type="date"
                                            className="w-full py-2.5 px-4 bg-white border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20"
                                            value={lineExpiry}
                                            onChange={(e) => setLineExpiry(e.target.value)}
                                        />
                                    </div>

                                    <div className="md:col-span-3 lg:col-span-4">
                                        <label className="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Line Remarks</label>
                                        <input
                                            type="text"
                                            className="w-full py-2.5 px-4 bg-white border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20"
                                            placeholder="Remarks, damaged item details, etc."
                                            value={lineRemarks}
                                            onChange={(e) => setLineRemarks(e.target.value)}
                                        />
                                    </div>

                                    <div className="md:col-span-3 lg:col-span-2 flex justify-end">
                                        <button
                                            type="button"
                                            onClick={handleAddLine}
                                            disabled={!selectedProduct}
                                            className="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white font-bold text-xs uppercase tracking-widest rounded-xl hover:bg-slate-800 disabled:opacity-50 disabled:cursor-not-allowed transition-all w-full md:w-auto justify-center"
                                        >
                                            <Plus size={14} />
                                            Inject Delivery Item
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Received Lines Grid */}
                        <div className="bg-white rounded-3xl border border-slate-100 p-8 shadow-sm">
                            <h3 className="font-extrabold text-slate-800 text-base mb-6 flex items-center gap-2">
                                <Package size={18} className="text-indigo-600" />
                                Goods Receiving Voucher Items
                            </h3>

                            {errors.lines && <div className="text-rose-500 text-sm font-bold mb-4">{errors.lines}</div>}

                            <div className="border border-slate-100 rounded-[2rem] overflow-hidden mb-6">
                                <table className="w-full text-left border-collapse">
                                    <thead>
                                        <tr className="bg-slate-50/50">
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">SKU</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Product Name</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center w-[120px]">Ordered Qty</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-center w-[120px]">Received Qty</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 w-[180px]">Lot/Batch No.</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 w-[160px]">Expiry Date</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right w-[150px]">Unit Cost</th>
                                            <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Line Total</th>
                                            {!purchaseOrder && <th className="px-6 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right w-[60px]"></th>}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-50">
                                        {data.lines.length > 0 ? (
                                            data.lines.map((line, idx) => (
                                                <tr key={idx} className="hover:bg-slate-50/10">
                                                    <td className="px-6 py-4 text-xs font-black text-slate-500">{line.sku}</td>
                                                    <td className="px-6 py-4 text-sm font-bold text-slate-700">{line.name}</td>
                                                    <td className="px-6 py-4 text-center text-sm font-semibold text-slate-400">
                                                        {purchaseOrder ? line.ordered_quantity : '-'}
                                                    </td>
                                                    <td className="px-6 py-4 text-center">
                                                        <input
                                                            type="number"
                                                            min="0.0001"
                                                            step="any"
                                                            className="w-full py-1.5 px-3 border border-slate-200 rounded-lg text-sm text-center font-bold text-slate-700 focus:ring-2 focus:ring-indigo-500/20"
                                                            value={line.received_quantity}
                                                            onChange={(e) => handleLineChange(idx, 'received_quantity', e.target.value)}
                                                        />
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <input
                                                            type="text"
                                                            placeholder="Lot/Batch ID"
                                                            className="w-full py-1.5 px-3 border border-slate-200 rounded-lg text-xs font-semibold focus:ring-2 focus:ring-indigo-500/20"
                                                            value={line.lot_number}
                                                            onChange={(e) => handleLineChange(idx, 'lot_number', e.target.value)}
                                                        />
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <input
                                                            type="date"
                                                            className="w-full py-1.5 px-3 border border-slate-200 rounded-lg text-xs font-semibold focus:ring-2 focus:ring-indigo-500/20"
                                                            value={line.expiry_date || ''}
                                                            onChange={(e) => handleLineChange(idx, 'expiry_date', e.target.value)}
                                                        />
                                                    </td>
                                                    <td className="px-6 py-4 text-right">
                                                        <input
                                                            type="number"
                                                            step="0.0001"
                                                            className="w-full py-1.5 px-3 border border-slate-200 rounded-lg text-sm text-right font-bold text-slate-700 focus:ring-2 focus:ring-indigo-500/20"
                                                            value={line.unit_cost}
                                                            onChange={(e) => handleLineChange(idx, 'unit_cost', e.target.value)}
                                                            disabled={!!purchaseOrder}
                                                        />
                                                    </td>
                                                    <td className="px-6 py-4 text-sm font-black text-slate-800 text-right">
                                                        {formatCurrency(line.received_quantity * line.unit_cost)}
                                                    </td>
                                                    {!purchaseOrder && (
                                                        <td className="px-6 py-4 text-right">
                                                            <button
                                                                type="button"
                                                                onClick={() => handleRemoveLine(idx)}
                                                                className="p-1.5 text-slate-300 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-all"
                                                            >
                                                                <Trash2 size={16} />
                                                            </button>
                                                        </td>
                                                    )}
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan={purchaseOrder ? "8" : "9"} className="px-6 py-12 text-center text-sm font-medium text-slate-400">
                                                    No lines added yet. Build the GRV contents using the panel above.
                                                </td>
                                            </tr>
                                        )}
                                        {data.lines.length > 0 && (
                                            <tr className="bg-slate-50/50">
                                                <td colSpan={purchaseOrder ? "7" : "7"} className="px-6 py-4 text-right text-xs font-black uppercase text-slate-400 tracking-wider">
                                                    Total Received Value
                                                </td>
                                                <td className="px-6 py-4 text-right font-black text-base text-indigo-600">
                                                    {formatCurrency(frontendTotal)}
                                                </td>
                                                {!purchaseOrder && <td></td>}
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Notes and Submission */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div className="bg-white rounded-3xl border border-slate-100 p-8 shadow-sm md:col-span-2">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Voucher Notes</label>
                                <textarea
                                    className="w-full min-h-[120px] p-4 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-medium focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all resize-y"
                                    placeholder="Enter physical delivery remarks, lot number exceptions, damaged package logs..."
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                />
                                {errors.notes && <div className="text-rose-500 text-xs font-bold mt-1">{errors.notes}</div>}
                            </div>

                            <div className="bg-white rounded-3xl border border-slate-100 p-8 shadow-sm flex flex-col justify-between items-stretch">
                                <div>
                                    <h4 className="font-extrabold text-slate-800 text-sm uppercase tracking-wider flex items-center gap-2 mb-3">
                                        <Calculator size={16} className="text-indigo-600" />
                                        Draft Cost Analysis
                                    </h4>
                                    <div className="space-y-2 mt-4 text-xs font-bold text-slate-500 uppercase tracking-wider">
                                        <div className="flex justify-between">
                                            <span>Received Value</span>
                                            <span className="text-slate-800">{formatCurrency(frontendTotal)}</span>
                                        </div>
                                    </div>
                                    <div className="mt-6 flex items-start gap-2 bg-amber-50 rounded-xl p-3 border border-amber-100 text-[10px] text-amber-800 font-semibold leading-relaxed">
                                        <AlertTriangle size={14} className="flex-shrink-0 text-amber-600" />
                                        <span>Saving this voucher creates a DRAFT workspace only. Real inventory stock and WAC values are updated only upon posting.</span>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing || data.lines.length === 0}
                                    className="mt-8 inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-2xl font-bold text-sm shadow-lg shadow-indigo-600/20 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                >
                                    <Save size={18} />
                                    Save GRV Draft
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
