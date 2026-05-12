import { test } from 'node:test';
import assert from 'node:assert/strict';
import { useTransactionStore } from '../../resources/js/Pages/POS/hooks/useTransactionStore.js';

// Mock localStorage
const mockStorage = new Map();
global.window = {
    localStorage: {
        getItem: (key) => mockStorage.get(key) || null,
        setItem: (key, val) => mockStorage.set(key, val),
        removeItem: (key) => mockStorage.delete(key),
        clear: () => mockStorage.clear()
    }
};

test('cartDraftStorage helper', async (t) => {
    const store = useTransactionStore();

    await t.test('Correct draft key generation', () => {
        const key = store.getDraftKey({ tenantId: 'tenant-123', branchId: 'branch-456', userId: 'user-789' });
        assert.strictEqual(key, 'ipos:cart-draft:tenant-123:branch-456:user-789');
    });

    await t.test('Safe placeholder key generation for missing branch/user', () => {
        const key = store.getDraftKey({ tenantId: 'tenant-123' });
        assert.strictEqual(key, 'ipos:cart-draft:tenant-123:no-branch:anonymous');
    });

    await t.test('Persisted draft includes required safe fields and excludes cost_price', () => {
        const rawItem = {
            id: 1,
            product_id: 1,
            display_name: 'Test Item',
            sku: 'TEST-1',
            cost_price: '50.00', // Unsafe
            quickbooks_metadata: 'xyz', // Unsafe
            unit_price: '100.00',
            quantity: 2,
        };

        const safeItem = store.sanitizeCartItem(rawItem);
        assert.strictEqual(safeItem.display_name, 'Test Item');
        assert.strictEqual(safeItem.quantity, 2);
        assert.strictEqual(safeItem.unit_price, '100.00');
        assert.strictEqual(safeItem.cost_price, undefined);
        assert.strictEqual(safeItem.quickbooks_metadata, undefined);
    });

    await t.test('Adding/Updating/Removing item persists draft locally', () => {
        mockStorage.clear();
        const context = { tenantId: 't1', branchId: 'b1', userId: 'u1' };
        
        // Simulating Add/Update/Remove
        store.saveDraft(context, {
            items: [{ product_id: 1, display_name: 'Test', quantity: 1, unit_price: 100 }],
            totals: { subtotal: 100 }
        });

        const key = store.getDraftKey(context);
        const storedJson = mockStorage.get(key);
        assert.ok(storedJson, 'Draft was not saved');

        const parsed = JSON.parse(storedJson);
        assert.strictEqual(parsed.schema_version, 1);
        assert.strictEqual(parsed.tenant_id, 't1');
        assert.strictEqual(parsed.items.length, 1);
        assert.strictEqual(parsed.estimated_totals.subtotal, 100);
    });

    await t.test('Clearing cart removes persisted draft', () => {
        const context = { tenantId: 't1', branchId: 'b1', userId: 'u1' };
        store.clearDraft(context);
        
        const key = store.getDraftKey(context);
        assert.strictEqual(mockStorage.get(key), undefined);
    });

    await t.test('Valid reload/restore works when tenant, branch, and user match', () => {
        mockStorage.clear();
        const context = { tenantId: 't1', branchId: 'b1', userId: 'u1' };
        store.saveDraft(context, { items: [{ product_id: 1, quantity: 2 }] });

        const result = store.restoreDraftIfSafe(context);
        assert.strictEqual(result.success, true);
        assert.strictEqual(result.draft.items.length, 1);
    });

    await t.test('Tenant mismatch prevents restoration', () => {
        mockStorage.clear();
        store.saveDraft({ tenantId: 't1', branchId: 'b1', userId: 'u1' }, { items: [] });
        
        const result = store.restoreDraftIfSafe({ tenantId: 't2', branchId: 'b1', userId: 'u1' });
        // Since key relies on context, restoring with t2 will look for key with t2.
        // It should return no-draft
        assert.strictEqual(result.success, false);
        assert.strictEqual(result.reason, 'no-draft');

        // Let's manually force a mismatch inside the payload to test the inner check
        mockStorage.set(
            'ipos:cart-draft:t3:b3:u3',
            JSON.stringify({ schema_version: 1, tenant_id: 'bad-tenant', branch_id: 'b3', user_id: 'u3' })
        );
        const forceResult = store.restoreDraftIfSafe({ tenantId: 't3', branchId: 'b3', userId: 'u3' });
        assert.strictEqual(forceResult.success, false);
        assert.strictEqual(forceResult.reason, 'tenant-mismatch');
    });

    await t.test('Branch mismatch prevents restoration', () => {
        mockStorage.set(
            'ipos:cart-draft:t4:b4:u4',
            JSON.stringify({ schema_version: 1, tenant_id: 't4', branch_id: 'bad-branch', user_id: 'u4' })
        );
        const result = store.restoreDraftIfSafe({ tenantId: 't4', branchId: 'b4', userId: 'u4' });
        assert.strictEqual(result.success, false);
        assert.strictEqual(result.reason, 'branch-mismatch');
    });

    await t.test('User mismatch prevents restoration', () => {
        mockStorage.set(
            'ipos:cart-draft:t5:b5:u5',
            JSON.stringify({ schema_version: 1, tenant_id: 't5', branch_id: 'b5', user_id: 'bad-user' })
        );
        const result = store.restoreDraftIfSafe({ tenantId: 't5', branchId: 'b5', userId: 'u5' });
        assert.strictEqual(result.success, false);
        assert.strictEqual(result.reason, 'user-mismatch');
    });

    await t.test('Unsupported schema_version is rejected safely', () => {
        mockStorage.set(
            'ipos:cart-draft:t6:b6:u6',
            JSON.stringify({ schema_version: 2, tenant_id: 't6', branch_id: 'b6', user_id: 'u6' })
        );
        const result = store.restoreDraftIfSafe({ tenantId: 't6', branchId: 'b6', userId: 'u6' });
        assert.strictEqual(result.success, false);
        assert.strictEqual(result.reason, 'unsupported-schema');
        // ensure it was cleared safely
        assert.strictEqual(mockStorage.get('ipos:cart-draft:t6:b6:u6'), undefined);
    });

    await t.test('Corrupted JSON is handled safely', () => {
        mockStorage.set('ipos:cart-draft:t7:b7:u7', '{ bad json');
        const result = store.restoreDraftIfSafe({ tenantId: 't7', branchId: 'b7', userId: 'u7' });
        assert.strictEqual(result.success, false);
        assert.strictEqual(result.reason, 'no-draft');
    });

    await t.test('UUID generation produces valid format', () => {
        const uuid = store.generateUUID();
        assert.ok(uuid);
        assert.strictEqual(uuid.length, 36);
        assert.match(uuid, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
    });

    await t.test('UUID persists correctly in envelope when saving', () => {
        mockStorage.clear();
        const context = { tenantId: 't1', branchId: 'b1', userId: 'u1' };
        const myUUID = store.generateUUID();
        
        store.saveDraft(context, {
            items: [],
            totals: { subtotal: 0 },
            cartState: 'draft',
            clientRequestUuid: myUUID
        });

        const key = store.getDraftKey(context);
        const storedJson = mockStorage.get(key);
        const parsed = JSON.parse(storedJson);
        assert.strictEqual(parsed.client_request_uuid, myUUID);
    });

    await t.test('UUID is correctly extracted upon restore', () => {
        mockStorage.clear();
        const context = { tenantId: 't1', branchId: 'b1', userId: 'u1' };
        const myUUID = store.generateUUID();
        
        mockStorage.set(
            'ipos:cart-draft:t1:b1:u1',
            JSON.stringify({ schema_version: 1, tenant_id: 't1', branch_id: 'b1', user_id: 'u1', client_request_uuid: myUUID, items: [] })
        );

        const result = store.restoreDraftIfSafe(context);
        assert.strictEqual(result.success, true);
        assert.strictEqual(result.draft.client_request_uuid, myUUID);
    });

});
