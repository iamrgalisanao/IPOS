import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import PremiumDialog from '@/Components/PremiumDialog';
import { 
    ArrowLeft, 
    Play, 
    Save, 
    CheckCircle2, 
    AlertCircle, 
    Search,
    Package,
    History,
    MessageSquare,
    ClipboardList,
    ChevronRight,
    Loader2,
    XCircle,
    Printer,
    Download,
    Plus,
    PlusCircle
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

export default function Show({ auth, session, lines, isBlindCount }) {
    const [searchTerm, setSearchTerm] = useState('');
    const [localLines, setLocalLines] = useState(lines);
    const [isDirty, setIsDirty] = useState(false);
    const [reviewConfirmOpen, setReviewConfirmOpen] = useState(false);
    const [cancelConfirmOpen, setCancelConfirmOpen] = useState(false);
    
    // Catalog Add State
    const [showAddModal, setShowAddModal] = useState(false);
    const [catalogSearch, setCatalogSearch] = useState('');
    const [catalogResults, setCatalogResults] = useState([]);
    const [isSearchingCatalog, setIsSearchingCatalog] = useState(false);

    const { data, setData, put, post, processing } = useForm({
        lines: [],
        product_id: '',
    });

    const isTerminal = session.status === 'posted' || session.status === 'cancelled' || session.status === 'rejected';

    const filteredLines = localLines.filter(line => 
        line.product_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        line.sku.toLowerCase().includes(searchTerm.toLowerCase())
    );

    const handleCountChange = (lineId, value) => {
        if (isTerminal || session.status !== 'counting') return;
        
        const val = value === '' ? 0 : parseFloat(value);
        setLocalLines(prev => prev.map(line => {
            if (line.id === lineId) {
                const newLine = { ...line, counted_quantity: val };
                if (!isBlindCount) {
                    newLine.variance_quantity = val - line.expected_quantity;
                }
                return newLine;
            }
            return line;
        }));
        setIsDirty(true);
    };

    const handleRemarksChange = (lineId, remarks) => {
        if (isTerminal || session.status !== 'counting') return;
        
        setLocalLines(prev => prev.map(line => 
            line.id === lineId ? { ...line, remarks } : line
        ));
        setIsDirty(true);
    };

    const handleSave = () => {
        const changedLines = localLines.map(l => ({
            id: l.id,
            counted_quantity: l.counted_quantity,
            remarks: l.remarks
        }));
        
        router.put(route('inventory.stocktakes.lines.update', session.id), {
            lines: changedLines
        }, {
            onSuccess: () => {
                setIsDirty(false);
            },
            preserveScroll: true
        });
    };

    const handleSubmitForReview = () => {
        if (isDirty) {
            setReviewConfirmOpen(true);
        } else {
            router.post(route('inventory.stocktakes.submit', session.id));
        }
    };

    const handleConfirmReview = () => {
        setReviewConfirmOpen(false);
        handleSave();
        router.post(route('inventory.stocktakes.submit', session.id));
    };

    const handleStartCounting = () => {
        router.post(route('inventory.stocktakes.start-counting', session.id));
    };

    const handleCancel = () => {
        setCancelConfirmOpen(true);
    };

    const handleConfirmCancel = () => {
        setCancelConfirmOpen(false);
        router.post(route('inventory.stocktakes.cancel', session.id));
    };

    const searchMasterCatalog = async (query) => {
        setCatalogSearch(query);
        if (query.length < 2) {
            setCatalogResults([]);
            return;
        }

        setIsSearchingCatalog(true);
        try {
            const response = await fetch(route('inventory.stocktakes.catalog.search', { q: query }));
            const results = await response.json();
            setCatalogResults(results);
        } catch (error) {
            console.error('Catalog search failed', error);
        } finally {
            setIsSearchingCatalog(false);
        }
    };

    const handleAddProduct = (productId) => {
        router.post(route('inventory.stocktakes.add-line', session.id), {
            product_id: productId
        }, {
            onSuccess: () => {
                setShowAddModal(false);
                setCatalogSearch('');
                setCatalogResults([]);
            },
            preserveScroll: true
        });
    };

    const formatQuantity = (val) => {
        return parseFloat(val || 0).toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 4
        });
    };

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
                                <h2 className="text-2xl font-bold leading-tight text-slate-900">{session.stocktake_number}</h2>
                                <StatusBadge status={session.status} />
                            </div>
                            <div className="flex items-center gap-4 text-xs text-slate-500 font-medium">
                                <div className="flex items-center gap-1">
                                    <Package size={12} className="text-slate-400" />
                                    {lines.length} Products
                                </div>
                                <div className="flex items-center gap-1">
                                    <History size={12} className="text-slate-400" />
                                    Started by {session.started_by_user?.name}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        {auth.permissions.includes('inventory.stocktake.view') && (
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
                        )}

                        {(auth.permissions.includes('inventory.stocktake.review') || 
                          auth.permissions.includes('inventory.stocktake.post') || 
                          auth.permissions.includes('inventory.stocktake.approve')) && (
                            <a
                                href={route('inventory.stocktakes.export.variance-csv', session.id)}
                                className="inline-flex items-center gap-2 px-4 py-2.5 text-slate-600 text-sm font-bold rounded-xl hover:bg-slate-100 transition-all"
                            >
                                <Download size={18} />
                                Export CSV
                            </a>
                        )}

                        {!isTerminal && auth.permissions.includes('inventory.stocktake.cancel') && (
                            <button
                                onClick={handleCancel}
                                disabled={processing}
                                className="inline-flex items-center gap-2 px-4 py-2.5 text-slate-600 text-sm font-bold rounded-xl hover:bg-rose-50 hover:text-rose-600 transition-all"
                            >
                                <XCircle size={18} />
                                Cancel Session
                            </button>
                        )}

                        {session.status === 'draft' && auth.permissions.includes('inventory.stocktake.count') && (
                            <button
                                onClick={handleStartCounting}
                                className="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-bold rounded-xl hover:bg-indigo-700 transition-all shadow-sm"
                            >
                                <Play size={18} fill="currentColor" />
                                Start Counting
                            </button>
                        )}
                        
                        {session.status === 'counting' && auth.permissions.includes('inventory.stocktake.count') && (
                            <>
                                {isDirty && (
                                    <span className="text-xs font-bold text-amber-600 animate-pulse flex items-center gap-1.5 mr-2">
                                        <AlertCircle size={14} />
                                        Unsaved changes
                                    </span>
                                )}
                                <button
                                    onClick={handleSave}
                                    disabled={!isDirty || processing}
                                    className={`inline-flex items-center gap-2 px-6 py-2.5 text-sm font-bold rounded-xl transition-all shadow-sm ${
                                        isDirty 
                                        ? 'bg-white text-slate-900 border border-slate-200 hover:bg-slate-50' 
                                        : 'bg-slate-100 text-slate-400 cursor-not-allowed'
                                    }`}
                                >
                                    {processing ? <Loader2 size={18} className="animate-spin" /> : <Save size={18} />}
                                    Save Progress
                                </button>

                                <button
                                    onClick={handleSubmitForReview}
                                    disabled={processing}
                                    className="inline-flex items-center gap-2 px-6 py-2.5 bg-slate-900 text-white text-sm font-bold rounded-xl hover:bg-slate-800 transition-all shadow-sm"
                                >
                                    <CheckCircle2 size={18} />
                                    Submit for Review
                                </button>
                            </>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`${session.stocktake_number} - Stocktake`} />

            <div className="py-8">
                <div className="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8 space-y-6">
                    {session.status === 'draft' ? (
                        <div className="bg-white rounded-[28px] border border-slate-200 p-12 text-center space-y-6 max-w-2xl mx-auto mt-12">
                            <div className="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto">
                                <ClipboardList size={40} className="text-slate-300" />
                            </div>
                            <div className="space-y-2">
                                <h3 className="text-xl font-bold text-slate-900">Session in Draft State</h3>
                                <p className="text-slate-500 text-sm leading-relaxed">
                                    This session has been initialized but counting has not yet begun. 
                                    Click <strong>Start Counting</strong> to snapshot current inventory levels and begin the physical count.
                                </p>
                            </div>
                            <button
                                onClick={handleStartCounting}
                                className="inline-flex items-center gap-2 px-8 py-3 bg-indigo-600 text-white font-bold rounded-2xl hover:bg-indigo-700 transition-all shadow-md shadow-indigo-100"
                            >
                                <Play size={20} fill="currentColor" />
                                Initialize & Start Count
                            </button>
                        </div>
                    ) : (
                        <>
                            {/* Search and Filters */}
                            <div className="flex items-center justify-between gap-4">
                                <div className="flex items-center gap-3 flex-1 max-w-2xl">
                                    <div className="relative flex-1">
                                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                                        <input
                                            type="text"
                                            placeholder="Search products in this session..."
                                            className="w-full pl-10 pr-4 py-2.5 bg-white border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 transition-all"
                                            value={searchTerm}
                                            onChange={(e) => setSearchTerm(e.target.value)}
                                        />
                                    </div>
                                    
                                    {session.status === 'counting' && (
                                        <button
                                            onClick={() => setShowAddModal(true)}
                                            className="inline-flex items-center gap-2 px-4 py-2.5 bg-slate-900 text-white text-sm font-bold rounded-xl hover:bg-slate-800 transition-all shadow-sm"
                                        >
                                            <Plus size={18} />
                                            Add Missing Product
                                        </button>
                                    )}
                                </div>
                                {isBlindCount && (
                                    <div className="px-4 py-2 bg-slate-100 border border-slate-200 rounded-xl flex items-center gap-2">
                                        <AlertCircle size={16} className="text-slate-500" />
                                        <span className="text-xs font-bold text-slate-600">Blind Count Mode Active</span>
                                    </div>
                                )}
                            </div>

                            {/* Counting Grid */}
                            <div className="bg-white rounded-[28px] shadow-sm border border-slate-200 overflow-hidden">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left border-collapse">
                                        <thead>
                                            <tr className="bg-slate-50/50 border-b border-slate-100">
                                                <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest min-w-[300px]">Product Info</th>
                                                {!isBlindCount && (
                                                    <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest text-right w-32">Book Stock</th>
                                                )}
                                                <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest text-center w-48">Physical Count</th>
                                                {!isBlindCount && (
                                                    <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest text-right w-32">Variance</th>
                                                )}
                                                <th className="px-6 py-4 text-[11px] font-bold text-slate-500 uppercase tracking-widest">Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-50">
                                            {filteredLines.map((line) => {
                                                const hasVariance = !isBlindCount && Math.abs(line.variance_quantity) > 0.0001;
                                                const isTerminal = session.status === 'posted' || session.status === 'cancelled' || session.status === 'rejected';

                                                return (
                                                    <tr key={line.id} className="hover:bg-slate-50/50 transition-colors group">
                                                        <td className="px-6 py-4">
                                                            <div className="flex flex-col">
                                                                <span className="text-sm font-bold text-slate-900 leading-tight">{line.product_name}</span>
                                                                <span className="text-[11px] font-mono text-slate-500 mt-1 uppercase tracking-tight">{line.sku}</span>
                                                            </div>
                                                        </td>
                                                        {!isBlindCount && (
                                                            <td className="px-6 py-4 text-right">
                                                                <span className="text-sm font-medium text-slate-600">
                                                                    {formatQuantity(line.expected_quantity)}
                                                                </span>
                                                            </td>
                                                        )}
                                                        <td className="px-6 py-4">
                                                            <div className="flex items-center justify-center">
                                                                <input
                                                                    type="number"
                                                                    step="any"
                                                                    disabled={session.status !== 'counting'}
                                                                    className={`w-32 px-3 py-2 text-center text-sm font-bold rounded-lg border transition-all ${
                                                                        session.status !== 'counting'
                                                                        ? 'bg-slate-50 border-slate-200 text-slate-400'
                                                                        : 'bg-slate-50 border-slate-200 focus:bg-white focus:ring-2 focus:ring-indigo-500'
                                                                    }`}
                                                                    value={line.counted_quantity ?? ''}
                                                                    onChange={(e) => handleCountChange(line.id, e.target.value)}
                                                                />
                                                            </div>
                                                        </td>
                                                        {!isBlindCount && (
                                                            <td className="px-6 py-4 text-right">
                                                                <span className={`text-sm font-bold ${
                                                                    line.variance_quantity > 0 ? 'text-emerald-600' : 
                                                                    (line.variance_quantity < 0 ? 'text-rose-600' : 'text-slate-400')
                                                                }`}>
                                                                    {line.variance_quantity > 0 ? '+' : ''}{formatQuantity(line.variance_quantity)}
                                                                </span>
                                                            </td>
                                                        )}
                                                        <td className="px-6 py-4">
                                                            <div className="relative group/remarks">
                                                                <div className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300">
                                                                    <MessageSquare size={14} />
                                                                </div>
                                                                <input
                                                                    type="text"
                                                                    disabled={session.status !== 'counting'}
                                                                    placeholder="Add note..."
                                                                    className="w-full pl-9 pr-4 py-2 bg-transparent border-none text-xs focus:ring-0 placeholder-slate-300 italic"
                                                                    value={line.remarks || ''}
                                                                    onChange={(e) => handleRemarksChange(line.id, e.target.value)}
                                                                />
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}

                                            {filteredLines.length === 0 && (
                                                <tr>
                                                    <td colSpan={isBlindCount ? 3 : 5} className="px-6 py-12 text-center">
                                                        <div className="text-slate-400 text-sm italic">No matching products found...</div>
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>

            {/* Add Product Modal */}
            {showAddModal && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
                    <div className="bg-white w-full max-w-lg rounded-[32px] shadow-2xl overflow-hidden border border-slate-200 animate-in fade-in zoom-in duration-200">
                        <div className="p-8 space-y-6">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <h3 className="text-xl font-extrabold text-slate-900">Add Missing Product</h3>
                                    <p className="text-sm text-slate-500 font-medium">Search the master catalog to pull into this session.</p>
                                </div>
                                <button 
                                    onClick={() => setShowAddModal(false)}
                                    className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition-all"
                                >
                                    <XCircle size={24} />
                                </button>
                            </div>

                            <div className="relative">
                                <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={20} />
                                <input
                                    autoFocus
                                    type="text"
                                    placeholder="Type SKU or Product Name..."
                                    className="w-full pl-12 pr-4 py-4 bg-slate-50 border-none rounded-2xl text-base font-bold focus:ring-2 focus:ring-indigo-500 transition-all"
                                    value={catalogSearch}
                                    onChange={(e) => searchMasterCatalog(e.target.value)}
                                />
                                {isSearchingCatalog && (
                                    <div className="absolute right-4 top-1/2 -translate-y-1/2">
                                        <Loader2 size={20} className="animate-spin text-indigo-500" />
                                    </div>
                                )}
                            </div>

                            <div className="space-y-2 max-h-[300px] overflow-y-auto pr-2 custom-scrollbar">
                                {catalogResults.length > 0 ? (
                                    catalogResults.map((product) => {
                                        const alreadyInSession = lines.some(l => l.product_id === product.id);
                                        return (
                                            <button
                                                key={product.id}
                                                disabled={alreadyInSession}
                                                onClick={() => handleAddProduct(product.id)}
                                                className={`w-full flex items-center justify-between p-4 rounded-2xl border transition-all text-left ${
                                                    alreadyInSession 
                                                    ? 'bg-slate-50 border-slate-100 opacity-60 cursor-not-allowed'
                                                    : 'bg-white border-slate-100 hover:border-indigo-200 hover:bg-indigo-50/30 group'
                                                }`}
                                            >
                                                <div className="flex items-center gap-3">
                                                    <div className={`p-2 rounded-xl ${alreadyInSession ? 'bg-slate-200' : 'bg-slate-100 group-hover:bg-indigo-100'} transition-colors`}>
                                                        <Package size={20} className={alreadyInSession ? 'text-slate-400' : 'text-slate-500 group-hover:text-indigo-600'} />
                                                    </div>
                                                    <div>
                                                        <p className="font-bold text-slate-900 leading-none">{product.name}</p>
                                                        <p className="text-xs font-mono text-slate-500 mt-1 uppercase">{product.sku}</p>
                                                    </div>
                                                </div>
                                                {alreadyInSession ? (
                                                    <span className="text-[10px] font-black uppercase tracking-widest text-slate-400">In Session</span>
                                                ) : (
                                                    <PlusCircle size={20} className="text-slate-300 group-hover:text-indigo-500" />
                                                )}
                                            </button>
                                        );
                                    })
                                ) : catalogSearch.length >= 2 ? (
                                    <div className="p-8 text-center space-y-3">
                                        <div className="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mx-auto">
                                            <Search size={24} className="text-slate-300" />
                                        </div>
                                        <p className="text-sm text-slate-500 font-medium italic">No products found matching "{catalogSearch}"</p>
                                    </div>
                                ) : (
                                    <div className="p-8 text-center text-slate-400 text-sm italic font-medium">
                                        Start typing to search the master catalog...
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}
            {reviewConfirmOpen && (
                <PremiumDialog
                    isOpen={reviewConfirmOpen}
                    type="info"
                    title="Unsaved Changes"
                    message="You have unsaved stocktake changes. Would you like to save them now and submit this stocktake session for review?"
                    confirmLabel="Save & Submit"
                    onConfirm={handleConfirmReview}
                    onCancel={() => setReviewConfirmOpen(false)}
                />
            )}

            {cancelConfirmOpen && (
                <PremiumDialog
                    isOpen={cancelConfirmOpen}
                    type="danger"
                    title="Cancel Stocktake Session"
                    message="Are you sure you want to CANCEL this stocktake session? This action is terminal, cannot be undone, and will void this count."
                    confirmLabel="Cancel Session"
                    onConfirm={handleConfirmCancel}
                    onCancel={() => setCancelConfirmOpen(false)}
                />
            )}
        </AuthenticatedLayout>
    );
}
