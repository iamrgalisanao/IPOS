import React, { useState, useEffect, useMemo } from 'react';
import { Head, Link } from '@inertiajs/react';
import SearchBar from './Components/SearchBar';
import ProductGrid from './Components/ProductGrid';
import Cart from './Components/Cart';
import Receipt from './Components/Receipt';
import SplitPayWizard from './Components/SplitPayWizard';
import FailureGuardianBanner from './Components/FailureGuardianBanner';
import { isCashPayment, calculateCashChange } from './helpers/splitPaymentHelper';
import { 
    ShoppingCart, Package, AlertTriangle, ArrowDownCircle, MonitorSmartphone, LayoutGrid, Settings,
    Wine, Cookie, Shirt, Smartphone, Laptop, Pill, Wrench, FolderOpen, Layers, Tag, ChevronRight, X, ArrowLeft, Loader2, Sparkles, Utensils, Coffee, ShoppingBag
} from 'lucide-react';
import { useTransactionStore } from './hooks/useTransactionStore';
import ShiftHUD from '@/Components/Shift/ShiftHUD';
import CloseShiftModal from '@/Components/Shift/CloseShiftModal';
import RecordCashEventModal from '@/Components/Shift/RecordCashEventModal';
import TerminalLockScreen from './Components/TerminalLockScreen';
import { createUncertainCheckoutError, getCheckoutErrorMessage, getGuardianPresentation, isUncertainCheckoutError } from './helpers/checkoutFailureHelper';
import { catalogCache } from '@/POS/offline/catalogCache';
import { validateCheckoutAllowed, isOffline, resolveOfflineCaptureReadiness } from '@/POS/offline/offlineGuards';
import { offlineSalesQueue } from '@/POS/offline/offlineSalesQueue';
import { offlineSyncManager } from '@/POS/offline/offlineSyncManager';
import { useConnectivityStore } from '@/POS/offline/connectivityStore';
import ConnectivityBanner from './Components/ConnectivityBanner';

