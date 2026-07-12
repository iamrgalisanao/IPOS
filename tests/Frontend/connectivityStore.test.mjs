import { test } from 'node:test';
import assert from 'node:assert/strict';
import axios from 'axios';

Object.defineProperty(global, 'navigator', {
    value: { onLine: true },
    configurable: true,
    writable: true,
});

const { checkConnectivity, globalState } = await import('../../resources/js/POS/offline/connectivityStore.ts');

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
