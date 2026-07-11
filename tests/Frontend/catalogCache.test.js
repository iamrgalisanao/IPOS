import { test } from 'node:test';
import assert from 'node:assert/strict';
import axios from 'axios';
import { catalogCache, filterCachedProducts } from '../../resources/js/POS/offline/catalogCache.ts';
import { validateCheckoutAllowed, isOffline, resolveOfflineCaptureReadiness } from '../../resources/js/POS/offline/offlineGuards.ts';
import { globalState } from '../../resources/js/POS/offline/connectivityStore.ts';

// ----------------- Mock IndexedDB -----------------
class MockIDBRequest {
    constructor(result = null) {
        this.result = result;
        this.onsuccess = null;
        this.onerror = null;
    }
}

class MockIDBObjectStore {
    constructor(dataMap = new Map()) {
        this.dataMap = dataMap;
    }
    clear() {
        this.dataMap.clear();
        const req = new MockIDBRequest();
        setTimeout(() => req.onsuccess && req.onsuccess({ target: req }), 0);
        return req;
    }
    put(value, key) {
        const k = key !== undefined ? key : value.id;
        this.dataMap.set(k, value);
        const req = new MockIDBRequest(k);
        setTimeout(() => req.onsuccess && req.onsuccess({ target: req }), 0);
        return req;
    }
    get(key) {
        const value = this.dataMap.get(key);
        const req = new MockIDBRequest(value);
        setTimeout(() => req.onsuccess && req.onsuccess({ target: req }), 0);
        return req;
    }
    getAll() {
        const values = Array.from(this.dataMap.values());
        const req = new MockIDBRequest(values);
        setTimeout(() => req.onsuccess && req.onsuccess({ target: req }), 0);
        return req;
    }
}

class MockIDBTransaction {
    constructor(stores) {
        this.stores = stores;
        this.oncomplete = null;
        this.onerror = null;
        setTimeout(() => this.oncomplete && this.oncomplete(), 5);
    }
    objectStore(name) {
        return this.stores[name];
    }
}

class MockIDBDatabase {
    constructor() {
        this.objectStoreNames = {
            contains: (name) => true
        };
        this.stores = {
            metadata: new MockIDBObjectStore(),
            products: new MockIDBObjectStore(),
            categories: new MockIDBObjectStore(),
            tax_categories: new MockIDBObjectStore()
        };
    }
    transaction(storeNames, mode) {
        return new MockIDBTransaction(this.stores);
    }
}

class MockIDBOpenRequest {
    constructor() {
        this.result = new MockIDBDatabase();
        this.onsuccess = null;
        this.onupgradeneeded = null;
        this.onerror = null;
        setTimeout(() => {
            if (this.onupgradeneeded) this.onupgradeneeded({ target: this });
            if (this.onsuccess) this.onsuccess({ target: this });
        }, 5);
    }
}

global.indexedDB = {
    open(name, version) {
        return new MockIDBOpenRequest();
    }
};

// ----------------- Mock navigator -----------------
const mockNavigator = {
    onLine: true
};
Object.defineProperty(global, 'navigator', {
    value: mockNavigator,
    configurable: true,
    writable: true
});

// Mock payload
const mockPayload = {
    products: [
        { id: 1, name: 'Product A', selling_price: '10.00' }
    ],
    categories: [
        { id: 1, name: 'Category X' }
    ],
    tax_categories: [
        { id: 1, name: 'VAT 12%' }
    ],
    tenant_context: { id: 'tenant-1', tax_mode: 'inclusive', offline_sales_enabled: true },
    branch_context: { id: 'branch-1', status: 'active', offline_sales_enabled: true },
    machine_profile_context: {
        id: 'machine-1',
        profile_code: 'POS-1',
        status: 'active',
        offline_sales_enabled: null,
        offline_sequence_prefix: 'INV-T01-',
        offline_sequence_next_value: 1,
        offline_sequence_status: 'active'
    },
    permissions: ['create_sale'],
    tax_configuration_version_hash: 'abc-hash-123',
    catalog_version_hash: 'catalog-hash-123',
    layout_version_hash: 'layout-hash-123',
    discount_rules_version_hash: 'discount-hash-123',
    payment_methods_version_hash: 'payment-hash-123',
    terminal_policy_version_hash: 'policy-hash-123',
    printer_profile_version_hash: 'printer-hash-123',
    config_snapshot_hash: 'snapshot-hash-123',
    config_snapshot: {
        schema_version: 1,
        config_snapshot_hash: 'snapshot-hash-123',
        layout_version_hash: 'layout-hash-123',
        catalog_version_hash: 'catalog-hash-123',
        tax_configuration_version_hash: 'abc-hash-123',
    },
    generated_at: new Date().toISOString(),
    cache_ttl_seconds: 3600
};

