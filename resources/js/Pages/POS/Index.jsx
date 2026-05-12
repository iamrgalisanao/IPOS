import React, { useState, useEffect, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import SearchBar from './Components/SearchBar';
import ProductGrid from './Components/ProductGrid';
import Cart from './Components/Cart';
import Receipt from './Components/Receipt';
import SplitPayWizard from './Components/SplitPayWizard';
import { isCashPayment, calculateCashChange } from './helpers/splitPaymentHelper';
import { ShoppingCart, Search, LayoutGrid, Package, Info, AlertTriangle, CheckCircle2 } from 'lucide-react';
import { useTransactionStore } from './hooks/useTransactionStore';
import { getCheckoutErrorMessage } from './helpers/checkoutFailureHelper';

export default function Index({ categories, payment_methods, tenant_id, branch_id, user_id }) {
    const [products, setProducts] = useState([]);
    const [cart, setCart] = useState([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedCategory, setSelectedCategory] = useState(null);
    const [loading, setLoading] = useState(false);
    const [mobileCartOpen, setMobileCartOpen] = useState(false);
    const [receiptData, setReceiptData] = useState(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [checkoutError, setCheckoutError] = useState(null);
    const [submissionFailed, setSubmissionFailed] = useState(false);
    const [activeSale, setActiveSale] = useState(null);
    const [showSplitPay, setShowSplitPay] = useState(false);
    const [lastCashChange, setLastCashChange] = useState(null);
    
    // Toast states
    const [restoredMessage, setRestoredMessage] = useState(null);
    const [errorMessage, setErrorMessage] = useState(null);

    const { generateUUID, saveDraft, restoreDraftIfSafe, clearDraft } = useTransactionStore();

    // Client request UUID for stable draft identity
    const [clientRequestUuid, setClientRequestUuid] = useState(null);

    const context = useMemo(() => ({
        tenantId: tenant_id,
        branchId: branch_id,
        userId: user_id
    }), [tenant_id, branch_id, user_id]);

    // Initial load and draft restoration
    useEffect(() => {
        const result = restoreDraftIfSafe(context);
        if (result.success && result.draft && result.draft.items) {
            // Patch older drafts that might have been saved without an `id`
            const patchedItems = result.draft.items.map(item => ({
                ...item,
                id: item.id || item.product_id
            }));
            setCart(patchedItems);
            setClientRequestUuid(result.draft.client_request_uuid);
            setRestoredMessage('Cart Restored - Your draft cart was restored.');
            setTimeout(() => setRestoredMessage(null), 4000);
        } else {
            // New draft gets a new UUID
            setClientRequestUuid(generateUUID());

            if (!result.success && result.reason !== 'no-draft') {
                let msg = 'Saved cart could not be restored.';
                if (result.reason === 'tenant-mismatch') msg = 'Saved cart belongs to a different tenant.';
                if (result.reason === 'branch-mismatch') msg = 'Saved cart belongs to a different branch.';
                if (result.reason === 'user-mismatch') msg = 'Saved cart belongs to a different user.';
                if (result.reason === 'unsupported-schema') msg = 'Saved cart schema is unsupported or corrupted.';
                
                setErrorMessage(msg);
                setTimeout(() => setErrorMessage(null), 5000);
            }
        }
    }, [context]); // Run once when context is stable

    // Save cart state whenever it changes (only when UUID is initialized and NOT submitting)
    useEffect(() => {
        if (!clientRequestUuid || isSubmitting) return;
        
        const subtotal = cart.reduce((sum, item) => sum + (Number(item.selling_price || item.unit_price || 0) * item.quantity), 0);
        saveDraft(context, {
            items: cart,
            totals: { subtotal },
            cartState: 'draft',
            clientRequestUuid: clientRequestUuid
        });
    }, [cart, context, clientRequestUuid, isSubmitting]);

    // Fetch products based on search and category
    const fetchProducts = async (q = '', catId = null) => {
        setLoading(true);
        try {
            const url = new URL('/pos/search', window.location.origin);
            if (q) url.searchParams.append('q', q);
            if (catId) url.searchParams.append('category_id', catId);
            if (tenant_id) url.searchParams.append('test_tenant_id', tenant_id);

            const response = await fetch(url);
            const data = await response.json();
            setProducts(data);
        } catch (error) {
            console.error('Failed to fetch products:', error);
        } finally {
            setLoading(false);
        }
    };

    // Initial fetch and dependency-based fetch
    useEffect(() => {
        const timeoutId = setTimeout(() => {
            fetchProducts(searchQuery, selectedCategory);
        }, 500); // Debounce
        return () => clearTimeout(timeoutId);
    }, [searchQuery, selectedCategory]);

    const addToCart = (product) => {
        if (isSubmitting) return;
        setCart(prev => {
            const pId = product.id || product.product_id;
            const existing = prev.find(item => (item.id || item.product_id) === pId);
            if (existing) {
                return prev.map(item =>
                    (item.id || item.product_id) === pId ? { ...item, quantity: item.quantity + 1 } : item
                );
            }
            return [...prev, { ...product, id: pId, product_id: pId, quantity: 1, unit_price: product.selling_price || product.unit_price }];
        });
    };

    const updateQuantity = (itemId, delta) => {
        if (isSubmitting) return;
        setCart(prev => prev.map(item => {
            if ((item.id || item.product_id) === itemId) {
                const newQty = Math.max(0, item.quantity + delta);
                return { ...item, quantity: newQty };
            }
            return item;
        }).filter(item => item.quantity > 0));
    };

    const clearCartState = () => {
        setCart([]);
        clearDraft(context);
        setClientRequestUuid(generateUUID());
    };

    const handleFinalTap = async () => {
        if (cart.length === 0 || isSubmitting) return;

        setIsSubmitting(true);
        setCheckoutError(null);
        setSubmissionFailed(false);

        try {
            const payload = {
                client_request_uuid: clientRequestUuid,
                items: cart.map(item => ({
                    product_id: item.id || item.product_id,
                    quantity: item.quantity
                }))
            };

            // 1. Validate Draft
            const validateRes = await fetch('/pos/checkout/validate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Tenant-ID': tenant_id,
                    'X-Branch-ID': branch_id,
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify(payload)
            });

            if (!validateRes.ok) {
                const errData = await validateRes.json();
                throw new Error(getCheckoutErrorMessage(validateRes.status, errData));
            }

            // 2. Create Sale
            const createSaleRes = await fetch('/pos/checkout/create-sale', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Tenant-ID': tenant_id,
                    'X-Branch-ID': branch_id,
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify(payload)
            });

            const saleData = await createSaleRes.json();
            if (!createSaleRes.ok || !(saleData.status === 'created' || saleData.status === 'duplicate_seen')) {
                throw new Error(getCheckoutErrorMessage(createSaleRes.status, saleData));
            }

            // 3. Fetch Receipt
            const receiptRes = await fetch(`/pos/sales/${saleData.sale_id}/receipt`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Tenant-ID': tenant_id,
                    'X-Branch-ID': branch_id
                }
            });
            if (!receiptRes.ok) {
                // If sale was created but receipt fetch fails, we still consider it a success for the sale
                // but we should warn the user.
                console.error('Sale created but receipt could not be loaded.');
                clearCartState();
                setIsSubmitting(false);
                setErrorMessage('Sale created but receipt could not be loaded.');
                setTimeout(() => setErrorMessage(null), 5000);
                return;
            }

            const receiptJson = await receiptRes.json();
            
            // Success Flow
            setActiveSale({
                id: saleData.sale_id,
                total: saleData.server_totals.total
            });
            setShowSplitPay(true);
            // clearCartState() will happen after payment or when closing the flow
        } catch (err) {
            setSubmissionFailed(true);
            setCheckoutError(err.message || 'Checkout failed. Please try again.');
        } finally {
            setIsSubmitting(false);
        }
    };

    const closeReceipt = () => {
        setReceiptData(null);
        setActiveSale(null);
        setShowSplitPay(false);
        setLastCashChange(null);
    };

    const handlePaymentRecorded = async (paymentResponse) => {
        // Find total cash tendered and change from the split pay rows for the receipt
        // This is frontend-only for now.
        const cashRow = paymentResponse.rows?.find(r => isCashPayment(payment_methods.find(m => m.id === r.payment_method_id)));
        if (cashRow) {
            setLastCashChange({
                tendered: Number(cashRow.amount_tendered),
                change: calculateCashChange(cashRow)
            });
        }

        // After successful payment, fetch final receipt
        try {
            const receiptRes = await fetch(`/pos/sales/${activeSale.id}/receipt`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Tenant-ID': tenant_id,
                    'X-Branch-ID': branch_id
                }
            });
            if (receiptRes.ok) {
                let receiptJson = await receiptRes.json();
                
                // Inject frontend-only data
                if (cashRow) {
                    receiptJson = {
                        ...receiptJson,
                        cash_tendered: Number(cashRow.amount_tendered),
                        change_due: calculateCashChange(cashRow)
                    };
                }

                setReceiptData(receiptJson);
            }
            setShowSplitPay(false);
            clearCartState();
        } catch (err) {
            console.error('Failed to load final receipt:', err);
            setShowSplitPay(false);
            clearCartState();
        }
    };

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col md:flex-row overflow-hidden relative">
            {/* Non-blocking Toasts */}
            <div className="absolute top-4 left-1/2 -translate-x-1/2 z-50 flex flex-col gap-2 pointer-events-none">
                {restoredMessage && (
                    <div className="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 px-4 py-3 rounded-xl shadow-2xl flex items-center gap-3 backdrop-blur-md animate-in fade-in slide-in-from-top-4">
                        <Info className="w-5 h-5" />
                        <span className="font-medium text-sm">{restoredMessage}</span>
                    </div>
                )}
                {errorMessage && (
                    <div className="bg-rose-500/10 border border-rose-500/20 text-rose-400 px-4 py-3 rounded-xl shadow-2xl flex items-center gap-3 backdrop-blur-md animate-in fade-in slide-in-from-top-4">
                        <AlertTriangle className="w-5 h-5" />
                        <span className="font-medium text-sm">{errorMessage}</span>
                    </div>
                )}
            </div>

            <Head title="POS Terminal" />

            {/* Main Content Area */}
            <main className="flex-1 flex flex-col h-screen overflow-hidden">
                {/* Header / Search Bar Area */}
                <header className="p-4 border-b border-slate-800 bg-slate-900/50 backdrop-blur-md sticky top-0 z-10 shrink-0">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center justify-between">
                        <div className="flex items-center gap-2">
                            <div className="p-2 bg-indigo-600 rounded-lg">
                                <Package className="w-6 h-6 text-white" />
                            </div>
                            <h1 className="text-xl font-bold tracking-tight">Draft Cart</h1>
                        </div>
                        <div className="flex-1 sm:max-w-md">
                            <SearchBar 
                                value={searchQuery} 
                                onChange={setSearchQuery} 
                                onScan={(barcode) => setSearchQuery(barcode)}
                                loading={loading}
                            />
                        </div>
                    </div>

                    {/* Category Filter */}
                    <div className="flex gap-2 mt-4 overflow-x-auto pb-2 scrollbar-hide">
                        <button
                            onClick={() => setSelectedCategory(null)}
                            className={`px-4 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-all ${
                                !selectedCategory 
                                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20' 
                                : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-slate-200'
                            }`}
                        >
                            All
                        </button>
                        {categories.map(cat => (
                            <button
                                key={cat.id}
                                onClick={() => setSelectedCategory(cat.id)}
                                className={`px-4 py-2 rounded-full text-sm font-medium whitespace-nowrap transition-all ${
                                    selectedCategory === cat.id 
                                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/20' 
                                    : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-slate-200'
                                }`}
                            >
                                {cat.name}
                            </button>
                        ))}
                    </div>
                </header>

                <section className="flex-1 overflow-y-auto p-4 scrollbar-thin scrollbar-thumb-slate-800 scrollbar-track-transparent">
                    <ProductGrid 
                        products={products} 
                        loading={loading} 
                        onSelect={addToCart} 
                    />
                </section>

                {/* Mobile Cart Preview Bottom Bar */}
                <div className="md:hidden sticky bottom-0 left-0 right-0 p-3 bg-slate-900 border-t border-slate-800 shadow-[0_-10px_40px_-10px_rgba(0,0,0,0.5)] z-20 shrink-0">
                    <button 
                        onClick={() => setMobileCartOpen(true)}
                        className="w-full bg-indigo-600 text-white rounded-xl py-3.5 px-4 flex items-center justify-between font-medium shadow-lg hover:bg-indigo-500 transition-colors active:scale-[0.98]"
                    >
                        <div className="flex items-center gap-3">
                            <div className="relative">
                                <ShoppingCart className="w-5 h-5" />
                                {cart.length > 0 && (
                                    <span className="absolute -top-2 -right-2 bg-rose-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[1.25rem] text-center">
                                        {cart.length}
                                    </span>
                                )}
                            </div>
                            <span>View Draft Cart</span>
                        </div>
                        <span className="font-bold font-mono tracking-tight text-lg">
                            ₱{(cart.reduce((sum, item) => sum + (Number(item.selling_price || item.unit_price || 0) * item.quantity), 0)).toFixed(2)}
                        </span>
                    </button>
                </div>
            </main>

            {/* Cart Sidebar / Mobile Overlay */}
            <aside className={`
                fixed inset-0 z-50 bg-slate-900 flex flex-col h-screen overflow-hidden shadow-2xl transition-transform duration-300 ease-in-out
                md:relative md:w-[400px] md:z-0 md:border-l md:border-slate-800 md:translate-y-0
                ${mobileCartOpen ? 'translate-y-0' : 'translate-y-full'}
            `}>
                <Cart 
                    items={cart} 
                    onUpdateQuantity={updateQuantity}
                    onClear={clearCartState}
                    onCheckout={handleFinalTap}
                    isSubmitting={isSubmitting}
                    checkoutError={checkoutError}
                    submissionFailed={submissionFailed}
                    onClose={() => setMobileCartOpen(false)}
                />
            </aside>

            {/* Receipt Modal */}
            {receiptData && (
                <Receipt 
                    data={receiptData} 
                    onClose={closeReceipt} 
                />
            )}

            {/* Split Pay Wizard */}
            {showSplitPay && activeSale && (
                <SplitPayWizard 
                    sale={activeSale}
                    paymentMethods={payment_methods}
                    tenantId={tenant_id}
                    branchId={branch_id}
                    onClose={() => {
                        setShowSplitPay(false);
                        // Optional: Clear cart anyway since sale was created? 
                        // Story 4.8 says draft clearing happens after backend sale creation.
                        clearCartState();
                    }}
                    onPaymentRecorded={handlePaymentRecorded}
                />
            )}
        </div>
    );
}
