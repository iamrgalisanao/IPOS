import axios from 'axios';

export interface TenantContext {
    id: string;
    tax_mode: string;
    [key: string]: any;
}

export interface BranchContext {
    id: string;
    status: string;
    [key: string]: any;
}

export interface MachineProfileContext {
    id: string;
    profile_code: string;
    [key: string]: any;
}

export interface CacheBootstrapPayload {
    products: any[];
    categories: any[];
    tax_categories: any[];
    payment_methods?: any[];
    cash_drawer_reasons?: any[];
    tenant_context: TenantContext | null;
    branch_context: BranchContext | null;
    machine_profile_context: MachineProfileContext | null;
    permissions: string[];
    tax_configuration_version_hash: string | null;
    catalog_version_hash: string | null;
    layout_version_hash?: string | null;
    discount_rules_version_hash?: string | null;
    payment_methods_version_hash?: string | null;
    terminal_policy_version_hash?: string | null;
    printer_profile_version_hash?: string | null;
    cash_drawer_reasons_version_hash?: string | null;
    config_snapshot_hash?: string | null;
    config_snapshot?: Record<string, any> | null;
    generated_at: string;
    cache_ttl_seconds: number;
}

export interface ConfigSnapshotMetadata {
    config_snapshot_hash: string | null;
    layout_version_hash: string | null;
    catalog_version_hash: string | null;
    tax_configuration_version_hash: string | null;
    discount_rules_version_hash: string | null;
    payment_methods_version_hash: string | null;
    terminal_policy_version_hash: string | null;
    printer_profile_version_hash: string | null;
    cash_drawer_reasons_version_hash: string | null;
    config_snapshot: Record<string, any> | null;
    generated_at: string | null;
    cache_ttl_seconds: number;
}

export function validateBootstrapPayload(payload: any): asserts payload is CacheBootstrapPayload {
    const generatedAt = typeof payload?.generated_at === 'string'
        ? Date.parse(payload.generated_at)
        : Number.NaN;

    const contexts = [payload?.tenant_context, payload?.branch_context];
    if (payload?.machine_profile_context !== null && payload?.machine_profile_context !== undefined) {
        contexts.push(payload.machine_profile_context);
    }
    const objectArrays = [payload?.products, payload?.categories, payload?.tax_categories, payload?.payment_methods];

    if (
        !payload
        || typeof payload !== 'object'
        || !Array.isArray(payload.products)
        || !Array.isArray(payload.categories)
        || !Array.isArray(payload.tax_categories)
        || !Array.isArray(payload.payment_methods)
        || (payload.cash_drawer_reasons !== undefined && !Array.isArray(payload.cash_drawer_reasons))
        || !Array.isArray(payload.permissions)
        || !payload.permissions.every((permission: any) => typeof permission === 'string')
        || !contexts.every((context) => context && typeof context === 'object' && !Array.isArray(context))
        || !objectArrays.every((rows) => rows.every((row: any) => row && typeof row === 'object' && !Array.isArray(row)))
        || typeof payload.config_snapshot_hash !== 'string'
        || payload.config_snapshot_hash.trim() === ''
        || !payload.config_snapshot
        || typeof payload.config_snapshot !== 'object'
        || Array.isArray(payload.config_snapshot)
        || payload.config_snapshot.config_snapshot_hash !== payload.config_snapshot_hash
        || !Number.isFinite(generatedAt)
        || generatedAt > Date.now() + 5 * 60 * 1000
        || typeof payload.cache_ttl_seconds !== 'number'
        || !Number.isFinite(payload.cache_ttl_seconds)
        || payload.cache_ttl_seconds <= 0
    ) {
        throw new Error('The server returned an invalid configuration snapshot. The previous configuration was kept.');
    }
}

function matchesCachedProductCategory(product: any, category: any): boolean {
    if (!category) {
        return true;
    }

    const categoryId = typeof category === 'object' ? category.id : category;
    const categoryName = typeof category === 'object' ? category.name : null;
    const categoryCode = typeof category === 'object' ? category.code : null;

    const productCategoryIds = [
        product.product_category_id,
        product.category_id,
        product.category?.id,
    ].filter((value) => value !== undefined && value !== null);

    if (categoryId !== undefined && categoryId !== null) {
        const targetCategoryId = String(categoryId);
        if (productCategoryIds.some((value) => String(value) === targetCategoryId)) {
            return true;
        }
    }

    const productCategoryNames = [
        product.category_name,
        product.category?.name,
        product.category,
    ].filter((value) => typeof value === 'string');

    if (categoryName && productCategoryNames.some((value) => value.toLowerCase() === String(categoryName).toLowerCase())) {
        return true;
    }

    const productCategoryCodes = [
        product.category_code,
        product.category?.code,
    ].filter((value) => value !== undefined && value !== null);

    if (categoryCode && productCategoryCodes.some((value) => String(value).toLowerCase() === String(categoryCode).toLowerCase())) {
        return true;
    }

    return false;
}