test('Frontend catalogCache functionality', async (t) => {

    await t.test('cache writes bootstrap payload successfully', async () => {
        await catalogCache.writeBootstrapPayload(mockPayload);
        const cached = await catalogCache.getCachedCatalog();
        
        assert.strictEqual(cached.tenant_context.id, 'tenant-1');
        assert.strictEqual(cached.products.length, 1);
        assert.strictEqual(cached.products[0].name, 'Product A');
    });

    await t.test('cache reads stored products/categories', async () => {
        await catalogCache.writeBootstrapPayload(mockPayload);
        const cached = await catalogCache.getCachedCatalog();

        assert.strictEqual(cached.products.length, 1);
        assert.strictEqual(cached.products[0].name, 'Product A');
        assert.strictEqual(cached.categories.length, 1);
        assert.strictEqual(cached.categories[0].name, 'Category X');
    });

    await t.test('cached POS products filter by UUID category and display name', async () => {
        const categoryId = 'category-uuid-1';
        const cachedProducts = [
            {
                product_id: 'product-1',
                product_category_id: categoryId,
                display_name: 'Iced Americano',
                sku: 'COF-ICE',
            },
            {
                product_id: 'product-2',
                product_category_id: 'category-uuid-2',
                display_name: 'Hot Tea',
                sku: 'TEA-HOT',
            },
        ];

        assert.deepStrictEqual(
            filterCachedProducts(cachedProducts, '', categoryId).map((product) => product.product_id),
            ['product-1']
        );

        assert.deepStrictEqual(
            filterCachedProducts(cachedProducts, 'americano', null).map((product) => product.product_id),
            ['product-1']
        );

        assert.deepStrictEqual(
            filterCachedProducts([
                {
                    product_id: 'product-3',
                    category_name: 'Beverages',
                    display_name: 'Lemonade',
                },
                {
                    product_id: 'product-4',
                    category_name: 'Snacks',
                    display_name: 'Chips',
                },
            ], '', { id: 'beverages-id', name: 'Beverages' }).map((product) => product.product_id),
            ['product-3']
        );
    });

    await t.test('cache preserves tax hash', async () => {
        await catalogCache.writeBootstrapPayload(mockPayload);
        const taxHash = await catalogCache.getTaxHash();
        assert.strictEqual(taxHash, 'abc-hash-123');
    });

    await t.test('cache preserves config snapshot metadata', async () => {
        await catalogCache.writeBootstrapPayload(mockPayload);
        const cached = await catalogCache.getCachedCatalog();
        const snapshot = await catalogCache.getConfigSnapshotMetadata();

        assert.strictEqual(cached.config_snapshot_hash, 'snapshot-hash-123');
        assert.strictEqual(cached.payment_methods_version_hash, 'payment-hash-123');
        assert.strictEqual(snapshot.config_snapshot_hash, 'snapshot-hash-123');
        assert.strictEqual(snapshot.layout_version_hash, 'layout-hash-123');
        assert.strictEqual(snapshot.catalog_version_hash, 'catalog-hash-123');
        assert.strictEqual(snapshot.tax_configuration_version_hash, 'abc-hash-123');
        assert.strictEqual(snapshot.config_snapshot.config_snapshot_hash, 'snapshot-hash-123');
    });

    await t.test('layout hash update keeps nested config snapshot aligned', async () => {
        await catalogCache.writeBootstrapPayload(mockPayload);
        await catalogCache.updateLayoutVersionHash('layout-hash-456');

        const snapshot = await catalogCache.getConfigSnapshotMetadata();

        assert.strictEqual(snapshot.layout_version_hash, 'layout-hash-456');
        assert.strictEqual(snapshot.config_snapshot.layout_version_hash, 'layout-hash-456');
    });

    await t.test('stale cache can be detected via hash mismatch', async () => {
        await catalogCache.writeBootstrapPayload(mockPayload);
        
        // Match -> Should not be stale
        const match = await catalogCache.isStale('abc-hash-123');
        assert.strictEqual(match, false);

        // Mismatch -> Should be stale
        const mismatch = await catalogCache.isStale('different-hash');
        assert.strictEqual(mismatch, true);
    });

    await t.test('stale cache can be detected via TTL expiry', async () => {
        const expiredPayload = {
            ...mockPayload,
            generated_at: new Date(Date.now() - 5000 * 1000).toISOString(), // 5000s ago
            cache_ttl_seconds: 3600 // Expired after 3600s
        };

        await catalogCache.writeBootstrapPayload(expiredPayload);
        const stale = await catalogCache.isStale();
        assert.strictEqual(stale, true);
    });

    await t.test('offline readiness resolves when bootstrap cache includes controlled offline settings', async () => {
        await catalogCache.writeBootstrapPayload(mockPayload);
        const readiness = await resolveOfflineCaptureReadiness();

        assert.strictEqual(readiness.allowed, true);
        assert.strictEqual(readiness.reason, 'allowed');
    });

    await t.test('no API sale creation occurs while offline unless controlled offline capture is available', async () => {
        await catalogCache.writeBootstrapPayload(mockPayload);

        // Set network status to offline
        mockNavigator.onLine = false;
        globalState.status = 'offline';
        
        assert.strictEqual(isOffline(), true);

        await assert.doesNotReject(async () => {
            await validateCheckoutAllowed();
        });

        await catalogCache.writeBootstrapPayload({
            ...mockPayload,
            machine_profile_context: {
                ...mockPayload.machine_profile_context,
                offline_sequence_prefix: null,
            },
        });

        await assert.doesNotReject(async () => {
            await validateCheckoutAllowed();
        });

        // Restore network status
        mockNavigator.onLine = true;
        globalState.status = 'online';
        assert.strictEqual(isOffline(), false);
        await assert.doesNotReject(async () => {
            await validateCheckoutAllowed();
        });
    });
});
