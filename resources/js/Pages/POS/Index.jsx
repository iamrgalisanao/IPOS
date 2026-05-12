import React, { useState, useEffect, useMemo } from 'react';
import { Head } from '@inertiajs/react';
import SearchBar from './Components/SearchBar';
import ProductGrid from './Components/ProductGrid';
import Cart from './Components/Cart';
import Receipt from './Components/Receipt';
import SplitPayWizard from './Components/SplitPayWizard';
import FailureGuardianBanner from './Components/FailureGuardianBanner';
import { isCashPayment, calculateCashChange } from './helpers/splitPaymentHelper';
import { ShoppingCart, Package, AlertTriangle } from 'lucide-react';
import { useTransactionStore } from './hooks/useTransactionStore';
import { createUncertainCheckoutError, getCheckoutErrorMessage, getGuardianPresentation, isUncertainCheckoutError } from './helpers/checkoutFailureHelper';

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
    const [paymentRows, setPaymentRows] = useState([]);
    const [lastCashChange, setLastCashChange] = useState(null);
    const [checkoutState, setCheckoutState] = useState('draft');
    const [guardianBanner, setGuardianBanner] = useState(null);
    const [isCheckingStatus, setIsCheckingStatus] = useState(false);
    const [errorMessage, setErrorMessage] = useState(null);

    const { generateUUID, saveDraft, restoreDraftIfSafe, clearDraft } = useTransactionStore();

    // Client request UUID for stable draft identity
    const [clientRequestUuid, setClientRequestUuid] = useState(null);

    const context = useMemo(() => ({
        tenantId: tenant_id,
        branchId: branch_id,
        userId: user_id
    }), [tenant_id, branch_id, user_id]);

    const cartSubtotal = useMemo(() => {
        return cart.reduce((sum, item) => sum + (Number(item.selling_price || item.unit_price || 0) * item.quantity), 0);
    }, [cart]);

    const persistDraftState = (cartState, overrides = {}) => {
        saveDraft(context, {
            items: overrides.items ?? cart,
            totals: overrides.totals ?? { subtotal: cartSubtotal },
            cartState,
            clientRequestUuid: overrides.clientRequestUuid ?? clientRequestUuid,
            activeSale: overrides.activeSale ?? activeSale,
            paymentRows: overrides.paymentRows ?? paymentRows,
            paymentWizardOpen: overrides.paymentWizardOpen ?? showSplitPay,
        });
    };

    const showGuardianBanner = (kind, message, options = {}) => {
        const presentation = getGuardianPresentation(kind);
        const banner = {
            kind,
            tone: presentation.tone,
            title: presentation.title,
            announcement: presentation.announcement,
            message,
        };

        setGuardianBanner(banner);

        if (options.timeoutMs) {
            window.setTimeout(() => {
                setGuardianBanner((current) => current === banner ? null : current);
                if (kind === 'restored') {
                    setCheckoutState((current) => current === 'restored' ? 'draft' : current);
                }
            }, options.timeoutMs);
        }
    };

    const fetchWithTimeout = async (url, options, timeoutMs = 4000) => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), timeoutMs);

        try {
            return await fetch(url, {
                ...options,
                signal: controller.signal,
            });
        } catch (error) {
            if (error?.name === 'AbortError' || error instanceof TypeError) {
                throw createUncertainCheckoutError('The connection dropped before sale confirmation returned.');
            }

            throw error;
        } finally {
            window.clearTimeout(timer);
        }
    };

    const activateConfirmedSale = (saleId, total, message = 'Sale confirmed. Continue to payment.') => {
        setActiveSale({
            id: saleId,
            total: total ?? cartSubtotal.toFixed(4),
        });
        setCheckoutState('confirmed');
        setShowSplitPay(true);
        setSubmissionFailed(false);
        setCheckoutError(null);
        showGuardianBanner('confirmed', message, { timeoutMs: 4000 });
    };

    const checkCheckoutStatus = async (uuid = clientRequestUuid) => {
        if (!uuid) return;

        setIsCheckingStatus(true);
        setCheckoutState('checking');
        showGuardianBanner('checking', 'We are verifying whether the sale was confirmed by the backend.');

        try {
            const response = await fetch('/pos/checkout/status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Tenant-ID': tenant_id,
                    'X-Branch-ID': branch_id,
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify({ client_request_uuid: uuid })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Checkout status could not be verified.');
            }

            if (data.status === 'confirmed') {
                activateConfirmedSale(data.sale_id, data.server_totals?.total, 'Sale confirmed. Continue to payment.');
                return;
            }

            if (data.status === 'retry_available' || data.status === 'not_found') {
                setCheckoutState('retry_available');
                showGuardianBanner('retry_available', 'No confirmed sale was found. Your cart is safe to retry with the same request ID.');
                persistDraftState('retry_available', {
                    clientRequestUuid: uuid,
                    activeSale: null,
                    paymentWizardOpen: false,
                });
                return;
            }

            setCheckoutState('uncertain');
            showGuardianBanner('uncertain', 'The sale status is still uncertain. Please check again before retrying.');
        } catch (error) {
            setCheckoutState('uncertain');
            showGuardianBanner('uncertain', 'We could not verify the sale yet. Keep the cart open and try checking again.');
        } finally {
            setIsCheckingStatus(false);
        }
    };

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
            setPaymentRows(result.draft.payment_rows || []);

            const restoredState = result.draft.cart_state || 'draft';
            const restoredActiveSale = result.draft.active_sale || null;
            const paymentWizardOpen = !!result.draft.payment_wizard_open && !!restoredActiveSale;

            if (paymentWizardOpen) {
                setActiveSale(restoredActiveSale);
                setShowSplitPay(true);
                setCheckoutState('confirmed');
                showGuardianBanner('restored', 'Payment draft restored. Continue where you left off.', { timeoutMs: 4000 });
                return;
            }

            if (restoredState === 'checking') {
                setCheckoutState('uncertain');
                showGuardianBanner('restored', 'Previous checkout restored. Verifying backend truth now.', { timeoutMs: 4000 });
                checkCheckoutStatus(result.draft.client_request_uuid);
                return;
            }

            if (restoredState === 'retry_available') {
                setCheckoutState('retry_available');
                showGuardianBanner('restored', 'Cart restored. This sale is safe to retry.', { timeoutMs: 4000 });
                return;
            }

            setCheckoutState('restored');
            showGuardianBanner('restored', 'Your draft cart was restored.', { timeoutMs: 4000 });
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

    // Persist draft, recovery, and payment-wizard metadata whenever local state changes.
    useEffect(() => {
        if (!clientRequestUuid) return;

        if (cart.length === 0 && !showSplitPay && !activeSale) {
            clearDraft(context);
            return;
        }

        const persistedCartState = showSplitPay && activeSale
            ? 'payment_pending'
            : checkoutState === 'restored' || checkoutState === 'confirmed' || checkoutState === 'failed'
                ? 'draft'
                : checkoutState;

        persistDraftState(persistedCartState);
    }, [cart, cartSubtotal, context, clientRequestUuid, showSplitPay, activeSale, paymentRows, checkoutState]);

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
        setCheckoutState('draft');
        setGuardianBanner(null);
        setCheckoutError(null);
        setSubmissionFailed(false);
        setActiveSale(null);
        setShowSplitPay(false);
        setPaymentRows([]);
        clearDraft(context);
        setClientRequestUuid(generateUUID());
    };

    const handleRetryCheckout = async () => {
        setGuardianBanner(null);
        await handleFinalTap();
    };

    const handleFinalTap = async () => {
        if (cart.length === 0 || isSubmitting || isCheckingStatus) return;

        setIsSubmitting(true);
        setCheckoutError(null);
        setSubmissionFailed(false);
        setGuardianBanner(null);

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
                const message = getCheckoutErrorMessage(validateRes.status, errData);
                setCheckoutState('failed');
                setSubmissionFailed(true);
                setCheckoutError(message);
                showGuardianBanner('failed', message);
                return;
            }

            persistDraftState('checking', {
                activeSale: null,
                paymentWizardOpen: false,
            });
            setCheckoutState('checking');
            showGuardianBanner('checking', 'Completing sale and waiting for backend confirmation.');

            // 2. Create Sale
            let createSaleRes;

            try {
                createSaleRes = await fetchWithTimeout('/pos/checkout/create-sale', {
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
            } catch (error) {
                if (isUncertainCheckoutError(error)) {
                    setCheckoutState('uncertain');
                    showGuardianBanner('uncertain', 'Connection was interrupted before sale confirmation returned. Check status before retrying.');
                    return;
                }

                throw error;
            }

            const saleData = await createSaleRes.json();
            if (!createSaleRes.ok || !(saleData.status === 'created' || saleData.status === 'duplicate_seen')) {
                const message = getCheckoutErrorMessage(createSaleRes.status, saleData);
                setCheckoutState('failed');
                setSubmissionFailed(true);
                setCheckoutError(message);
                showGuardianBanner('failed', message);
                return;
            }

            activateConfirmedSale(saleData.sale_id, saleData.server_totals?.total);
        } catch (err) {
            setCheckoutState('failed');
            setSubmissionFailed(true);
            const message = err.message || 'Checkout failed. Please try again.';
            setCheckoutError(message);
            showGuardianBanner('failed', message);
        } finally {
            setIsSubmitting(false);
        }
    };

    const closeReceipt = () => {
        setReceiptData(null);
        setActiveSale(null);
        setShowSplitPay(false);
        setPaymentRows([]);
        setLastCashChange(null);
        setGuardianBanner(null);
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
            setPaymentRows([]);
            clearCartState();
        } catch (err) {
            console.error('Failed to load final receipt:', err);
            setShowSplitPay(false);
            setPaymentRows([]);
            clearCartState();
        }
    };

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col md:flex-row overflow-hidden relative">
            {/* Non-blocking Toasts */}
            <div className="absolute top-4 left-1/2 -translate-x-1/2 z-50 flex w-[min(90vw,34rem)] flex-col gap-2 pointer-events-none">
                {guardianBanner && (
                    <FailureGuardianBanner
                        kind={guardianBanner.kind}
                        tone={guardianBanner.tone}
                        title={guardianBanner.title}
                        message={guardianBanner.message}
                        announcement={guardianBanner.announcement}
                    />
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
                    checkoutState={checkoutState}
                    isCheckingStatus={isCheckingStatus}
                    onCheckStatus={checkCheckoutStatus}
                    onRetryCheckout={handleRetryCheckout}
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
                    initialRows={paymentRows}
                    onRowsChange={setPaymentRows}
                    onClose={() => {
                        setShowSplitPay(false);
                        setPaymentRows([]);
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