export function filterCachedProducts(products: any[] = [], searchQuery = '', category: any = null): any[] {
    let filtered = products;

    if (category) {
        filtered = filtered.filter((product) => matchesCachedProductCategory(product, category));
    }

    const query = searchQuery.trim().toLowerCase();
    if (query) {
        filtered = filtered.filter((product) => {
            const searchable = [
                product.display_name,
                product.name,
                product.barcode,
                product.sku,
            ];

            return searchable.some((value) => String(value ?? '').toLowerCase().includes(query));
        });
    }

    return filtered;
}

const DB_NAME = 'ipos_pos_cache';
const DB_VERSION = 2;

export class CatalogCacheService {
    private db: IDBDatabase | null = null;

    async initDb(): Promise<IDBDatabase> {
        if (this.db) return this.db;

        const idb = typeof indexedDB !== 'undefined' ? indexedDB : (global as any).indexedDB;
        if (!idb) {
            throw new Error('IndexedDB is not supported/available in this environment.');
        }

        return new Promise((resolve, reject) => {
            const request = idb.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (event: any) => {
                const db = request.result;
                if (!db.objectStoreNames.contains('metadata')) {
                    db.createObjectStore('metadata');
                }
                if (!db.objectStoreNames.contains('products')) {
                    db.createObjectStore('products', { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains('categories')) {
                    db.createObjectStore('categories', { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains('tax_categories')) {
                    db.createObjectStore('tax_categories', { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains('payment_methods')) {
                    db.createObjectStore('payment_methods', { keyPath: 'id' });
                }
            };

            request.onsuccess = () => {
                this.db = request.result;
                resolve(this.db);
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    async clearCache(): Promise<void> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata', 'products', 'categories', 'tax_categories', 'payment_methods'], 'readwrite');
            tx.objectStore('metadata').clear();
            tx.objectStore('products').clear();
            tx.objectStore('categories').clear();
            tx.objectStore('tax_categories').clear();
            tx.objectStore('payment_methods').clear();

            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    async writeBootstrapPayload(payload: CacheBootstrapPayload): Promise<void> {
        validateBootstrapPayload(payload);
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata', 'products', 'categories', 'tax_categories', 'payment_methods'], 'readwrite');

            // Clear previous cache
            tx.objectStore('metadata').clear();
            tx.objectStore('products').clear();
            tx.objectStore('categories').clear();
            tx.objectStore('tax_categories').clear();
            tx.objectStore('payment_methods').clear();

             // Write metadata
             const metadataStore = tx.objectStore('metadata');
             metadataStore.put(payload.tax_configuration_version_hash, 'tax_configuration_version_hash');
             metadataStore.put(payload.catalog_version_hash, 'catalog_version_hash');
             metadataStore.put(payload.layout_version_hash || null, 'layout_version_hash');
             metadataStore.put(payload.discount_rules_version_hash || null, 'discount_rules_version_hash');
             metadataStore.put(payload.payment_methods_version_hash || null, 'payment_methods_version_hash');
             metadataStore.put(payload.terminal_policy_version_hash || null, 'terminal_policy_version_hash');
             metadataStore.put(payload.printer_profile_version_hash || null, 'printer_profile_version_hash');
             metadataStore.put(payload.cash_drawer_reasons_version_hash || null, 'cash_drawer_reasons_version_hash');
             metadataStore.put(payload.cash_drawer_reasons || [], 'cash_drawer_reasons');
             metadataStore.put(payload.config_snapshot_hash || null, 'config_snapshot_hash');
             metadataStore.put(payload.config_snapshot || null, 'config_snapshot');
             metadataStore.put(payload.tenant_context, 'tenant_context');
             metadataStore.put(payload.branch_context, 'branch_context');
             metadataStore.put(payload.machine_profile_context, 'machine_profile_context');
             metadataStore.put(payload.permissions, 'permissions');
             metadataStore.put(payload.generated_at, 'generated_at');
             metadataStore.put(payload.cache_ttl_seconds, 'cache_ttl_seconds');

            // Write Products (normalize 'product_id' -> 'id' for IndexedDB keyPath)
            const productsStore = tx.objectStore('products');
            for (const prod of payload.products || []) {
                if (!prod.id && prod.product_id) {
                    prod.id = prod.product_id;
                }
                productsStore.put(prod);
            }

            // Write Categories
            const categoriesStore = tx.objectStore('categories');
            for (const cat of payload.categories || []) {
                categoriesStore.put(cat);
            }

            // Write Tax Categories
            const taxCategoriesStore = tx.objectStore('tax_categories');
            for (const tc of payload.tax_categories || []) {
                taxCategoriesStore.put(tc);
            }

            // Write Payment Methods
            const paymentMethodsStore = tx.objectStore('payment_methods');
            for (const pm of payload.payment_methods || []) {
                paymentMethodsStore.put(pm);
            }

            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    async getCachedCatalog(): Promise<CacheBootstrapPayload> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata', 'products', 'categories', 'tax_categories', 'payment_methods'], 'readonly');

            const productsRequest = tx.objectStore('products').getAll();
            const categoriesRequest = tx.objectStore('categories').getAll();
            const taxCategoriesRequest = tx.objectStore('tax_categories').getAll();
            const paymentMethodsRequest = tx.objectStore('payment_methods').getAll();
            
            const metadataStore = tx.objectStore('metadata');
            const hashReq = metadataStore.get('tax_configuration_version_hash');
            const catalogHashReq = metadataStore.get('catalog_version_hash');
            const layoutHashReq = metadataStore.get('layout_version_hash');
            const discountRulesHashReq = metadataStore.get('discount_rules_version_hash');
            const paymentMethodsHashReq = metadataStore.get('payment_methods_version_hash');
            const terminalPolicyHashReq = metadataStore.get('terminal_policy_version_hash');
            const printerProfileHashReq = metadataStore.get('printer_profile_version_hash');
            const cashDrawerReasonsHashReq = metadataStore.get('cash_drawer_reasons_version_hash');
            const cashDrawerReasonsReq = metadataStore.get('cash_drawer_reasons');
            const configSnapshotHashReq = metadataStore.get('config_snapshot_hash');
            const configSnapshotReq = metadataStore.get('config_snapshot');
            const tenantReq = metadataStore.get('tenant_context');
            const branchReq = metadataStore.get('branch_context');
            const machineReq = metadataStore.get('machine_profile_context');
            const permissionsReq = metadataStore.get('permissions');
            const generatedAtReq = metadataStore.get('generated_at');
            const ttlReq = metadataStore.get('cache_ttl_seconds');

            tx.oncomplete = () => {
                resolve({
                    products: productsRequest.result || [],
                    categories: categoriesRequest.result || [],
                    tax_categories: taxCategoriesRequest.result || [],
                    payment_methods: paymentMethodsRequest.result || [],
                    cash_drawer_reasons: cashDrawerReasonsReq.result || [],
                    tenant_context: tenantReq.result || null,
                    branch_context: branchReq.result || null,
                    machine_profile_context: machineReq.result || null,
                    permissions: permissionsReq.result || [],
                    tax_configuration_version_hash: hashReq.result || null,
                    catalog_version_hash: catalogHashReq.result || null,
                    layout_version_hash: layoutHashReq.result || null,
                    discount_rules_version_hash: discountRulesHashReq.result || null,
                    payment_methods_version_hash: paymentMethodsHashReq.result || null,
                    terminal_policy_version_hash: terminalPolicyHashReq.result || null,
                    printer_profile_version_hash: printerProfileHashReq.result || null,
                    cash_drawer_reasons_version_hash: cashDrawerReasonsHashReq.result || null,
                    config_snapshot_hash: configSnapshotHashReq.result || null,
                    config_snapshot: configSnapshotReq.result || null,
                    generated_at: generatedAtReq.result || '',
                    cache_ttl_seconds: ttlReq.result || 0
                });
            };

            tx.onerror = () => reject(tx.error);
        });
    }

    async getTaxHash(): Promise<string | null> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata'], 'readonly');
            const request = tx.objectStore('metadata').get('tax_configuration_version_hash');
            request.onsuccess = () => resolve(request.result || null);
            request.onerror = () => reject(request.error);
        });
    }

    async getCatalogHash(): Promise<string | null> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata'], 'readonly');
            const request = tx.objectStore('metadata').get('catalog_version_hash');
            request.onsuccess = () => resolve(request.result || null);
            request.onerror = () => reject(request.error);
        });
    }

    async getConfigSnapshotMetadata(): Promise<ConfigSnapshotMetadata> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata'], 'readonly');
            const store = tx.objectStore('metadata');
            const configSnapshotHashReq = store.get('config_snapshot_hash');
            const layoutHashReq = store.get('layout_version_hash');
            const catalogHashReq = store.get('catalog_version_hash');
            const taxHashReq = store.get('tax_configuration_version_hash');
            const discountRulesHashReq = store.get('discount_rules_version_hash');
            const paymentMethodsHashReq = store.get('payment_methods_version_hash');
            const terminalPolicyHashReq = store.get('terminal_policy_version_hash');
            const printerProfileHashReq = store.get('printer_profile_version_hash');
            const cashDrawerReasonsHashReq = store.get('cash_drawer_reasons_version_hash');
            const configSnapshotReq = store.get('config_snapshot');
            const generatedAtReq = store.get('generated_at');
            const ttlReq = store.get('cache_ttl_seconds');

            tx.oncomplete = () => resolve({
                config_snapshot_hash: configSnapshotHashReq.result || null,
                layout_version_hash: layoutHashReq.result || null,
                catalog_version_hash: catalogHashReq.result || null,
                tax_configuration_version_hash: taxHashReq.result || null,
                discount_rules_version_hash: discountRulesHashReq.result || null,
                payment_methods_version_hash: paymentMethodsHashReq.result || null,
                terminal_policy_version_hash: terminalPolicyHashReq.result || null,
                printer_profile_version_hash: printerProfileHashReq.result || null,
                cash_drawer_reasons_version_hash: cashDrawerReasonsHashReq.result || null,
                config_snapshot: configSnapshotReq.result || null,
                generated_at: generatedAtReq.result || null,
                cache_ttl_seconds: ttlReq.result || 0,
            });
            tx.onerror = () => reject(tx.error);
        });
    }

    async isStale(currentTaxHash?: string): Promise<boolean> {
        const db = await this.initDb();
        
        const metadata: {
            hash: string | null;
            generatedAt: string | null;
            ttl: number;
        } = await new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata'], 'readonly');
            const store = tx.objectStore('metadata');
            const hashReq = store.get('tax_configuration_version_hash');
            const genReq = store.get('generated_at');
            const ttlReq = store.get('cache_ttl_seconds');
            
            tx.oncomplete = () => {
                resolve({
                    hash: hashReq.result || null,
                    generatedAt: genReq.result || null,
                    ttl: ttlReq.result || 0
                });
            };
            tx.onerror = () => reject(tx.error);
        });

        // 1. Mismatch with currentTaxHash (if provided)
        if (currentTaxHash !== undefined && metadata.hash !== currentTaxHash) {
            return true;
        }

        // 2. Missing hash or generated timestamp
        if (!metadata.hash || !metadata.generatedAt) {
            return true;
        }

        // 3. TTL Expiry check
        const generatedTime = new Date(metadata.generatedAt).getTime();
        const expiryTime = generatedTime + (metadata.ttl * 1000);
        if (Date.now() > expiryTime) {
            return true;
        }

        return false;
    }

    async fetchAndStoreBootstrap(): Promise<CacheBootstrapPayload> {
        // Enforce online check
        const nav = typeof navigator !== 'undefined' ? navigator : (global as any).navigator;
        if (nav && !nav.onLine) {
            throw new Error('Cannot fetch bootstrap cache while offline.');
        }

        const response = await axios.get<CacheBootstrapPayload>('/api/pos/bootstrap-cache');
        const payload = response.data;

        validateBootstrapPayload(payload);
        await this.writeBootstrapPayload(payload);
        return payload;
    }

    /**
     * Update only the layout_version_hash in the IndexedDB metadata store.
     *
     * Called after the cashier reloads the layout via the StaleLayoutBanner so that
     * the next heartbeat compares against the refreshed hash and no longer reports drift.
     *
     * This is a targeted single-key write — it does not clear or invalidate the rest of
     * the bootstrap cache (catalog, tax, payment methods etc. are unaffected).
     */
    async updateLayoutVersionHash(hash: string | null): Promise<void> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata'], 'readwrite');
            const store = tx.objectStore('metadata');
            const snapshotRequest = store.get('config_snapshot');

            snapshotRequest.onsuccess = () => {
                const snapshot = snapshotRequest.result && typeof snapshotRequest.result === 'object'
                    ? { ...snapshotRequest.result, layout_version_hash: hash ?? null }
                    : null;

                if (snapshot) {
                    store.put(snapshot, 'config_snapshot');
                }
            };

            store.put(hash ?? null, 'layout_version_hash');
            tx.oncomplete = () => resolve();
            tx.onerror = (e) => reject((e.target as IDBRequest).error);
        });
    }
}

export const catalogCache = new CatalogCacheService();
