import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import SearchBar from './Components/SearchBar';
import ProductGrid from './Components/ProductGrid';
import Cart from './Components/Cart';
import Receipt from './Components/Receipt';
import SplitPayWizard from './Components/SplitPayWizard';
import FailureGuardianBanner from './Components/FailureGuardianBanner';
import { isCashPayment, calculateCashChange } from './helpers/splitPaymentHelper';
import {
    ShoppingCart, Package, AlertTriangle, ArrowDownCircle, MonitorSmartphone, LayoutGrid, Settings, Plus, Clock,
    Wine, Cookie, Shirt, Smartphone, Laptop, Pill, Wrench, FolderOpen, Layers, Tag, ChevronRight, X, ArrowLeft, Loader2, Sparkles, Utensils, Coffee, ShoppingBag, LogIn, RefreshCw
} from 'lucide-react';
import { useTransactionStore } from './hooks/useTransactionStore';
import ShiftHUD from '@/Components/Shift/ShiftHUD';
import CloseShiftModal from '@/Components/Shift/CloseShiftModal';
import RecordCashEventModal from '@/Components/Shift/RecordCashEventModal';
import SpotAuditModal from '@/Components/Shift/SpotAuditModal';
import TerminalLockScreen from './Components/TerminalLockScreen';
import SpecialDiscountModal from './Components/SpecialDiscountModal';
import { createUncertainCheckoutError, getCheckoutErrorMessage, getGuardianPresentation, getPosAccessIssue, isUncertainCheckoutError } from './helpers/checkoutFailureHelper';
import { catalogCache, filterCachedProducts } from '@/POS/offline/catalogCache';
import { validateCheckoutAllowed, isOffline, resolveOfflineCaptureReadiness } from '@/POS/offline/offlineGuards';
import { offlineSalesQueue } from '@/POS/offline/offlineSalesQueue';
import { offlineSyncManager } from '@/POS/offline/offlineSyncManager';
import { offlinePaymentQueue } from '@/POS/offline/offlinePaymentQueue';
import { buildPaymentMethodSnapshot } from '@/POS/offline/offlinePaymentPolicy';
import { buildShiftAuthoritySnapshot, validateShiftAuthoritySnapshot } from '@/POS/offline/offlineShiftAuthority';
import { buildOfflineAcknowledgmentPrintEvent } from '@/POS/offline/offlineDocumentPolicy';
import { useConnectivityStore } from '@/POS/offline/connectivityStore';
import ConnectivityBanner from './Components/ConnectivityBanner';
import StaleLayoutBanner from './Components/StaleLayoutBanner';
import ActivationModal from './Components/ActivationModal';