export default function Index({ categories, payment_methods, tenant_id, branch_id, user_id, is_admin_mode }) {
    const [products, setProducts] = useState([]);
    const [cart, setCart] = useState([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedCategory, setSelectedCategory] = useState(null);
    const [isProductPanelOpen, setIsProductPanelOpen] = useState(false);
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
    const [showCashEvent, setShowCashEvent] = useState(false);
    const [activeShift, setActiveShift] = useState(null);
    const [activeLayout, setActiveLayout] = useState(null);
    const [isLayoutLoading, setIsLayoutLoading] = useState(false);
    const [terminalLocked, setTerminalLocked] = useState(() => {
        return localStorage.getItem(`terminal_locked_${user_id}`) === 'true';
    });
    const [lastSelectedCategory, setLastSelectedCategory] = useState(null);
    const [localCategories, setLocalCategories] = useState(categories || []);
    const [offlineCaptureReadiness, setOfflineCaptureReadiness] = useState({ allowed: false, reason: 'unknown', message: '', machineProfile: null });
    const [offlineQueueSummary, setOfflineQueueSummary] = useState({
        queued: 0,
        syncing: 0,
        accepted: 0,
        duplicate: 0,
        rejected: 0,
        conflict: 0,
        failed: 0,
        cancelled: 0,
        total: 0,
        lastSyncAttemptAt: null,
        lastSuccessfulSyncAt: null,
    });

    const {
        status: connStatus,
        isOnline,
        isOffline: connOffline,
        isChecking,
        isStale,
        lastSyncedAt,
        triggerSync,
        checkConnectivity
    } = useConnectivityStore();

    // Dynamically load categories from cache when sync state updates
    useEffect(() => {
        const loadCategories = async () => {
            try {
                const cached = await catalogCache.getCachedCatalog();
                if (cached && cached.categories && cached.categories.length > 0) {
                    setLocalCategories(cached.categories);
                }
            } catch (err) {
                console.error('Failed to load categories from local cache:', err);
            }
        };
        loadCategories();
    }, [lastSyncedAt]);

    const getCategoryIcon = (name) => {
        const lower = name.toLowerCase();
        if (lower.includes('beverage') || lower.includes('drink') || lower.includes('wine') || lower.includes('beer') || lower.includes('juice') || lower.includes('water')) {
            return <Wine className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
        }
        if (lower.includes('coffee') || lower.includes('tea') || lower.includes('cafe')) {
            return <Coffee className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
        }
        if (lower.includes('snack') || lower.includes('cookie') || lower.includes('chip') || lower.includes('biscuit') || lower.includes('candy')) {
            return <Cookie className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
        }
        if (lower.includes('food') || lower.includes('meal') || lower.includes('dine') || lower.includes('restaurant')) {
            return <Utensils className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
        }
        if (lower.includes('apparel') || lower.includes('cloth') || lower.includes('shirt') || lower.includes('wear') || lower.includes('shoe')) {
            return <Shirt className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
        }
        if (lower.includes('electronics') || lower.includes('phone') || lower.includes('device') || lower.includes('tech')) {
            return <Smartphone className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
        }
        if (lower.includes('med') || lower.includes('pharma') || lower.includes('clinical') || lower.includes('health') || lower.includes('drug')) {
            return <Pill className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
        }
        if (lower.includes('service') || lower.includes('repair') || lower.includes('fix')) {
            return <Wrench className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
        }
        if (lower.includes('bag') || lower.includes('accessories') || lower.includes('pack')) {
            return <ShoppingBag className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
        }
        return <Layers className="w-8 h-8 transition-transform duration-300 group-hover:scale-110" />;
    };

    const handleSelectCategory = (cat) => {
        if (!isProductPanelOpen) {
            setProducts([]); // Clear any previous products instantly to force skeleton load
        }
        setSelectedCategory(cat.id);
        setIsProductPanelOpen(true);
    };

    const handleClosePanel = () => {
        setIsProductPanelOpen(false);
        setTimeout(() => {
            setSelectedCategory(null);
            setSearchQuery('');
            setProducts([]); // Clear products on panel close
        }, 300); // 300ms matches transition-all duration-300
    };

    // Keep panel open if searching
    useEffect(() => {
        if (searchQuery.trim().length > 0) {
            setIsProductPanelOpen(true);
        }
    }, [searchQuery]);

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

    const parseMoneyToCentavos = (value) => {
        const normalized = String(value ?? '0').replace(/[^0-9.-]/g, '');
        if (!normalized || normalized === '-' || normalized === '.') {
            return 0;
        }

        const isNegative = normalized.startsWith('-');
        const [whole = '0', decimal = ''] = normalized.replace('-', '').split('.');
        const centavos = (parseInt(whole || '0', 10) * 100) + parseInt((decimal + '00').slice(0, 2), 10);
        return isNegative ? -centavos : centavos;
    };

    const centavosToDecimalString = (value) => {
        const absolute = Math.abs(value);
        const whole = Math.floor(absolute / 100);
        const cents = String(absolute % 100).padStart(2, '0');
        return `${value < 0 ? '-' : ''}${whole}.${cents}`;
    };

    const buildOfflineCapturePayload = (taxHash) => {
        const items = cart.map((item) => {
            const unitPriceCentavos = parseMoneyToCentavos(item.selling_price || item.unit_price || 0);

            return {
                product_id: item.id || item.product_id,
                quantity: Number(item.quantity),
                unit_price: centavosToDecimalString(unitPriceCentavos),
            };
        });

        const totalCentavos = items.reduce((sum, item) => sum + (parseMoneyToCentavos(item.unit_price) * item.quantity), 0);
        const taxCentavos = Math.round((totalCentavos * 12) / 112);
        const subtotalCentavos = totalCentavos - taxCentavos;

        const clientTotals = {
            subtotal: centavosToDecimalString(subtotalCentavos),
            tax: centavosToDecimalString(taxCentavos),
            total: centavosToDecimalString(totalCentavos),
        };

        return {
            payload: {
                client_request_uuid: clientRequestUuid,
                submitted_at: new Date().toISOString(),
                items,
                client_subtotal: clientTotals.subtotal,
                client_tax_total: clientTotals.tax,
                client_total: clientTotals.total,
                tax_configuration_version_hash: taxHash,
            },
            clientTotals,
        };
    };

    const refreshOfflineState = async () => {
        const [readiness, summary] = await Promise.all([
            resolveOfflineCaptureReadiness(),
            offlineSalesQueue.getStatusSummary(),
        ]);

        setOfflineCaptureReadiness(readiness);
        setOfflineQueueSummary(summary);
    };

    useEffect(() => {
        refreshOfflineState();
        const unsubscribe = offlineSalesQueue.subscribe(() => {
            offlineSalesQueue.getStatusSummary().then(setOfflineQueueSummary).catch((error) => {
                console.error('Failed to refresh offline queue summary:', error);
            });
        });

        return unsubscribe;
    }, [lastSyncedAt, connStatus, isStale]);

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

    // Fetch Active Shift and Layout
    useEffect(() => {
        const fetchShift = async () => {
            try {
                const response = await axios.get(route('pos.active-shift'));
                setActiveShift(response.data);
            } catch (err) {
                console.error("Failed to fetch active shift:", err);
            }
        };

        const fetchLayout = async () => {
            setIsLayoutLoading(true);
            try {
                const response = await axios.get(route('pos.layout'));
                if (response.data && !response.data.fallback) {
                    setActiveLayout(response.data);
                } else {
                    setActiveLayout(null);
                }
            } catch (err) {
                console.error("Failed to fetch POS layout:", err);
                setActiveLayout(null);
            } finally {
                setIsLayoutLoading(false);
            }
        };

        fetchShift();
        fetchLayout();
    }, []);

    const [showCloseShift, setShowCloseShift] = useState(false);

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
            if (isOffline()) {
                const cached = await catalogCache.getCachedCatalog();
                let filtered = cached.products || [];

                if (catId) {
                    filtered = filtered.filter(p => Number(p.category_id) === Number(catId));
                }

                if (q) {
                    const query = q.toLowerCase();
                    filtered = filtered.filter(p => 
                        (p.name && p.name.toLowerCase().includes(query)) ||
                        (p.barcode && p.barcode.toLowerCase().includes(query)) ||
                        (p.sku && p.sku.toLowerCase().includes(query))
                    );
                }

                setProducts(filtered);
            } else {
                const url = new URL('/pos/search', window.location.origin);
                if (q) url.searchParams.append('q', q);
                if (catId) url.searchParams.append('category_id', catId);
                if (tenant_id) url.searchParams.append('test_tenant_id', tenant_id);

                const response = await fetch(url);
                const data = await response.json();
                setProducts(data);
            }
        } catch (error) {
            console.error('Failed to fetch products:', error);
        } finally {
            setLoading(false);
        }
    };

    // Initial fetch and dependency-based fetch with instant filter and debounced search
    useEffect(() => {
        // Clear products immediately only if the panel is NOT already open, to avoid flickering/redrawing
        if (!isProductPanelOpen) {
            setProducts([]);
        }

        if (selectedCategory !== lastSelectedCategory) {
            // Instant category switch - bypass debounce
            setLastSelectedCategory(selectedCategory);
            fetchProducts(searchQuery, selectedCategory);
        } else if (searchQuery) {
            // Debounce keyboard search
            const timeoutId = setTimeout(() => {
                fetchProducts(searchQuery, selectedCategory);
            }, 400);
            return () => clearTimeout(timeoutId);
        } else {
            // Default fetch
            fetchProducts('', selectedCategory);
        }
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

    const handleRetryOfflineSync = async () => {
        await offlineSyncManager.retryFailed();
        await refreshOfflineState();
    };

    const handleFinalTap = async () => {
        if (cart.length === 0 || isSubmitting || isCheckingStatus) return;

        setIsSubmitting(true);
        setCheckoutError(null);
        setSubmissionFailed(false);
        setGuardianBanner(null);

        let taxHash = null;
        try {
            taxHash = await catalogCache.getTaxHash();
        } catch (e) {
            console.error('Failed to get tax hash from cache:', e);
        }

        try {
            const currentlyOffline = isOffline();
            const currentReadiness = currentlyOffline
                ? await resolveOfflineCaptureReadiness()
                : offlineCaptureReadiness;

            await validateCheckoutAllowed();
            if (!currentlyOffline && isStale) {
                throw new Error('Local database configuration is outdated. Please click Sync Now to update your tax configuration before proceeding.');
            }

            if (currentlyOffline) {
                if (!currentReadiness.allowed || !currentReadiness.machineProfile?.offline_sequence_prefix) {
                    throw new Error(currentReadiness.message || 'Controlled offline sales are not available on this terminal.');
                }

                const { payload, clientTotals } = buildOfflineCapturePayload(taxHash);

                await offlineSalesQueue.appendTransaction(payload, clientTotals, {
                    prefix: currentReadiness.machineProfile.offline_sequence_prefix,
                    initialNextValue: currentReadiness.machineProfile.offline_sequence_next_value,
                });

                clearCartState();
                setMobileCartOpen(false);
                showGuardianBanner(
                    'offline_captured',
                    'OFFLINE TRANSACTION CAPTURED. Pending server synchronization and reconciliation. This is not final ledger posting.',
                    { timeoutMs: 5000 }
                );
                return;
            }
        } catch (offlineErr) {
            setIsSubmitting(false);
            setCheckoutState('failed');
            setSubmissionFailed(true);
            setCheckoutError(offlineErr.message);
            showGuardianBanner('failed', offlineErr.message);
            return;
        }

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
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                    ...(taxHash ? { 'X-Tax-Config-Hash': taxHash } : {})
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
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                        ...(taxHash ? { 'X-Tax-Config-Hash': taxHash } : {})
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
        <div className="h-screen bg-slate-950 text-slate-100 flex flex-col overflow-hidden relative">
            <Head title="POS Terminal" />

            <ConnectivityBanner />

            {/* Non-blocking Toasts */}
            <div className="absolute top-20 left-1/2 -translate-x-1/2 z-50 flex w-[min(90vw,34rem)] flex-col gap-2 pointer-events-none">
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

            {/* Top Bar: Shift HUD or Admin Banner */}
            {is_admin_mode ? (
                <div className="bg-gradient-to-r from-indigo-900/40 via-slate-900/40 to-indigo-900/40 border-b border-indigo-500/30 px-6 py-2.5 flex items-center justify-between backdrop-blur-xl shrink-0 z-30">
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-2 bg-indigo-500/20 px-3 py-1 rounded-full border border-indigo-500/30">
                            <MonitorSmartphone className="w-4 h-4 text-indigo-400" />
                            <span className="text-[10px] font-black uppercase tracking-[0.2em] text-indigo-300">Admin Mode</span>
                        </div>
                        <div className="hidden sm:block h-4 w-px bg-slate-700" />
                        <span className="text-xs text-slate-400 font-medium tracking-wide">
                            <span className="text-slate-300">Sandbox:</span> Layout testing & read-only checkout validation
                        </span>
                    </div>
                    
                    <div className="flex items-center gap-3">
                        {activeLayout && (
                            <Link 
                                href={route('admin.pos-layouts.show', activeLayout.layout.id)}
                                className="flex items-center gap-2 px-4 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-bold transition-all shadow-lg shadow-indigo-600/20 active:scale-95"
                            >
                                <LayoutGrid size={14} />
                                Edit Current Layout
                            </Link>
                        )}
                        <Link 
                            href={route('admin.pos-layouts.index')}
                            className="flex items-center gap-2 px-4 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-bold transition-all border border-slate-700"
                        >
                            <Settings size={14} />
                            Exit to Admin
                        </Link>
                    </div>
                </div>
            ) : (
                <ShiftHUD 
                    shift={activeShift} 
                    onRecordEvent={() => setShowCashEvent(true)}
                    onCloseShift={() => setShowCloseShift(true)}
                    onLockTerminal={() => {
                        localStorage.setItem(`terminal_locked_${user_id}`, 'true');
                        setTerminalLocked(true);
                    }}
                />
            )}

            <div className="flex-1 flex flex-col md:flex-row overflow-hidden min-h-0">
                {/* Product Catalog Column */}
                <div className="flex-1 flex flex-col overflow-hidden min-h-0 relative">
                    {/* Header / Search Bar Area */}
                    <header className="p-4 border-b border-slate-800 bg-slate-900/50 backdrop-blur-md sticky top-0 z-10 shrink-0">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center justify-between">
                            <div className="flex flex-col gap-1">
                                <div className="flex items-center gap-2">
                                    <div className="p-2 bg-indigo-600 rounded-lg">
                                        <LayoutGrid className="w-6 h-6 text-white" />
                                    </div>
                                    <h1 className="text-xl font-bold tracking-tight">Abbadev IPOS Terminal</h1>
                                    
                                    {/* Cache Status Badge */}
                                    <div className="flex items-center gap-1.5 ml-2">
                                        <span className={`w-2 h-2 rounded-full ${
                                            connOffline ? 'bg-amber-500 animate-pulse' :
                                            isChecking ? 'bg-indigo-500 animate-pulse' :
                                            isStale ? 'bg-rose-500 animate-pulse' :
                                            lastSyncedAt ? 'bg-emerald-500' : 'bg-slate-500'
                                        }`} />
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                            {connOffline ? 'Offline (Cached Mode)' :
                                             isChecking ? 'Syncing...' :
                                             isStale ? 'Cache Outdated' :
                                             lastSyncedAt ? 'Cache Sync\'d' : 'Cache Empty'}
                                        </span>
                                    </div>
                                </div>
                                {lastSyncedAt && (
                                    <p className="text-[10px] text-slate-500 ml-12">
                                        Last synchronized: {new Date(lastSyncedAt).toLocaleTimeString()} ({isStale ? 'Expired' : 'Active'})
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-3 flex-1 sm:max-w-md">
                                <SearchBar 
                                    value={searchQuery} 
                                    onChange={setSearchQuery} 
                                    onScan={(barcode) => setSearchQuery(barcode)}
                                    loading={loading}
                                />
                            </div>
                        </div>
                    </header>

                    {/* Main Categories Grid */}
                    <section className="flex-1 overflow-y-auto p-6 scrollbar-thin scrollbar-thumb-slate-800 scrollbar-track-transparent">
                        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-6">
                            {localCategories.map((cat) => (
                                <button
                                    key={cat.id}
                                    onClick={() => handleSelectCategory(cat)}
                                    className="group flex flex-col items-center justify-between p-6 rounded-2xl bg-slate-900 border border-slate-800 hover:border-indigo-500/50 hover:bg-slate-900/80 hover:shadow-[0_0_30px_rgba(99,102,241,0.15)] transition-all duration-300 transform active:scale-95 text-center h-44 relative overflow-hidden"
                                >
                                    <div className="absolute inset-0 bg-gradient-to-br from-indigo-500/5 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500" />
                                    
                                    <div className="w-16 h-16 rounded-2xl bg-slate-800/80 group-hover:bg-indigo-600/10 group-hover:text-indigo-400 text-slate-400 flex items-center justify-center transition-all duration-300 shrink-0 border border-slate-700/50 group-hover:border-indigo-500/30">
                                        {getCategoryIcon(cat.name)}
                                    </div>
                                    
                                    <div className="flex-1 flex flex-col justify-center mt-4">
                                        <span className="font-bold text-sm text-slate-200 group-hover:text-white tracking-wide transition-colors leading-tight">
                                            {cat.name}
                                        </span>
                                        <span className="text-[10px] text-slate-500 group-hover:text-indigo-400/80 font-semibold uppercase tracking-widest mt-1">
                                            Explore Items
                                        </span>
                                    </div>
                                </button>
                            ))}
                        </div>
                    </section>

                    {/* Sliding Product Selection Panel (Glassmorphic half-screen overlay) */}
                    <div 
                        className={`absolute inset-y-0 right-0 w-full md:w-1/2 z-30 flex flex-col bg-slate-950/45 backdrop-blur-2xl border-l border-white/10 ring-1 ring-white/5 shadow-[0_0_80px_rgba(0,0,0,0.9)] transition-all duration-300 ease-in-out transform ${
                            isProductPanelOpen 
                            ? 'translate-x-0 opacity-100' 
                            : 'translate-x-full opacity-0 pointer-events-none'
                        }`}
                    >
                        {/* Panel Header */}
                        <div className="flex items-center justify-between p-4 border-b border-white/5 bg-slate-950/20 backdrop-blur-xl sticky top-0 z-10 shrink-0">
                            <div className="flex items-center gap-3">
                                <button
                                    onClick={handleClosePanel}
                                    className="p-2 hover:bg-slate-800/40 text-slate-400 hover:text-white rounded-xl transition-all flex items-center justify-center border border-transparent hover:border-white/10 active:scale-95"
                                >
                                    <ArrowLeft className="w-5 h-5" />
                                </button>
                                <div>
                                    <h2 className="text-lg font-bold tracking-tight text-white flex items-center gap-2">
                                        {searchQuery && !selectedCategory
                                            ? `Search results for "${searchQuery}"`
                                            : localCategories.find(c => c.id === selectedCategory)?.name || 'Products'
                                        }
                                        {searchQuery && selectedCategory && (
                                            <span className="text-xs bg-indigo-500/20 text-indigo-400 px-2 py-0.5 rounded-full border border-indigo-500/30">
                                                Search Active
                                            </span>
                                        )}
                                    </h2>
                                    <p className="text-xs text-slate-400">
                                        {products.length} {products.length === 1 ? 'item' : 'items'} available
                                    </p>
                                </div>
                            </div>
                            <button
                                onClick={handleClosePanel}
                                className="px-5 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold transition-all shadow-lg shadow-indigo-600/20 active:scale-95"
                            >
                                Done
                            </button>
                        </div>

                        {/* Panel Product Grid Content */}
                        <div className="flex-1 overflow-y-auto p-6 scrollbar-thin scrollbar-thumb-slate-800 scrollbar-track-transparent relative">
                            {loading && products.length > 0 && (
                                <div className="absolute top-4 right-4 z-20 bg-slate-950/60 backdrop-blur-md border border-white/10 rounded-full p-2 text-indigo-400 animate-spin shadow-lg">
                                    <Loader2 className="w-4 h-4" />
                                </div>
                            )}
                            <ProductGrid 
                                products={products} 
                                loading={loading} 
                                onSelect={addToCart} 
                                activeLayout={activeLayout}
                                isSearchActive={searchQuery.length > 0 || selectedCategory !== null}
                                cart={cart}
                                gridColsClass="grid-cols-1 sm:grid-cols-2 lg:grid-cols-3"
                            />
                        </div>
                    </div>

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
                </div>

                {/* Cart Sidebar / Mobile Overlay */}
                <aside className={`
                    fixed inset-0 z-50 bg-slate-900 flex flex-col h-screen overflow-hidden shadow-2xl transition-transform duration-300 ease-in-out
                    md:relative md:w-[400px] md:h-full md:z-0 md:border-l md:border-slate-800 md:translate-y-0
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
                        isOffline={connOffline}
                        isStale={isStale}
                        offlineCaptureAllowed={connOffline && offlineCaptureReadiness.allowed}
                        offlineQueueSummary={offlineQueueSummary}
                        onRetryOfflineSync={handleRetryOfflineSync}
                    />
                </aside>
            </div>

            {/* Receipt Modal */}
            {receiptData && (
                <Receipt 
                    data={receiptData} 
                    tenantId={tenant_id}
                    branchId={branch_id}
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
                    shift={activeShift}
                    isAdmin={is_admin_mode}
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

            {/* Cash Drawer Event Modal */}
            {!is_admin_mode && (
                <RecordCashEventModal
                    show={showCashEvent}
                    onClose={() => setShowCashEvent(false)}
                    shift={activeShift}
                />
            )}
            
            {/* Close Shift Modal */}
            {!is_admin_mode && activeShift && showCloseShift && (
                <CloseShiftModal
                    onClose={() => setShowCloseShift(false)}
                    shift={activeShift}
                />
            )}

            {/* Terminal Lock Screen Overlay */}
            {terminalLocked && activeShift && (
                <TerminalLockScreen
                    cashierName={activeShift.cashier_name || 'Cashier'}
                    onUnlock={() => {
                        localStorage.removeItem(`terminal_locked_${user_id}`);
                        setTerminalLocked(false);
                    }}
                />
            )}
        </div>
    );
}
