import { test } from 'node:test';
import assert from 'node:assert/strict';
import axios from 'axios';

Object.defineProperty(global, 'navigator', {
    value: { onLine: true },
    configurable: true,
    writable: true,
});

const { checkConnectivity, globalState, refreshConfiguration } = await import('../../resources/js/POS/offline/connectivityStore.ts');
const { catalogCache } = await import('../../resources/js/POS/offline/catalogCache.ts');

const validPayload = {
    products: [],
    categories: [],
    tax_categories: [],
    payment_methods: [],
    permissions: [],
    tenant_context: { id: 'tenant-1' },
    branch_context: { id: 'branch-1' },
    machine_profile_context: { id: 'terminal-1' },
    tax_configuration_version_hash: 'tax-1',
    catalog_version_hash: 'catalog-1',
    config_snapshot_hash: 'snapshot-1',
    config_snapshot: { config_snapshot_hash: 'snapshot-1' },
    generated_at: new Date().toISOString(),
    cache_ttl_seconds: 3600,
};

test('connectivity starts in checking state until backend reachability is proven', async () => {
    assert.strictEqual(globalState.status, 'checking');

    let calls = 0;
    axios.get = async () => {
        calls += 1;
        throw new Error('Network Error');
    };

    const reachable = await checkConnectivity();

    assert.strictEqual(reachable, false);
    assert.strictEqual(calls, 1);
    assert.strictEqual(globalState.status, 'offline');
});

test('expected backend reachability failures do not emit console errors', async () => {
    globalState.status = 'checking';

    const originalConsoleError = console.error;
    const messages = [];
    console.error = (...args) => {
        messages.push(args);
    };

    axios.get = async () => {
        const error = new Error('Network Error');
        error.request = {};
        throw error;
    };

    try {
        await checkConnectivity();
    } finally {
        console.error = originalConsoleError;
    }

    assert.deepStrictEqual(messages, []);
    assert.strictEqual(globalState.status, 'offline');
});

test('protected bootstrap rejection marks terminal context invalid', async () => {
    globalState.status = 'checking';
    globalState.terminalContextInvalid = false;
    axios.get = async () => {
        const error = new Error('Forbidden');
        error.response = {
            status: 403,
            data: { code: 'TERMINAL_CONTEXT_INVALID' },
        };
        throw error;
    };

    const reachable = await checkConnectivity();
    assert.strictEqual(reachable, false);
    assert.strictEqual(globalState.status, 'offline');
    assert.strictEqual(globalState.terminalContextInvalid, true);
});

test('connectivity check reports stale configuration without replacing it', async () => {
    global.navigator.onLine = true;
    globalState.status = 'online';
    let writes = 0;
    axios.get = async () => ({ status: 200, data: validPayload });
    catalogCache.getConfigSnapshotMetadata = async () => ({
        config_snapshot_hash: 'previous-snapshot',
        generated_at: '2026-07-12T07:00:00.000Z',
    });
    catalogCache.isStale = async () => false;
    catalogCache.writeBootstrapPayload = async () => {
        writes += 1;
    };

    const reachable = await checkConnectivity(undefined, { force: true });

    assert.strictEqual(reachable, true);
    assert.strictEqual(writes, 0);
    assert.strictEqual(globalState.isStale, true);
    assert.strictEqual(globalState.refreshResult.status, 'stale');
    assert.strictEqual(globalState.lastSnapshotHash, 'previous-snapshot');
});

test('fresh connectivity check clears a prior stale result', async () => {
    global.navigator.onLine = true;
    globalState.status = 'online';
    globalState.refreshResult = {
        status: 'stale',
        message: 'Old stale result',
        generatedAt: null,
        snapshotHash: null,
        completedAt: new Date().toISOString(),
    };
    axios.get = async () => ({ status: 200, data: validPayload });
    catalogCache.getConfigSnapshotMetadata = async () => ({
        config_snapshot_hash: validPayload.config_snapshot_hash,
        generated_at: validPayload.generated_at,
    });
    catalogCache.isStale = async () => false;

    await checkConnectivity(undefined, { force: true });

    assert.strictEqual(globalState.isStale, false);
    assert.strictEqual(globalState.refreshResult.status, 'idle');
});