export default function Index({ categories, initial_products, payment_methods, discount_types = [], tenant_id, branch_id, terminal_id, user_id, is_admin_mode }) {
    const activeShiftCacheKey = `ipos_active_shift_${tenant_id || 'tenant'}_${branch_id || 'branch'}_${user_id || 'user'}`;
    const readCachedActiveShift = () => {
        if (typeof window === 'undefined') return null;

        try {
            const cached = localStorage.getItem(activeShiftCacheKey);
            if (!cached) return null;

            const parsed = JSON.parse(cached);
            return parsed && parsed.id ? parsed : null;
        } catch (err) {
            console.error('Failed to read cached active shift:', err);
            return null;
        }
    };

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
    const [posAccessIssue, setPosAccessIssue] = useState(null);
    const [showCashEvent, setShowCashEvent] = useState(false);
    const [showSpotAudit, setShowSpotAudit] = useState(false);
    const [showSpecialDiscount, setShowSpecialDiscount] = useState(false);
    const [appliedStatutoryDiscount, setAppliedStatutoryDiscount] = useState(null);
    const [activeShift, setActiveShift] = useState(() => readCachedActiveShift());
    const [timecardStatus, setTimecardStatus] = useState({ clocked_in: false, clocked_in_at: null });
    const [activeLayout, setActiveLayout] = useState(null);
    const [isLayoutLoading, setIsLayoutLoading] = useState(false);
    // Layout drift: set to true when heartbeat response reports server layout has changed
    const [layoutDrift, setLayoutDrift] = useState(false);
    const [layoutDriftName, setLayoutDriftName] = useState(null);
    const [layoutDriftDismissed, setLayoutDriftDismissed] = useState(false);
    const [terminalLocked, setTerminalLocked] = useState(() => {
        return localStorage.getItem(`terminal_locked_${user_id}`) === 'true';
    });
    const [configBannerDismissed, setConfigBannerDismissed] = useState(false);
    const [shiftDuration, setShiftDuration] = useState('');

    useEffect(() => {
        if (!activeShift?.opened_at) return;

        const timer = setInterval(() => {
            const start = new Date(activeShift.opened_at);
            const now = new Date();
            const diff = Math.floor((now - start) / 1000);

            const hours = Math.floor(diff / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;

            setShiftDuration(
                `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
            );
        }, 1000);

        return () => clearInterval(timer);
    }, [activeShift?.opened_at]);
    const [lastSelectedCategory, setLastSelectedCategory] = useState(null);
    const [localCategories, setLocalCategories] = useState(categories || []);
    const [offlineInitialProducts] = useState(initial_products || []);
    const productSearchServerUnavailableUntil = useRef(0);
    const [catalogHash, setCatalogHash] = useState(null);
    const [offlineCaptureReadiness, setOfflineCaptureReadiness] = useState({ allowed: false, reason: 'unknown', message: '', machineProfile: null });
    const [offlineQueueSummary, setOfflineQueueSummary] = useState({
        pending: 0,
        syncing: 0,
        synced: 0,
        conflict: 0,
        accepted_with_warning: 0,
        failed: 0,
        cancelled: 0,
        total: 0,
        lastSyncAttemptAt: null,
        lastSuccessfulSyncAt: null,
    });
    const persistedTerminalId = typeof window !== 'undefined'
        ? localStorage.getItem('ipos_sales_machine_profile_id')
        : null;
    const terminalHeaderId = offlineCaptureReadiness?.machineProfile?.id || terminal_id || persistedTerminalId || null;
    const discountTypes = Array.isArray(discount_types) ? discount_types : [];

    const buildPosHeaders = (taxHash = null, extra = {}) => ({
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Tenant-ID': tenant_id,
        'X-Branch-ID': branch_id,
        ...(terminalHeaderId ? { 'X-Terminal-ID': terminalHeaderId } : {}),
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        ...(taxHash ? { 'X-Tax-Config-Hash': taxHash } : {}),
        ...extra,
    });

    const isExpectedStartupFetchFailure = (err) => {
        const status = err?.response?.status;
        return status === 401
            || status === 419
            || err?.message === 'Network Error'
            || err?.code === 'ERR_NETWORK'
            || (err?.request && !err?.response);
    };

    const applyPosAccessIssue = (status, data = {}, source = null) => {
        const issue = getPosAccessIssue(status, data);
        if (!issue) return false;

        setPosAccessIssue({
            ...issue,
            source,
            timestamp: Date.now(),
        });

        return true;
    };

    const clearPosAccessIssue = () => {
        setPosAccessIssue(null);
    };

    const captureRequestAccessIssue = (err, source = null) => {
        const status = err?.response?.status || err?.status;
        const data = err?.response?.data || err?.data || {};
        return applyPosAccessIssue(status, data, source);
    };

    const readResponseJsonSafely = async (response) => {
        try {
            return await response.clone().json();
        } catch {
            return {};
        }
    };

    const {
        status: connStatus,
        isOnline,
        isOffline: connOffline,
        isChecking,
        isStale,
        lastSyncedAt,
        terminalContextInvalid,
        triggerSync,
        refreshConfiguration,
        checkConnectivity
    } = useConnectivityStore();

    const reviewCount = Number(offlineQueueSummary.conflict || 0) + Number(offlineQueueSummary.accepted_with_warning || 0);
    const lastSyncedTime = lastSyncedAt ? new Date(lastSyncedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }).toLowerCase() : 'never';

    const getCheckoutAlertState = () => {
        if (posAccessIssue?.code === 'TERMINAL_CONTEXT_INVALID' || terminalContextInvalid) {
            return {
                type: 'terminal_invalid',
                severity: 'error',
                title: 'Terminal activation required',
                message: 'This device is not bound to an active POS terminal. Activate this terminal before selling.',
                primaryAction: 'activate_terminal'
            };
        }

        if (!activeShift) {
            return {
                type: 'shift_required',
                severity: 'warning',
                title: 'Open Shift to Start Selling',
                message: 'Cashier sales are disabled until a shift is opened.',
                primaryAction: 'open_shift'
            };
        }

        // We treat isStale as a blocking configuration refresh
        if (isStale && !configBannerDismissed) {
            return {
                type: 'config_blocking',
                severity: 'warning',
                title: 'Checkout paused: configuration refresh required',
                message: 'This terminal has outdated tax or payment rules. Refresh before checkout.',
                primaryAction: 'refresh_config'
            };
        }

        if (reviewCount > 0 && !configBannerDismissed) {
            return {
                type: 'offline_review',
                severity: 'warning',
                title: 'Offline transactions need admin review',
                message: `${reviewCount} offline sale${reviewCount === 1 ? '' : 's'} require review before posting.`,
                primaryAction: 'review_queue'
            };
        }

        if (layoutDrift && !layoutDriftDismissed) {
            return {
                type: 'layout_update',
                severity: 'info',
                title: 'Updated POS layout available',
                message: 'Reload the product grid without clearing the cart.',
                primaryAction: 'reload_layout'
            };
        }

        return null;
    };

    const checkoutAlert = getCheckoutAlertState();

    const [isReloadingLayout, setIsReloadingLayout] = useState(false);
    const handleReloadLayout = async () => {
        if (isReloadingLayout) return;
        setIsReloadingLayout(true);
        try {
            const response = await axios.get(route('pos.layout'));
            if (response.data && !response.data.fallback) {
                try {
                    const { catalogCache: cache } = await import('@/POS/offline/catalogCache');
                    await cache.updateLayoutVersionHash(response.data.layout_version_hash ?? null);
                } catch (cacheErr) {
                    console.warn('Failed to persist refreshed layout hash to IndexedDB:', cacheErr);
                }
                setActiveLayout(response.data);
                setLayoutDrift(false);
                setLayoutDriftName(null);
            }
        } catch (err) {
            console.error('Failed to reload POS layout:', err);
        } finally {
            setIsReloadingLayout(false);
        }
    };

    const showLayoutEditControls = Boolean(is_admin_mode);

    const handleRefreshConfig = async () => {
        try {
            const result = await refreshConfiguration?.();
            if (!result || result.status === 'success') {
                window.location.reload();
            }
        } catch (err) {
            console.error('Failed to refresh config:', err);
        }
    };

    // Dynamically load categories from cache when sync state updates
    useEffect(() => {
        const loadCategories = async () => {
            try {
                const cached = await catalogCache.getCachedCatalog();
                if (cached) {
                    if (cached.categories && cached.categories.length > 0) {
                        setLocalCategories(cached.categories);
                    }
                    setCatalogHash(cached.catalog_version_hash);
                }
            } catch (err) {
                console.error('Failed to load categories from local cache:', err);
            }
        };
        loadCategories();
    }, [lastSyncedAt]);

    useEffect(() => {
        axios.defaults.headers.common['X-Tenant-ID'] = tenant_id;
        axios.defaults.headers.common['X-Branch-ID'] = branch_id;

        const deviceId = localStorage.getItem('ipos_device_id');
        if (deviceId) {
            axios.defaults.headers.common['X-Device-ID'] = deviceId;
        } else {
            delete axios.defaults.headers.common['X-Device-ID'];
        }

        if (terminalHeaderId) {
            axios.defaults.headers.common['X-Terminal-ID'] = terminalHeaderId;
        } else {
            delete axios.defaults.headers.common['X-Terminal-ID'];
        }

        if (connStatus === 'checking') {
            void checkConnectivity().catch(() => undefined);
        }

        if (!isOnline) {
            return;
        }

        const fetchTimecardStatus = async () => {
            try {
                const response = await axios.get(route('pos.timecard.status'));
                if (response.data.success) {
                    clearPosAccessIssue();
                    setTimecardStatus({
                        clocked_in: response.data.clocked_in,
                        clocked_in_at: response.data.clocked_in_at,
                        timecard_id: response.data.timecard_id
                    });
                }
            } catch (err) {
                if (captureRequestAccessIssue(err, 'Timecard status')) {
                    return;
                }

                if (isExpectedStartupFetchFailure(err)) {
                    console.info('Timecard status unavailable; using cached/offline terminal state.');
                    return;
                }

                console.error('Failed to fetch timecard status:', err);
            }
        };
        fetchTimecardStatus();
    }, [tenant_id, branch_id, terminalHeaderId, isOnline, connStatus]);

    useEffect(() => {
        if (offlineCaptureReadiness?.machineProfile?.id) {
            axios.defaults.headers.common['X-Terminal-ID'] = offlineCaptureReadiness.machineProfile.id;
        }
    }, [offlineCaptureReadiness?.machineProfile]);

    useEffect(() => {
        if (terminalContextInvalid) {
            setPosAccessIssue({
                code: 'TERMINAL_CONTEXT_INVALID',
                tone: 'red',
                title: 'Terminal Context Not Verified',
                message: 'This terminal context is invalid or revoked. Please activate the terminal to continue.',
                actionLabel: 'Activate Terminal',
                action: 'activate_terminal',
            });
        }
    }, [terminalContextInvalid]);

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
        if (!activeShift && !is_admin_mode) return;
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
    const cartActiveLineCount = useMemo(() => {
        return cart.filter((item) => Number(item.quantity || 0) > 0).length;
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

    const formatStockQuantity = (value) => {
        const quantity = Number(value);
        if (!Number.isFinite(quantity)) return '0';
        return Number.isInteger(quantity) ? String(quantity) : quantity.toFixed(2).replace(/\.?0+$/, '');
    };

    const getProductSaleBlockMessage = (product, requestedQuantity = 1) => {
        if (!product?.is_inventory_tracked) {
            return null;
        }

        const currentStock = Number(product.current_stock ?? 0);
        const availableToSell = Number(product.available_to_sell ?? currentStock);
        const productName = product.display_name || product.name || 'This product';

        if (product.stock_state === 'expired') {
            return `${productName} is expired and blocked from sale.`;
        }

        if (product.stock_state === 'out_of_stock' || availableToSell <= 0 || product.stock_available === false) {
            return `${productName} is out of stock.`;
        }

        if (requestedQuantity > availableToSell) {
            return `Only ${formatStockQuantity(availableToSell)} ${productName} available to sell.`;
        }

        return null;
    };

    const showDraftValidationMessage = (message) => {
        setCheckoutError(message);
        setSubmissionFailed(false);
        setGuardianBanner(null);
    };

    const refreshDraftRequestIdentity = () => {
        const nextUuid = generateUUID();
        setClientRequestUuid(nextUuid);
        setCheckoutState('draft');
        setGuardianBanner(null);
        return nextUuid;
    };

    const buildOfflineCapturePayload = (taxHash, rowsOverride = null) => {
        let deviceId = localStorage.getItem('ipos_device_id');
        if (!deviceId) {
            deviceId = 'DEV-' + Math.random().toString(36).substring(2, 15);
            localStorage.setItem('ipos_device_id', deviceId);
        }

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

        const cashPaymentMethod = payment_methods.find((method) => isCashPayment(method));
        if (!cashPaymentMethod) {
            throw new Error('Offline cash capture requires an active Cash payment method. Reconnect or configure Cash before continuing.');
        }

        const capturedAt = new Date().toISOString();
        const shiftAuthority = buildShiftAuthoritySnapshot(activeShift, capturedAt);
        const shiftValidation = validateShiftAuthoritySnapshot(shiftAuthority, new Date(capturedAt));

        if (!shiftValidation.allowed) {
            throw new Error('Offline capture requires an open shift with valid cached shift authorization. Reconnect before continuing.');
        }

        const sourcePaymentRows = Array.isArray(rowsOverride) && rowsOverride.length > 0
            ? rowsOverride
            : [{
                payment_method_id: cashPaymentMethod.id,
                amount: clientTotals.total,
                reference_number: null,
            }];

        const payments = sourcePaymentRows.map((row) => {
            const method = payment_methods.find((paymentMethod) => paymentMethod.id === row.payment_method_id);
            if (!isCashPayment(method)) {
                throw new Error('Offline capture only supports cash payments. Reconnect for card, e-wallet, bank, or other payment methods.');
            }

            const methodSnapshot = buildPaymentMethodSnapshot(method || cashPaymentMethod);
            const amountCentavos = parseMoneyToCentavos(row.amount || clientTotals.total);

            return {
                payment_method_id: row.payment_method_id || cashPaymentMethod.id,
                amount: centavosToDecimalString(amountCentavos),
                amount_centavos: amountCentavos,
                reference_number: null,
                ...methodSnapshot,
            };
        });

        const paymentSnapshot = buildPaymentMethodSnapshot(cashPaymentMethod);

        const payload = {
            schema_version: 2,
            tenant_id,
            branch_id,
            terminal_id: offlineCaptureReadiness.machineProfile?.id || null,
            device_id: deviceId,
            ...shiftAuthority,
            timecard_id: timecardStatus?.timecard_id || null,
            local_transaction_reference: '', // will be set by appendTransaction
            local_receipt_number: '',        // will be set by appendTransaction
            business_date: activeShift?.business_date || new Date().toISOString().split('T')[0],
            terminal_timestamp: capturedAt,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            sales_machine_profile_id: offlineCaptureReadiness.machineProfile?.id || null,
            catalog_version_hash: catalogHash || null,
            tax_configuration_version_hash: taxHash,
            cart_snapshot: { items: cart },
            payment_method: 'cash',
            ...paymentSnapshot,
            payments,
            gross_amount_centavos: totalCentavos,
            discount_total_centavos: 0,
            taxable_amount_centavos: subtotalCentavos,
            tax_amount_centavos: taxCentavos,
            net_amount_centavos: totalCentavos,
            payload_hash: '',                // will be set by appendTransaction
            sync_status: 'pending',
            sync_attempt_count: 0,
            last_sync_attempt_at: null,
            cash_status: 'collected',
            offline_document_events: [
                buildOfflineAcknowledgmentPrintEvent({
                    client_request_uuid: clientRequestUuid,
                    amount: clientTotals.total,
                    captured_at: capturedAt,
                    terminal_id: offlineCaptureReadiness.machineProfile?.id || null,
                    cashier_id: user_id,
                }),
            ],

            // Legacy/envelope support
            client_request_uuid: clientRequestUuid,
            submitted_at: capturedAt,
            items,
            client_subtotal: clientTotals.subtotal,
            client_tax_total: clientTotals.tax,
            client_total: clientTotals.total,
        };

        return {
            payload,
            clientTotals,
        };
    };

    const buildCombinedOfflineQueueSummary = async () => {
        const [salesSummary, paymentSummary] = await Promise.all([
            offlineSalesQueue.getStatusSummary(),
            Promise.resolve(offlinePaymentQueue.getStatusSummary()),
        ]);

        return {
            ...salesSummary,
            pending: Number(salesSummary.pending || 0) + Number(paymentSummary.pending || 0),
            syncing: Number(salesSummary.syncing || 0) + Number(paymentSummary.syncing || 0),
            failed: Number(salesSummary.failed || 0) + Number(paymentSummary.failed || 0),
            total: Number(salesSummary.total || 0) + Number(paymentSummary.total || 0),
        };
    };

    const refreshOfflineState = async () => {
        const [readiness, summary] = await Promise.all([
            resolveOfflineCaptureReadiness(),
            buildCombinedOfflineQueueSummary(),
        ]);

        setOfflineCaptureReadiness(readiness);
        setOfflineQueueSummary(summary);
    };

    useEffect(() => {
        refreshOfflineState();
        const refreshSummary = () => {
            buildCombinedOfflineQueueSummary().then(setOfflineQueueSummary).catch((error) => {
                console.error('Failed to refresh offline queue summary:', error);
            });
        };
        const unsubscribeSales = offlineSalesQueue.subscribe(refreshSummary);
        const unsubscribePayments = offlinePaymentQueue.subscribe(refreshSummary);

        return () => {
            unsubscribeSales();
            unsubscribePayments();
        };
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
                credentials: 'same-origin',
                headers: buildPosHeaders(),
                body: JSON.stringify({ client_request_uuid: uuid })
            });

            const data = await response.json();

            if (!response.ok) {
                applyPosAccessIssue(response.status, data, 'Checkout status');
                throw new Error(data.message || 'Checkout status could not be verified.');
            }

            clearPosAccessIssue();

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
            setPaymentRows(result.draft.payment_rows || []);

            const restoredState = result.draft.cart_state || 'draft';
            const restoredActiveSale = result.draft.active_sale || null;
            const paymentWizardOpen = !!result.draft.payment_wizard_open && !!restoredActiveSale;

            if (paymentWizardOpen) {
                setClientRequestUuid(result.draft.client_request_uuid);
                setActiveSale(restoredActiveSale);
                setShowSplitPay(true);
                setCheckoutState('confirmed');
                showGuardianBanner('restored', 'Payment draft restored. Continue where you left off.', { timeoutMs: 4000 });
                return;
            }

            if (restoredState === 'checking') {
                setClientRequestUuid(result.draft.client_request_uuid);
                setCheckoutState('uncertain');
                showGuardianBanner('restored', 'Previous checkout restored. Verifying backend truth now.', { timeoutMs: 4000 });
                checkCheckoutStatus(result.draft.client_request_uuid);
                return;
            }

            if (restoredState === 'retry_available') {
                setClientRequestUuid(result.draft.client_request_uuid);
                setCheckoutState('retry_available');
                showGuardianBanner('restored', 'Cart restored. This sale is safe to retry.', { timeoutMs: 4000 });
                return;
            }

            setClientRequestUuid(generateUUID());
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
        if (!isOnline) {
            setActiveShift((currentShift) => currentShift || readCachedActiveShift());
            setIsLayoutLoading(false);
            return;
        }

        const fetchShift = async () => {
            try {
                const response = await axios.get(route('pos.active-shift'));
                const shift = response.data?.id ? response.data : null;
                clearPosAccessIssue();
                setActiveShift(shift);

                if (shift) {
                    localStorage.setItem(activeShiftCacheKey, JSON.stringify(shift));
                } else {
                    localStorage.removeItem(activeShiftCacheKey);
                }
            } catch (err) {
                captureRequestAccessIssue(err, 'Active shift');
                if (!isExpectedStartupFetchFailure(err)) {
                    console.error("Failed to fetch active shift:", err);
                } else {
                    console.info('Active shift unavailable; using cached/offline shift state.');
                }
                setActiveShift((currentShift) => currentShift || readCachedActiveShift());
            }
        };

        const fetchLayout = async () => {
            setIsLayoutLoading(true);
            try {
                const response = await axios.get(route('pos.layout'));
                clearPosAccessIssue();
                if (response.data && !response.data.fallback) {
                    setActiveLayout(response.data);
                } else {
                    setActiveLayout(null);
                }
            } catch (err) {
                captureRequestAccessIssue(err, 'POS layout');
                if (!isExpectedStartupFetchFailure(err)) {
                    console.error("Failed to fetch POS layout:", err);
                } else {
                    console.info('POS layout unavailable; using default cached/offline layout.');
                }
                setActiveLayout(null);
            } finally {
                setIsLayoutLoading(false);
            }
        };

        fetchShift();
        fetchLayout();
    }, [activeShiftCacheKey, isOnline]);

    const [localSyncStatus, setLocalSyncStatus] = useState({ status: 'offline', broker: null });
    const [selectedTable, setSelectedTable] = useState('none');
    const [tableLockError, setTableLockError] = useState(null);

    useEffect(() => {
        const resolveLocalSync = async () => {
            if (!isOnline || !offlineCaptureReadiness.machineProfile) return;

            try {
                // Try to discover master
                const discoverRes = await axios.get(route('pos.local-sync.broker.discover'));
                if (discoverRes.data && discoverRes.data.success) {
                    const broker = discoverRes.data.data;
                    if (broker.master_profile_id === offlineCaptureReadiness.machineProfile.id) {
                        setLocalSyncStatus({ status: 'master', broker });
                    } else {
                        setLocalSyncStatus({ status: 'slave', broker });
                    }
                    return;
                }

                if (discoverRes.data?.code === 'BROKER_NOT_FOUND') {
                    const registerRes = await axios.post(route('pos.local-sync.broker.register'), {
                        sales_machine_profile_id: offlineCaptureReadiness.machineProfile.id,
                        local_ip_address: window.location.hostname === 'localhost' ? '127.0.0.1' : window.location.hostname,
                        local_port: parseInt(window.location.port || '80', 10),
                    });
                    if (registerRes.data && registerRes.data.success) {
                        setLocalSyncStatus({ status: 'master', broker: registerRes.data.data });
                    }
                }
            } catch (err) {
                if (err.response?.status === 404 && err.response?.data?.code === 'BROKER_NOT_FOUND') {
                    try {
                        const registerRes = await axios.post(route('pos.local-sync.broker.register'), {
                            sales_machine_profile_id: offlineCaptureReadiness.machineProfile.id,
                            local_ip_address: window.location.hostname === 'localhost' ? '127.0.0.1' : window.location.hostname,
                            local_port: parseInt(window.location.port || '80', 10),
                        });
                        if (registerRes.data && registerRes.data.success) {
                            setLocalSyncStatus({ status: 'master', broker: registerRes.data.data });
                        }
                    } catch (regErr) {
                        if (isExpectedStartupFetchFailure(regErr)) {
                            console.info('Local sync broker registration unavailable; using offline local sync state.');
                            setLocalSyncStatus({ status: 'offline', broker: null });
                        } else {
                            console.error("Failed to register local sync broker:", regErr);
                            setLocalSyncStatus({ status: 'offline', broker: null });
                        }
                    }
                } else if (isExpectedStartupFetchFailure(err)) {
                    console.info('Local sync broker unavailable; using offline local sync state.');
                    setLocalSyncStatus({ status: 'offline', broker: null });
                } else {
                    console.error("Failed to discover local sync broker:", err);
                    setLocalSyncStatus({ status: 'offline', broker: null });
                }
            }
        };

        resolveLocalSync();

        // Send heartbeat and check for layout drift every 2 minutes
        const sendHeartbeat = async () => {
            if (!isOnline || !offlineCaptureReadiness.machineProfile) return;
            try {
                const { catalogCache } = await import('@/POS/offline/catalogCache');
                const cached = await catalogCache.getConfigSnapshotMetadata();
                const clientSnapshot = {
                    ...(cached?.config_snapshot || {}),
                    layout_version_hash: cached?.layout_version_hash ?? cached?.config_snapshot?.layout_version_hash ?? null,
                    catalog_version_hash: cached?.catalog_version_hash ?? cached?.config_snapshot?.catalog_version_hash ?? null,
                    tax_configuration_version_hash: cached?.tax_configuration_version_hash ?? cached?.config_snapshot?.tax_configuration_version_hash ?? null,
                    discount_rules_version_hash: cached?.discount_rules_version_hash ?? cached?.config_snapshot?.discount_rules_version_hash ?? null,
                    payment_methods_version_hash: cached?.payment_methods_version_hash ?? cached?.config_snapshot?.payment_methods_version_hash ?? null,
                    terminal_policy_version_hash: cached?.terminal_policy_version_hash ?? cached?.config_snapshot?.terminal_policy_version_hash ?? null,
                    printer_profile_version_hash: cached?.printer_profile_version_hash ?? cached?.config_snapshot?.printer_profile_version_hash ?? null,
                    config_snapshot_hash: cached?.config_snapshot_hash ?? cached?.config_snapshot?.config_snapshot_hash ?? null,
                };

                const hbRes = await axios.post('/api/pos/heartbeat', {
                    app_version: window.__IPOS_VERSION__ ?? null,
                    device_id: offlineCaptureReadiness.machineProfile?.activated_device_id ?? null,
                    queue_count: offlineQueueSummary.pending + offlineQueueSummary.failed,
                    connection_state: isOnline ? 'online' : 'offline',
                    reported_at: new Date().toISOString(),
                    config_snapshot: clientSnapshot,
                }, {
                    headers: buildPosHeaders(),
                });

                if (hbRes.data?.layout_drift === true) {
                    setLayoutDrift(true);
                    setLayoutDriftName(hbRes.data?.server_layout_name ?? null);
                    setLayoutDriftDismissed(false);
                } else if (hbRes.data?.layout_drift === false) {
                    // Heartbeat confirmed we are in sync — clear any stale drift state
                    setLayoutDrift(false);
                }
            } catch (err) {
                if (!isExpectedStartupFetchFailure(err)) {
                    console.warn('Heartbeat failed:', err);
                }
            }
        };

        sendHeartbeat();
        const interval = setInterval(() => {
            resolveLocalSync();
            sendHeartbeat();
        }, 120000);

        return () => clearInterval(interval);
    }, [offlineCaptureReadiness.machineProfile, isOnline]);

    const handleSelectTable = async (tableId) => {
        if (!offlineCaptureReadiness.machineProfile) return;

        if (tableId === 'none') {
            if (selectedTable !== 'none') {
                try {
                    await axios.post(route('pos.local-sync.table.unlock'), {
                        table_id: selectedTable,
                        sales_machine_profile_id: offlineCaptureReadiness.machineProfile.id
                    });
                } catch (err) {
                    console.error("Failed to release lock:", err);
                }
            }
            setSelectedTable('none');
            return;
        }

        try {
            await axios.post(route('pos.local-sync.table.lock'), {
                table_id: tableId,
                sales_machine_profile_id: offlineCaptureReadiness.machineProfile.id
            });

            if (selectedTable !== 'none' && selectedTable !== tableId) {
                try {
                    await axios.post(route('pos.local-sync.table.unlock'), {
                        table_id: selectedTable,
                        sales_machine_profile_id: offlineCaptureReadiness.machineProfile.id
                    });
                } catch (err) {
                    console.error("Failed to release old lock:", err);
                }
            }

            setSelectedTable(tableId);
            setTableLockError(null);
        } catch (err) {
            if (err.response?.status === 409) {
                setTableLockError(`Table ${tableId} is locked by another terminal.`);
            } else {
                setTableLockError("Failed to lock table.");
            }
            setTimeout(() => setTableLockError(null), 5000);
        }
    };

    const [showCloseShift, setShowCloseShift] = useState(false);

    // A completed local capture must never leave the empty cart in a submitting state.
    useEffect(() => {
        if (cartActiveLineCount === 0 && !showSplitPay && !activeSale && isSubmitting) {
            setIsSubmitting(false);
        }
    }, [cartActiveLineCount, showSplitPay, activeSale, isSubmitting]);

    // Persist draft, recovery, and payment-wizard metadata whenever local state changes.
    useEffect(() => {
        if (!clientRequestUuid) return;

        if (cartActiveLineCount === 0 && !showSplitPay && !activeSale) {
            if (isSubmitting) {
                setIsSubmitting(false);
            }
            clearDraft(context);
            return;
        }

        const persistedCartState = showSplitPay && activeSale
            ? 'payment_pending'
            : checkoutState === 'restored' || checkoutState === 'confirmed' || checkoutState === 'failed'
                ? 'draft'
                : checkoutState;

        persistDraftState(persistedCartState);
    }, [cart, cartActiveLineCount, cartSubtotal, context, clientRequestUuid, showSplitPay, activeSale, paymentRows, checkoutState, isSubmitting]);

    // Prevent accidental reload/navigation if there is an active draft or in-flight transaction
    useEffect(() => {
        const handleBeforeUnload = (e) => {
            const hasActiveDraft = cartActiveLineCount > 0;
            const isInFlight = checkoutState === 'checking' || (cartActiveLineCount > 0 && isSubmitting) || isCheckingStatus;
            const isPaymentPending = showSplitPay && activeSale;

            if (hasActiveDraft || isInFlight || isPaymentPending) {
                e.preventDefault();
                e.returnValue = ''; // Standard way to trigger browser warning
                return '';
            }
        };

        window.addEventListener('beforeunload', handleBeforeUnload);
        return () => window.removeEventListener('beforeunload', handleBeforeUnload);
    }, [cartActiveLineCount, checkoutState, isSubmitting, isCheckingStatus, showSplitPay, activeSale]);

    // Fetch products based on search and category
    const fetchProducts = async (q = '', catId = null) => {
        const category = catId
            ? localCategories.find((item) => String(item.id) === String(catId)) || catId
            : null;
        const productsFromOfflineSnapshot = (cached) => {
            const cachedProducts = cached.products || [];
            return cachedProducts.length > 0 ? cachedProducts : offlineInitialProducts;
        };
        const loadCachedProducts = async () => {
            const cached = await catalogCache.getCachedCatalog();
            setProducts(filterCachedProducts(productsFromOfflineSnapshot(cached), q, category));
        };
        const shouldUseCachedSearch = !isOnline
            || connOffline
            || isOffline()
            || Date.now() < productSearchServerUnavailableUntil.current;

        setLoading(true);
        try {
            if (shouldUseCachedSearch) {
                await loadCachedProducts();
            } else {
                const url = new URL('/pos/search', window.location.origin);
                if (q) url.searchParams.append('q', q);
                if (catId) url.searchParams.append('category_id', catId);
                if (tenant_id) url.searchParams.append('test_tenant_id', tenant_id);

                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: buildPosHeaders(),
                });
                if (!response.ok) {
                    const errorData = await readResponseJsonSafely(response);
                    applyPosAccessIssue(response.status, errorData, 'Product catalog');
                    const error = new Error(`Server returned ${response.status}`);
                    error.status = response.status;
                    error.data = errorData;
                    throw error;
                }

                const contentType = response.headers.get('content-type') || '';
                if (!contentType.toLowerCase().includes('application/json')) {
                    const error = new Error('Product search returned a non-JSON response');
                    error.status = response.status;
                    error.contentType = contentType;
                    throw error;
                }

                const data = await response.json();
                clearPosAccessIssue();
                setProducts(data);
            }
        } catch (error) {
            const isReachabilityFailure = error instanceof TypeError
                || error?.status === 401
                || error?.status === 419
                || error?.contentType
                || /failed to fetch|network/i.test(String(error?.message || ''));
            if (isReachabilityFailure) {
                productSearchServerUnavailableUntil.current = Date.now() + 10000;
                checkConnectivity?.().catch(() => {});
                console.info('Product search server unreachable. Loading products from offline cache.');
            } else {
                console.error('Failed to fetch products:', error);
            }

            // Fallback to IndexedDB cache when server is unreachable
            try {
                await loadCachedProducts();
                console.info('Loaded products from offline cache fallback');
            } catch (cacheErr) {
                console.error('Offline cache fallback also failed:', cacheErr);
                setProducts(filterCachedProducts(offlineInitialProducts, q, category));
            }
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
    }, [searchQuery, selectedCategory, isOnline, lastSyncedAt, localCategories]);

    const addToCart = (product) => {
        if (!activeShift && !is_admin_mode) return;
        if (isSubmitting && cartActiveLineCount > 0) return;
        if (isSubmitting) {
            setIsSubmitting(false);
        }
        const pId = product.id || product.product_id;
        const existing = cart.find(item => (item.id || item.product_id) === pId);
        const requestedQuantity = (existing?.quantity || 0) + 1;
        const blockMessage = getProductSaleBlockMessage(product, requestedQuantity);

        if (blockMessage) {
            showDraftValidationMessage(blockMessage);
            return;
        }

        setCheckoutError(null);
        setSubmissionFailed(false);
        refreshDraftRequestIdentity();
        setCart(prev => {
            if (existing) {
                return prev.map(item =>
                    (item.id || item.product_id) === pId ? { ...item, quantity: item.quantity + 1 } : item
                );
            }
            return [...prev, { ...product, id: pId, product_id: pId, quantity: 1, unit_price: product.selling_price || product.unit_price }];
        });
    };

    const updateQuantity = (itemId, delta) => {
        if (!activeShift && !is_admin_mode) return;
        if (isSubmitting && cartActiveLineCount > 0) return;
        if (isSubmitting) {
            setIsSubmitting(false);
        }
        const existing = cart.find(item => (item.id || item.product_id) === itemId);
        const requestedQuantity = existing ? Math.max(0, existing.quantity + delta) : 0;
        const blockMessage = delta > 0 && existing ? getProductSaleBlockMessage(existing, requestedQuantity) : null;

        if (blockMessage) {
            showDraftValidationMessage(blockMessage);
            return;
        }

        if (delta > 0) {
            setCheckoutError(null);
            setSubmissionFailed(false);
        }

        refreshDraftRequestIdentity();
        setCart(prev => prev.map(item => {
            if ((item.id || item.product_id) === itemId) {
                const newQty = Math.max(0, item.quantity + delta);
                return { ...item, quantity: newQty };
            }
            return item;
        }).filter(item => item.quantity > 0));
    };

    const clearCartState = () => {
        setIsSubmitting(false);
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
        if (!activeShift && !is_admin_mode) return;
        setGuardianBanner(null);
        await handleFinalTap();
    };

    const handleRetryOfflineSync = async () => {
        await offlineSyncManager.retryFailed();
        await refreshOfflineState();
    };

    const handleFinalTap = async () => {
        if (!activeShift && !is_admin_mode) return;
        if (cartActiveLineCount === 0 || isCheckingStatus) {
            if (isSubmitting) {
                setIsSubmitting(false);
            }
            return;
        }
        if (isSubmitting) return;

        if (activeSale) {
            setCheckoutState('confirmed');
            setShowSplitPay(true);
            setCheckoutError(null);
            setSubmissionFailed(false);
            setGuardianBanner(null);
            return;
        }

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
                if (!currentReadiness.allowed) {
                    throw new Error(currentReadiness.message || 'Controlled offline sales are not available on this terminal.');
                }

                const total = Number(cartSubtotal || 0).toFixed(2);
                const cashPaymentMethod = payment_methods.find((method) => isCashPayment(method));
                setActiveSale({
                    id: `offline-draft-${clientRequestUuid || generateUUID()}`,
                    total,
                    is_offline_draft: true,
                });
                setPaymentRows([{
                    id: crypto.randomUUID(),
                    payment_method_id: cashPaymentMethod?.id || '',
                    amount: total,
                    amount_tendered: '',
                    reference_number: '',
                }]);
                setCheckoutState('confirmed');
                setShowSplitPay(true);
                setIsSubmitting(false);
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
            const requestUuid = submissionFailed || checkoutState === 'failed'
                ? refreshDraftRequestIdentity()
                : clientRequestUuid;

            const payload = {
                client_request_uuid: requestUuid,
                items: cart.map(item => ({
                    product_id: item.id || item.product_id,
                    quantity: item.quantity
                }))
            };

            // 1. Validate Draft
            const validateRes = await fetch('/pos/checkout/validate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildPosHeaders(taxHash),
                body: JSON.stringify(payload)
            });

            if (!validateRes.ok) {
                const errData = await validateRes.json();
                applyPosAccessIssue(validateRes.status, errData, 'Checkout validation');
                const message = getCheckoutErrorMessage(validateRes.status, errData);
                if (validateRes.status === 409) {
                    const nextUuid = generateUUID();
                    setClientRequestUuid(nextUuid);
                    persistDraftState('draft', {
                        clientRequestUuid: nextUuid,
                        activeSale: null,
                        paymentWizardOpen: false,
                    });
                }
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
                    headers: buildPosHeaders(taxHash),
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
                applyPosAccessIssue(createSaleRes.status, saleData, 'Sale creation');
                const message = getCheckoutErrorMessage(createSaleRes.status, saleData);
                setCheckoutState('failed');
                setSubmissionFailed(true);
                setCheckoutError(message);
                showGuardianBanner('failed', message);
                return;
            }

            clearPosAccessIssue();
            activateConfirmedSale(saleData.sale_id, saleData.server_totals?.total);
        } catch (err) {
            captureRequestAccessIssue(err, 'Checkout');
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

    const handleSplitPayClose = () => {
        setShowSplitPay(false);
        if (activeSale?.is_offline_draft) {
            setActiveSale(null);
            setPaymentRows([]);
            setCheckoutState('draft');
            setCheckoutError(null);
            setSubmissionFailed(false);
            setGuardianBanner(null);
            persistDraftState('draft', {
                activeSale: null,
                paymentRows: [],
                paymentWizardOpen: false,
            });
            return;
        }

        setCheckoutState('confirmed');
        setCheckoutError(null);
        setSubmissionFailed(false);
        setGuardianBanner(null);
        persistDraftState('payment_pending', {
            paymentWizardOpen: false,
        });
    };

    const handleOfflinePaymentCaptured = async (rows) => {
        const currentReadiness = await resolveOfflineCaptureReadiness();
        if (!currentReadiness.allowed) {
            throw new Error(currentReadiness.message || 'Controlled offline sales are not available on this terminal.');
        }

        const taxHash = await catalogCache.getTaxHash();
        const { payload, clientTotals } = buildOfflineCapturePayload(taxHash, rows);
        const snapshotMetadata = await catalogCache.getConfigSnapshotMetadata();
        Object.assign(payload, snapshotMetadata);
        const businessDate = (activeShift?.business_date || new Date().toISOString().split('T')[0]).replace(/[^0-9]/g, '');
        const prefix = `OFF-${currentReadiness.machineProfile.profile_code || 'UNK'}-${businessDate}-`;

        await offlineSalesQueue.appendTransaction(payload, clientTotals, {
            prefix,
            initialNextValue: currentReadiness.machineProfile.offline_sequence_next_value,
        });

        const cashRow = rows.find(r => isCashPayment(payment_methods.find(m => m.id === r.payment_method_id)));
        if (cashRow) {
            setLastCashChange({
                tendered: Number(cashRow.amount_tendered),
                change: calculateCashChange(cashRow)
            });
        }

        setShowSplitPay(false);
        setPaymentRows([]);
        setIsSubmitting(false);
        clearCartState();
        setMobileCartOpen(false);
        showGuardianBanner(
            'offline_captured',
            'OFFLINE TRANSACTION CAPTURED. Pending server synchronization and reconciliation. This is not final ledger posting.',
            { timeoutMs: 5000 }
        );
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

        if (paymentResponse.status === 'queued_offline_payment') {
            setShowSplitPay(false);
            setPaymentRows([]);
            setIsSubmitting(false);
            clearCartState();
            setMobileCartOpen(false);
            showGuardianBanner(
                'offline_captured',
                'OFFLINE PAYMENT QUEUED. It will sync to the existing sale when the server is available.',
                { timeoutMs: 5000 }
            );
            return;
        }

        // After successful payment, fetch final receipt
        try {
            const receiptRes = await fetch(`/pos/sales/${activeSale.id}/receipt`, {
                credentials: 'same-origin',
                headers: buildPosHeaders(),
            });
            if (receiptRes.ok) {
                clearPosAccessIssue();
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
            } else {
                const receiptError = await readResponseJsonSafely(receiptRes);
                applyPosAccessIssue(receiptRes.status, receiptError, 'Receipt');
            }
            setShowSplitPay(false);
            setPaymentRows([]);
            clearCartState();
        } catch (err) {
            captureRequestAccessIssue(err, 'Receipt');
            console.error('Failed to load final receipt:', err);
            setShowSplitPay(false);
            setPaymentRows([]);
            clearCartState();
        }
    };

    const handlePosAccessIssueAction = async () => {
        if (!posAccessIssue) return;

        if (posAccessIssue.action === 'login') {
            window.location.href = '/login';
            return;
        }

        if (posAccessIssue.action === 'open_shift') {
            if (connOffline) {
                setErrorMessage("Internet connection required to open or modify cashier shifts.");
                setTimeout(() => setErrorMessage(null), 5000);
                return;
            }
            window.location.href = route('shifts.open');
            return;
        }

        try {
            await checkConnectivity?.();
        } catch {
            // Keep the banner visible; the next successful terminal request will clear it.
        }
    };

    return (
        <div className="h-[100dvh] w-full bg-slate-950 text-slate-100 flex flex-col overflow-hidden relative">
            <Head title="POS Terminal" />

            {posAccessIssue?.code === 'TERMINAL_CONTEXT_INVALID' ? (
                <ActivationModal
                    onActivated={(data) => {
                        clearPosAccessIssue();
                        refreshOfflineState();
                    }}
                />
            ) : null}

            {checkoutAlert && (
                <div className={`shrink-0 z-40 border-b px-6 py-3 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 animate-in fade-in duration-200 ${
                    checkoutAlert.severity === 'error'
                        ? 'border-rose-500/30 bg-rose-950/45 text-rose-100'
                        : checkoutAlert.severity === 'warning'
                            ? 'border-amber-500/30 bg-amber-950/45 text-amber-100'
                            : 'border-blue-500/30 bg-blue-950/45 text-blue-100'
                }`}>
                    <div className="flex items-start gap-3">
                        <AlertTriangle className={`mt-0.5 h-5 w-5 shrink-0 ${
                            checkoutAlert.severity === 'error' ? 'text-rose-400' :
                            checkoutAlert.severity === 'warning' ? 'text-amber-400' : 'text-blue-400'
                        }`} />
                        <div>
                            <h3 className="font-bold text-sm leading-snug">{checkoutAlert.title}</h3>
                            <p className="mt-1 text-xs opacity-90 leading-relaxed font-medium">
                                {checkoutAlert.message}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 shrink-0 self-end sm:self-center">
                        {checkoutAlert.type !== 'terminal_invalid' && checkoutAlert.type !== 'shift_required' && (
                            <button
                                type="button"
                                onClick={() => setConfigBannerDismissed(true)}
                                className="rounded-lg border border-slate-800 bg-slate-900/50 px-4 py-2 text-xs font-bold text-slate-300 hover:bg-slate-800 transition-all active:scale-95"
                            >
                                Dismiss
                            </button>
                        )}
                        {checkoutAlert.primaryAction === 'activate_terminal' && (
                            <button
                                type="button"
                                id="activate-terminal-trigger-btn"
                                onClick={() => {
                                    setPosAccessIssue({ code: 'TERMINAL_CONTEXT_INVALID' });
                                }}
                                className="rounded-lg bg-rose-500/20 border border-rose-500/30 px-4 py-2 text-xs font-bold text-rose-300 hover:bg-rose-500/30 transition-all active:scale-95"
                            >
                                Activate Terminal
                            </button>
                        )}
                        {checkoutAlert.primaryAction === 'open_shift' && (
                            <Link
                                href={route('shifts.open')}
                                onClick={(e) => {
                                    if (connOffline) {
                                        e.preventDefault();
                                        setErrorMessage("Internet connection required to open or modify cashier shifts.");
                                        setTimeout(() => setErrorMessage(null), 5000);
                                    }
                                }}
                                className="rounded-lg bg-amber-500/20 border border-amber-500/30 px-4 py-2 text-xs font-bold text-amber-300 hover:bg-amber-500/30 transition-all active:scale-95"
                            >
                                Open Shift
                            </Link>
                        )}
                        {checkoutAlert.primaryAction === 'refresh_config' && (
                            <button
                                type="button"
                                onClick={handleRefreshConfig}
                                className="rounded-lg bg-amber-500/20 border border-amber-500/30 px-4 py-2 text-xs font-bold text-amber-300 hover:bg-amber-500/30 transition-all active:scale-95"
                            >
                                Refresh Config
                            </button>
                        )}
                        {checkoutAlert.primaryAction === 'review_queue' && (
                            <Link
                                href={route('pos.terminal.sync-status')}
                                className="rounded-lg bg-amber-500/20 border border-amber-500/30 px-4 py-2 text-xs font-bold text-amber-300 hover:bg-amber-500/30 transition-all active:scale-95"
                            >
                                Review Queue
                            </Link>
                        )}
                        {checkoutAlert.primaryAction === 'reload_layout' && (
                            <button
                                type="button"
                                onClick={handleReloadLayout}
                                disabled={isReloadingLayout}
                                className="rounded-lg bg-blue-500/20 border border-blue-500/30 px-4 py-2 text-xs font-bold text-blue-300 hover:bg-blue-500/30 transition-all active:scale-95 flex items-center gap-1.5"
                            >
                                {isReloadingLayout ? (
                                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                                ) : (
                                    <RefreshCw className="w-3.5 h-3.5" />
                                )}
                                Reload Layout
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Compact Offline Mode strip */}
            {connOffline && !checkoutAlert && (
                <div className="mx-6 mt-4 bg-amber-500/10 border border-amber-500/20 text-amber-300 px-4 py-2.5 rounded-xl flex items-center justify-between text-xs animate-in fade-in duration-200">
                    <div className="flex items-center gap-2">
                        <WifiOff className="w-4 h-4 text-amber-400 shrink-0 animate-pulse" />
                        <span>Offline mode — Cash sales only. {offlineQueueSummary.pending} sale{offlineQueueSummary.pending === 1 ? '' : 's'} pending sync.</span>
                    </div>
                    <Link
                        href={route('pos.terminal.sync-status')}
                        className="text-xs font-bold underline hover:text-amber-200 transition-colors"
                    >
                        View sync status
                    </Link>
                </div>
            )}

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
                {tableLockError && (
                    <div className="bg-rose-500/10 border border-rose-500/20 text-rose-400 px-4 py-3 rounded-xl shadow-2xl flex items-center gap-3 backdrop-blur-md animate-in fade-in slide-in-from-top-4">
                        <AlertTriangle className="w-5 h-5 animate-bounce" />
                        <span className="font-medium text-sm">{tableLockError}</span>
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
                <div className="shrink-0 space-y-3">
                    {/* Unified Amber Alert Banner */}
                    {(isStale || reviewCount > 0) && !configBannerDismissed && (
                        <div className="mx-6 mt-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-amber-100 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 animate-in fade-in duration-200">
                            <div className="flex items-start gap-3">
                                <AlertTriangle className="mt-0.5 h-5 w-5 text-amber-400 shrink-0" />
                                <div>
                                    <h3 className="font-bold text-sm">
                                        {isStale && reviewCount > 0
                                            ? `Config outdated, ${reviewCount} items need review`
                                            : isStale
                                                ? 'Config outdated'
                                                : `${reviewCount} items need review`}
                                    </h3>
                                    <p className="mt-1 text-xs text-amber-200/80 leading-relaxed font-medium">
                                        Cache last synced {lastSyncedTime}. Refresh config and resolve conflicts before checkout.
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-3 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setConfigBannerDismissed(true)}
                                    className="rounded-lg border border-slate-800 bg-slate-900/50 px-4 py-2 text-xs font-bold text-slate-300 hover:bg-slate-800 transition-all active:scale-95"
                                >
                                    Dismiss
                                </button>
                                <button
                                    type="button"
                                    onClick={handleRefreshConfig}
                                    className="rounded-lg bg-amber-500/20 border border-amber-500/30 px-4 py-2 text-xs font-bold text-amber-300 hover:bg-amber-500/30 transition-all active:scale-95"
                                >
                                    Refresh config
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Quiet Shift Row */}
                    {!activeShift ? (
                        <div className="mx-6 mt-4 flex items-center justify-between rounded-xl border border-slate-800 bg-slate-900/30 px-4 py-3 text-slate-400">
                            <div className="flex items-center gap-2 text-sm font-medium">
                                <Clock className="w-4 h-4 text-slate-500" />
                                <span>No active shift</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <Link
                                    href={route('pos.terminal.floor-map')}
                                    className="flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800/40 px-3 py-1.5 text-xs font-bold text-slate-200 hover:bg-slate-800 hover:text-white transition-all active:scale-95"
                                >
                                    <Utensils className="h-3.5 w-3.5" />
                                    Floor map
                                </Link>
                                <Link
                                    href={route('shifts.open')}
                                    onClick={(e) => {
                                        if (connOffline) {
                                            e.preventDefault();
                                            setErrorMessage("Internet connection required to open or modify cashier shifts.");
                                            setTimeout(() => setErrorMessage(null), 5000);
                                        }
                                    }}
                                    className="rounded-lg border border-slate-700 bg-slate-800/40 px-3 py-1.5 text-xs font-bold text-slate-200 hover:bg-slate-800 hover:text-white transition-all active:scale-95"
                                >
                                    Open shift
                                </Link>
                            </div>
                        </div>
                    ) : (
                        <div className="mx-6 mt-4 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-800 bg-slate-900/30 px-4 py-3 text-slate-400">
                            <div className="flex items-center gap-6">
                                <div className="flex items-center gap-2 text-sm text-slate-300 font-medium">
                                    <Clock className="w-4 h-4 text-slate-500" />
                                    <span>Active shift: {activeShift.cashier_name}</span>
                                    {timecardStatus && (
                                        <span
                                            className={`inline-block w-2 h-2 rounded-full ${timecardStatus.clocked_in ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'}`}
                                            title={timecardStatus.clocked_in ? 'Timecard clocked in' : 'Timecard clocked out'}
                                        />
                                    )}
                                </div>
                                {shiftDuration && (
                                    <span className="text-xs font-mono text-slate-500 font-medium">Duration: {shiftDuration}</span>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <Link
                                    href={route('pos.terminal.floor-map')}
                                    className="flex items-center gap-1.5 rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:bg-slate-800 transition-all active:scale-95"
                                >
                                    <Utensils className="h-3.5 w-3.5" />
                                    Floor map
                                </Link>
                                <button
                                    onClick={() => setShowCashEvent(true)}
                                    className="rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:bg-slate-800 transition-all active:scale-95"
                                >
                                    Record cash
                                </button>
                                <button
                                    onClick={() => setShowSpotAudit(true)}
                                    className="rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:bg-slate-800 transition-all active:scale-95"
                                >
                                    Spot audit
                                </button>
                                <button
                                    onClick={() => setShowCloseShift(true)}
                                    className="rounded-lg border border-rose-500/20 bg-rose-500/10 px-3 py-1.5 text-xs font-bold text-rose-300 hover:bg-rose-500/20 transition-all active:scale-95"
                                >
                                    Close shift
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            <div className="flex-1 flex flex-col md:flex-row overflow-hidden min-h-0">
                {/* Product Catalog Column */}
                <div className="flex-1 flex flex-col overflow-hidden min-h-0 relative">
                    {!activeShift && !showLayoutEditControls && (
                        <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-md z-20 flex flex-col items-center justify-center text-center p-6 animate-in fade-in duration-200">
                            <div className="w-16 h-16 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-center text-slate-500 mb-4">
                                <Clock className="w-8 h-8 animate-pulse text-indigo-400" />
                            </div>
                            <h2 className="text-lg font-bold text-slate-200">Open Shift to Start Selling</h2>
                            <p className="text-sm text-slate-500 max-w-sm mt-2 leading-relaxed">
                                Cashier sales are disabled until a shift is opened.
                            </p>
                            <Link
                                href={route('shifts.open')}
                                onClick={(e) => {
                                    if (connOffline) {
                                        e.preventDefault();
                                        setErrorMessage("Internet connection required to open or modify cashier shifts.");
                                        setTimeout(() => setErrorMessage(null), 5000);
                                    }
                                }}
                                className="mt-6 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold px-6 py-3 shadow-lg shadow-indigo-600/20 transition-all active:scale-95 text-sm"
                            >
                                Open Shift
                            </Link>
                        </div>
                    )}

                    {/* Header / Search Bar Area */}
                    <header className="p-4 border-b border-slate-800 bg-slate-900/50 backdrop-blur-md sticky top-0 z-10 shrink-0">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center justify-between">
                            <div className="flex flex-col gap-1">
                                <div className="flex items-center gap-2">
                                    <div className="p-2 bg-indigo-600 rounded-lg">
                                        <LayoutGrid className="w-6 h-6 text-white" />
                                    </div>
                                    <h1 className="text-xl font-bold tracking-tight">Abbadev IPOS Terminal</h1>

                                    {/* Config Status Badge */}
                                    <div className="flex items-center gap-1.5 ml-2">
                                        <span className={`w-2 h-2 rounded-full ${
                                            isChecking ? 'bg-indigo-500 animate-pulse' :
                                            isStale ? 'bg-amber-500 animate-pulse' :
                                            lastSyncedAt ? 'bg-emerald-500' : 'bg-slate-500'
                                        }`} />
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                            {isChecking ? 'Syncing...' :
                                             isStale ? 'Config update available' :
                                             lastSyncedAt ? 'Synchronized' : 'Sync not available'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center gap-3 flex-1 sm:max-w-xl">
                                {/* Table Selector */}
                                <div className="flex items-center gap-2 shrink-0">
                                    <span className="text-xs text-slate-400 font-bold uppercase tracking-wider">Table:</span>
                                    <select
                                        value={selectedTable}
                                        onChange={(e) => handleSelectTable(e.target.value)}
                                        className="bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-xs font-bold text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <option value="none">None (Direct)</option>
                                        <option value="Table-01">Table 1</option>
                                        <option value="Table-02">Table 2</option>
                                        <option value="Table-03">Table 3</option>
                                        <option value="Table-04">Table 4</option>
                                        <option value="Table-05">Table 5</option>
                                    </select>
                                </div>
                                <SearchBar
                                    value={searchQuery}
                                    onChange={setSearchQuery}
                                    onScan={(barcode) => setSearchQuery(barcode)}
                                    loading={loading}
                                    disabled={!activeShift && !showLayoutEditControls}
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
                            {showLayoutEditControls && (
                                <button
                                    onClick={() => window.location.href = route('admin.pos-layouts.index')}
                                    className="group flex flex-col items-center justify-between p-6 rounded-2xl bg-slate-900/30 border border-dashed border-slate-800 hover:border-indigo-500/50 hover:bg-slate-900/60 hover:shadow-[0_0_30px_rgba(99,102,241,0.08)] transition-all duration-300 transform active:scale-95 text-center h-44 relative overflow-hidden"
                                >
                                    <div className="absolute inset-0 bg-gradient-to-br from-indigo-500/5 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500" />

                                    <div className="w-16 h-16 rounded-2xl bg-slate-950/40 border border-slate-800/80 group-hover:border-indigo-500/30 text-slate-600 group-hover:text-indigo-400 flex items-center justify-center transition-all duration-300 shrink-0">
                                        <Plus className="w-6 h-6" />
                                    </div>

                                    <div className="flex-1 flex flex-col justify-center mt-4">
                                        <span className="font-bold text-sm text-slate-400 group-hover:text-white tracking-wide transition-colors leading-tight">
                                            Add category
                                        </span>
                                        <span className="text-[10px] text-slate-500 group-hover:text-indigo-400/80 font-semibold uppercase tracking-widest mt-1">
                                            Manage in settings
                                        </span>
                                    </div>
                                </button>
                            )}
                            {localCategories.length === 0 && !showLayoutEditControls && (
                                <div className="col-span-full py-16 text-center text-slate-650">
                                    <Layers className="w-12 h-12 text-slate-800 mx-auto mb-3" />
                                    <p className="font-bold text-slate-400 text-sm">Layout not configured</p>
                                    <p className="text-xs text-slate-600 mt-1.5 max-w-xs mx-auto font-medium">Ask an admin to configure the POS terminal layout.</p>
                                </div>
                            )}
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
                                    {cartActiveLineCount > 0 && (
                                        <span className="absolute -top-2 -right-2 bg-rose-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[1.25rem] text-center">
                                            {cartActiveLineCount}
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
                        onOpenSpecialDiscount={() => setShowSpecialDiscount(true)}
                        appliedStatutoryDiscount={appliedStatutoryDiscount}
                        hasActiveShift={Boolean(activeShift)}
                    />
                </aside>
            </div>

            {/* Special Discount Modal */}
            <SpecialDiscountModal
                isOpen={showSpecialDiscount}
                onClose={() => setShowSpecialDiscount(false)}
                onApply={(discountData) => setAppliedStatutoryDiscount(discountData)}
                discountTypes={discountTypes || []}
                cartItems={cart}
                buildPosHeaders={(taxHash) => buildPosHeaders(taxHash)}
            />

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
                    onClose={handleSplitPayClose}
                    onPaymentRecorded={handlePaymentRecorded}
                    onOfflineCapture={handleOfflinePaymentCaptured}
                    offlineCaptureMode={Boolean(activeSale?.is_offline_draft)}
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

            {/* Spot Audit Modal */}
            {!is_admin_mode && activeShift && showSpotAudit && (
                <SpotAuditModal
                    show={showSpotAudit}
                    onClose={() => setShowSpotAudit(false)}
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
