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
    tenant_context: TenantContext | null;
    branch_context: BranchContext | null;
    machine_profile_context: MachineProfileContext | null;
    permissions: string[];
    tax_configuration_version_hash: string | null;
    catalog_version_hash: string | null;
    generated_at: string;
    cache_ttl_seconds: number;
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
const DB_VERSION = 1;

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
            const tx = db.transaction(['metadata', 'products', 'categories', 'tax_categories'], 'readwrite');
            tx.objectStore('metadata').clear();
            tx.objectStore('products').clear();
            tx.objectStore('categories').clear();
            tx.objectStore('tax_categories').clear();

            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    async writeBootstrapPayload(payload: CacheBootstrapPayload): Promise<void> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata', 'products', 'categories', 'tax_categories'], 'readwrite');

            // Clear previous cache
            tx.objectStore('metadata').clear();
            tx.objectStore('products').clear();
            tx.objectStore('categories').clear();
            tx.objectStore('tax_categories').clear();

             // Write metadata
             const metadataStore = tx.objectStore('metadata');
             metadataStore.put(payload.tax_configuration_version_hash, 'tax_configuration_version_hash');
             metadataStore.put(payload.catalog_version_hash, 'catalog_version_hash');
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

            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    async getCachedCatalog(): Promise<CacheBootstrapPayload> {
        const db = await this.initDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(['metadata', 'products', 'categories', 'tax_categories'], 'readonly');

            const productsRequest = tx.objectStore('products').getAll();
            const categoriesRequest = tx.objectStore('categories').getAll();
            const taxCategoriesRequest = tx.objectStore('tax_categories').getAll();
            
            const metadataStore = tx.objectStore('metadata');
            const hashReq = metadataStore.get('tax_configuration_version_hash');
            const catalogHashReq = metadataStore.get('catalog_version_hash');
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
                    tenant_context: tenantReq.result || null,
                    branch_context: branchReq.result || null,
                    machine_profile_context: machineReq.result || null,
                    permissions: permissionsReq.result || [],
                    tax_configuration_version_hash: hashReq.result || null,
                    catalog_version_hash: catalogHashReq.result || null,
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

        await this.writeBootstrapPayload(payload);
        return payload;
    }
}

export const catalogCache = new CatalogCacheService();