test('manual configuration refresh publishes success only after cache persistence', async () => {
    global.navigator.onLine = true;
    globalState.status = 'online';
    globalState.terminalContextInvalid = false;
    let persisted = false;
    catalogCache.fetchAndStoreBootstrap = async () => {
        await new Promise((resolve) => setTimeout(resolve, 5));
        persisted = true;
        return validPayload;
    };

    const pending = refreshConfiguration();
    assert.strictEqual(globalState.refreshResult.status, 'refreshing');
    assert.strictEqual(persisted, false);

    const result = await pending;
    assert.strictEqual(persisted, true);
    assert.strictEqual(result.status, 'success');
    assert.strictEqual(result.snapshotHash, 'snapshot-1');
    assert.strictEqual(globalState.lastSyncedAt, validPayload.generated_at);
});

test('concurrent manual refresh callers share one fetch and write', async () => {
    global.navigator.onLine = true;
    globalState.status = 'online';
    let calls = 0;
    let release;
    catalogCache.fetchAndStoreBootstrap = () => {
        calls += 1;
        return new Promise((resolve) => {
            release = () => resolve(validPayload);
        });
    };

    const first = refreshConfiguration();
    const second = refreshConfiguration();
    assert.strictEqual(first, second);
    assert.strictEqual(calls, 1);

    release();
    const [firstResult, secondResult] = await Promise.all([first, second]);
    assert.strictEqual(firstResult, secondResult);
    assert.strictEqual(firstResult.status, 'success');
});

test('connectivity check waits for an active configuration refresh', async () => {
    global.navigator.onLine = true;
    globalState.status = 'online';
    let release;
    let connectivityCalls = 0;
    catalogCache.fetchAndStoreBootstrap = () => new Promise((resolve) => {
        release = () => resolve(validPayload);
    });
    axios.get = async () => {
        connectivityCalls += 1;
        return { status: 200, data: validPayload };
    };

    const refresh = refreshConfiguration();
    const check = checkConnectivity(undefined, { force: true });
    release();

    const [result, reachable] = await Promise.all([refresh, check]);
    assert.strictEqual(result.status, 'success');
    assert.strictEqual(reachable, true);
    assert.strictEqual(connectivityCalls, 0);
    assert.strictEqual(globalState.refreshResult.status, 'success');
});

test('offline manual refresh explains that no download occurred', async () => {
    global.navigator.onLine = false;
    let calls = 0;
    catalogCache.fetchAndStoreBootstrap = async () => {
        calls += 1;
        return validPayload;
    };

    const result = await refreshConfiguration();

    assert.strictEqual(calls, 0);
    assert.strictEqual(result.status, 'offline');
    assert.match(result.message, /previous configuration remains available/i);
    global.navigator.onLine = true;
});

test('cache persistence failure never publishes refresh success', async () => {
    global.navigator.onLine = true;
    globalState.status = 'online';
    globalState.lastSnapshotHash = 'previous-snapshot';
    catalogCache.fetchAndStoreBootstrap = async () => {
        throw new Error('IndexedDB transaction failed');
    };

    const result = await refreshConfiguration();

    assert.strictEqual(result.status, 'failure');
    assert.strictEqual(globalState.lastSnapshotHash, 'previous-snapshot');
});

test('manual refresh preserves cached metadata when terminal context is invalid', async () => {
    global.navigator.onLine = true;
    globalState.status = 'online';
    globalState.terminalContextInvalid = false;
    globalState.lastSnapshotHash = 'previous-snapshot';
    catalogCache.fetchAndStoreBootstrap = async () => {
        const error = new Error('Forbidden');
        error.response = { status: 403, data: { code: 'TERMINAL_CONTEXT_INVALID' } };
        throw error;
    };

    const result = await refreshConfiguration();

    assert.strictEqual(result.status, 'invalid-terminal');
    assert.strictEqual(globalState.terminalContextInvalid, true);
    assert.strictEqual(globalState.lastSnapshotHash, 'previous-snapshot');
});
