import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    ArrowLeft, 
    Save, 
    AlertCircle, 
    Search,
    Package,
    History,
    MessageSquare,
    CheckCircle2,
    XCircle,
    Info,
    AlertTriangle,
    Loader2,
    Printer,
    Download
} from 'lucide-react';

const StatusBadge = ({ status }) => {
    const statusConfig = {
        draft: { color: 'bg-slate-100 text-slate-800 border-slate-200', label: 'Draft' },
        counting: { color: 'bg-blue-100 text-blue-800 border-blue-200', label: 'Counting' },
        review: { color: 'bg-amber-100 text-amber-800 border-amber-200', label: 'In Review' },
        posted: { color: 'bg-emerald-100 text-emerald-800 border-emerald-200', label: 'Posted' },
        cancelled: { color: 'bg-rose-100 text-rose-800 border-rose-200', label: 'Cancelled' },
        rejected: { color: 'bg-rose-100 text-rose-800 border-rose-200', label: 'Rejected' },
    };

    const config = statusConfig[status] || statusConfig.draft;

    return (
        <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border ${config.color}`}>
            {config.label}
        </span>
    );
};

export default function Review({ auth, session, lines, reasonCodes }) {
    const [searchTerm, setSearchTerm] = useState('');
    const [localLines, setLocalLines] = useState(lines);
    const [isDirty, setIsDirty] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [rejectConfirmOpen, setRejectConfirmOpen] = useState(false);
    const [postConfirmOpen, setPostConfirmOpen] = useState(false);

    const isTerminal = session.status === 'posted' || session.status === 'cancelled' || session.status === 'rejected';

    const filteredLines = localLines.filter(line => 
        line.product_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        line.sku.toLowerCase().includes(searchTerm.toLowerCase())
    );

    const handleReasonChange = (lineId, reasonCode) => {
        if (isTerminal) return;
        setLocalLines(prev => prev.map(line => 
            line.id === lineId ? { ...line, reason_code: reasonCode } : line
        ));
        setIsDirty(true);
    };

    const handleRemarksChange = (lineId, remarks) => {
        if (isTerminal) return;
        setLocalLines(prev => prev.map(line => 
            line.id === lineId ? { ...line, remarks } : line
        ));
        setIsDirty(true);
    };

    const handleSaveReasons = () => {
        setProcessing(true);
        router.put(route('inventory.stocktakes.variance-reasons.update', session.id), {
            lines: localLines.map(l => ({
                id: l.id,
                reason_code: l.reason_code,
                remarks: l.remarks
            }))
        }, {
            onSuccess: () => {
                setIsDirty(false);
                setProcessing(false);
            },
            onError: (errors) => {
                setProcessing(false);
                alert(Object.values(errors).join('\n'));
            },
            preserveScroll: true
        });
    };

    const handleReject = () => {
        setRejectConfirmOpen(true);
    };

    const handleConfirmReject = () => {
        setRejectConfirmOpen(false);
        router.post(route('inventory.stocktakes.reject', session.id));
    };

    const handlePost = () => {
        if (linesMissingReasons.length > 0) {
            alert(`Cannot post. ${linesMissingReasons.length} items with variance are missing reason codes.`);
            return;
        }

        if (otherReasonsMissingRemarks.length > 0) {
            alert(`Cannot post. ${otherReasonsMissingRemarks.length} items with 'Other' reason code are missing remarks.`);
            return;
        }

        setPostConfirmOpen(true);
    };

    const handleConfirmPost = () => {
        setPostConfirmOpen(false);
        router.post(route('inventory.stocktakes.post', session.id));
    };

    const formatQuantity = (val) => {
        return parseFloat(val || 0).toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 4
        });
    };

    const linesWithVariance = localLines.filter(l => Math.abs(l.variance_quantity) > 0.0001);
    const linesMissingReasons = linesWithVariance.filter(l => !l.reason_code);
    const otherReasonsMissingRemarks = linesWithVariance.filter(l => l.reason_code === 'OTHER' && !l.remarks);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex items-center gap-4">
                        <Link
                            href={route('inventory.stocktakes.index')}
                            className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition-all"
                        >
                            <ArrowLeft size={20} />
                        </Link>
                        <div className="space-y-1">
                            <div className="flex items-center gap-3">
                                <h2 className="text-2xl font-bold leading-tight text-slate-900">Review: {session.stocktake_number}</h2>
                                <StatusBadge status={session.status} />
                            </div>
                            <div className="flex items-center gap-4 text-xs text-slate-500 font-medium">
                                <div className="flex items-center gap-1">
                                    <Package size={12} className="text-slate-400" />
                                    {lines.length} Products
                                </div>
                                <div className="flex items-center gap-1 text-rose-500 font-bold">
                                    <AlertTriangle size={12} />
                                    {linesWithVariance.length} Variances
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <div className="flex flex-col items-start">
                            <Link
                                href={route('inventory.stocktakes.summary', session.id)}
                                className="inline-flex items-center gap-2 px-4 py-2.5 text-slate-600 text-sm font-bold rounded-xl hover:bg-slate-100 transition-all"
                            >
                                <Printer size={18} />
                                View Summary
                            </Link>
                            <span className="text-[10px] font-medium text-slate-400 px-4">Print-ready summary view</span>
                        </div>

                        <a
                            href={route('inventory.stocktakes.export.variance-csv', session.id)}
                            className="inline-flex items-center gap-2 px-4 py-2.5 text-slate-600 text-sm font-bold rounded-xl hover:bg-slate-100 transition-all"
                        >
                            <Download size={18} />
                            Export CSV
                        </a>

                        {!isTerminal && auth.permissions.includes('inventory.stocktake.review') && (
                            <button
                                onClick={handleReject}
                                disabled={processing}
                                className="inline-flex items-center gap-2 px-6 py-2.5 bg-rose-50 text-rose-600 text-sm font-bold rounded-xl hover:bg-rose-100 transition-all border border-rose-200"
                            >
                                <XCircle size={18} />
                                Reject Session
                            </button>
                        )}
                        
                        {!isTerminal && auth.permissions.includes('inventory.stocktake.review') && (
                            <button
                                onClick={handleSaveReasons}
                                disabled={!isDirty || processing}
                                className={`inline-flex items-center gap-2 px-6 py-2.5 text-sm font-bold rounded-xl transition-all shadow-sm ${
                                    isDirty 
                                    ? 'bg-white text-slate-900 border border-slate-200 hover:bg-slate-50' 
                                    : 'bg-slate-100 text-slate-400 cursor-not-allowed'
                                }`}
                            >
                                {processing ? <Loader2 size={18} className="animate-spin" /> : <Save size={18} />}
                                Save Reasons
                            </button>
                        )}

                        {!isTerminal && auth.permissions.includes('inventory.stocktake.post') && (
                            <button
                                onClick={handlePost}
                                disabled={processing || isDirty}
                                className={`inline-flex items-center gap-2 px-8 py-2.5 text-sm font-bold rounded-xl transition-all shadow-sm ${
                                    isDirty
                                    ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                                    : 'bg-slate-900 text-white hover:bg-slate-800'
                                }`}
                            >
                                <CheckCircle2 size={18} />
                                Post Stocktake
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Review ${session.stocktake_number}`} />

            <div className="py-8">
                <div className="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8 space-y-6">
                    {/* Summary Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div className="bg-white p-6 rounded-[28px] border border-slate-200 shadow-sm flex items-center gap-4">
                            <div className="w-12 h-12 bg-blue-50 rounded-2xl flex items-center justify-center text-blue-600">
                                <Package size={24} />
                            </div>
                            <div>
                                <p className="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Items</p>
                                <p className="text-xl font-bold text-slate-900">{lines.length}</p>
                            </div>
                        </div>
                        <div className="bg-white p-6 rounded-[28px] border border-slate-200 shadow-sm flex items-center gap-4">
                            <div className="w-12 h-12 bg-amber-50 rounded-2xl flex items-center justify-center text-amber-600">
                                <AlertTriangle size={24} />
                            </div>
                            <div>
                                <p className="text-xs font-bold text-slate-400 uppercase tracking-wider">Lines with Variance</p>
                                <p className="text-xl font-bold text-slate-900">{linesWithVariance.length}</p>
                            </div>
                        </div>
                        <div className="bg-slate-900 p-6 rounded-[28px] text-white shadow-sm flex items-center gap-4">
                            <div className="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center text-white">
                                <CheckCircle2 size={24} />
                            </div>
                            <div>
                                <p className="text-xs font-bold text-slate-400 uppercase tracking-wider text-slate-300">Unresolved Reasons</p>
                                <p className="text-xl font-bold">{linesMissingReasons.length + otherReasonsMissingRemarks.length}</p>
                            </div>
                        </div>
                    </div>

                    {/* Alerts */}
                    {(linesMissingReasons.length > 0 || otherReasonsMissingRemarks.length > 0) && (
                        <div className="p-4 bg-amber-50 border border-amber-200 rounded-2xl flex gap-3 items-center">
                            <AlertCircle className="text-amber-500 shrink-0" size={20} />
                            <div className="text-sm font-medium text-amber-900">
                                {linesMissingReasons.length > 0 && `Reason codes are required for ${linesMissingReasons.length} items with variance. `}
                                {otherReasonsMissingRemarks.length > 0 && `Remarks are required for ${otherReasonsMissingRemarks.length} items using the 'Other' reason code.`}
                            </div>
                        </div>
                    )}

                    <div className="flex items-center justify-between gap-4">
                        <div className="relative flex-1 max-w-md">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                            <input
                                type="text"
                                placeholder="Filter products..."
                                className="w-full pl-10 pr-4 py-2.5 bg-white border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 transition-all"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                            />
                        </div>
                    </div>

                    {/* Review Grid */}
                    <div className="bg-white rounded-[28px] shadow-sm border border-slate-200 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50/50 border-b border-slate-100">
                                        <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest min-w-[250px]">Product Info</th>
                                        <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest text-right w-32">Book Stock</th>
                                        <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest text-right w-32">Physical</th>
                                        <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest text-right w-32">Variance</th>
                                        <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest w-64">Reason Code</th>
                                        <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {filteredLines.map((line) => {
                                        const hasVariance = Math.abs(line.variance_quantity) > 0.0001;
                                        const isMissingReason = hasVariance && !line.reason_code;
                                        const isMissingRemarks = hasVariance && line.reason_code === 'OTHER' && !line.remarks;

                                        return (
                                            <tr key={line.id} className={`hover:bg-slate-50/50 transition-colors ${hasVariance ? 'bg-amber-50/20' : ''}`}>
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-col">
                                                        <span className="text-sm font-bold text-slate-900">{line.product_name}</span>
                                                        <span className="text-[11px] font-mono text-slate-500 mt-1 uppercase">{line.sku}</span>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 text-right">
                                                    <span className="text-sm font-medium text-slate-600">{formatQuantity(line.expected_quantity)}</span>
                                                </td>
                                                <td className="px-6 py-4 text-right">
                                                    <span className="text-sm font-bold text-slate-900">{formatQuantity(line.counted_quantity)}</span>
                                                </td>
                                                <td className="px-6 py-4 text-right">
                                                    <span className={`text-sm font-bold ${
                                                        line.variance_quantity > 0 ? 'text-emerald-600' : 
                                                        (line.variance_quantity < 0 ? 'text-rose-600' : 'text-slate-400')
                                                    }`}>
                                                        {line.variance_quantity > 0 ? '+' : ''}{formatQuantity(line.variance_quantity)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4">
                                                    {hasVariance ? (
                                                        <select
                                                            className={`w-full text-xs font-bold py-1.5 rounded-lg border transition-all ${
                                                                isMissingReason 
                                                                ? 'border-rose-300 bg-rose-50 text-rose-700' 
                                                                : 'border-slate-200 bg-white text-slate-700 focus:ring-2 focus:ring-indigo-500'
                                                            }`}
                                                            value={line.reason_code || ''}
                                                            onChange={(e) => handleReasonChange(line.id, e.target.value)}
                                                        >
                                                            <option value="">Select Reason...</option>
                                                            {Object.entries(reasonCodes).map(([code, label]) => (
                                                                <option key={code} value={code}>{label}</option>
                                                            ))}
                                                        </select>
                                                    ) : (
                                                        <span className="text-xs text-slate-400 italic">No variance</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="relative">
                                                        <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300">
                                                            <MessageSquare size={14} />
                                                        </div>
                                                        <input
                                                            type="text"
                                                            placeholder={hasVariance ? "Mandatory for 'Other'..." : "Add note..."}
                                                            className={`w-full pl-9 pr-4 py-1.5 bg-transparent border-none text-xs focus:ring-0 italic ${
                                                                isMissingRemarks ? 'placeholder-rose-300 text-rose-700' : 'placeholder-slate-300'
                                                            }`}
                                                            value={line.remarks || ''}
                                                            onChange={(e) => handleRemarksChange(line.id, e.target.value)}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            {rejectConfirmOpen && (
                <PremiumDialog
                    isOpen={rejectConfirmOpen}
                    type="danger"
                    title="Reject Stocktake Session"
                    message="Are you sure you want to reject this stocktake? This action is terminal and will send it back to the counting phase."
                    confirmLabel="Reject Session"
                    onConfirm={handleConfirmReject}
                    onCancel={() => setRejectConfirmOpen(false)}
                />
            )}

            {postConfirmOpen && (
                <PremiumDialog
                    isOpen={postConfirmOpen}
                    type="success"
                    title="Post Stocktake & Adjust Inventory"
                    message="Are you sure you want to POST this stocktake? This will atomically update book inventory quantities and generate formal audit ledger movements. This action is irreversible."
                    confirmLabel="Post Stocktake"
                    onConfirm={handleConfirmPost}
                    onCancel={() => setPostConfirmOpen(false)}
                />
            )}
        </AuthenticatedLayout>
    );
}
