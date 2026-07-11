const CACHE_NAME = 'ipos-terminal-shell-v31-20260711';
const VITE_MANIFEST_URL = '/build/manifest.json';
const POS_SHELL_URL = '/pos/terminal/checkout';
const POS_LEGACY_SHELL_URL = '/pos';
const POS_TERMINAL_PREFIX = '/pos/terminal/';
const POS_SHELL_CACHE_KEY = '__ipos_pos_terminal_shell__';
const SW_HEALTH_URL = '/__ipos-sw-health';

// We only cache the app shell and basic static assets.
// We explicitly DO NOT cache API endpoints or admin routes.
const SHELL_ASSETS_TO_CACHE = [
    POS_SHELL_URL,
    POS_LEGACY_SHELL_URL,
    '/manifest.json',
    '/favicon.svg',
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cacheUrls(cache, SHELL_ASSETS_TO_CACHE)
                .then(() => cacheVitePosAssets(cache))
                .catch(err => {
                    console.warn('Failed to cache some assets during install:', err);
                });
        })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        return caches.delete(cache);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // 1. Never intercept POST, PUT, DELETE, etc.
    if (event.request.method !== 'GET') {
        return;
    }

    if (url.pathname === SW_HEALTH_URL) {
        event.respondWith(new Response(JSON.stringify({
            controlled: true,
            cacheName: CACHE_NAME,
        }), {
            status: 200,
            headers: new Headers({ 'Content-Type': 'application/json' })
        }));
        return;
    }

    if (isPosTerminalNavigation(event.request, url)) {
        event.respondWith(handlePosTerminalNavigation(event.request));
        return;
    }

    // 2. With root scope, keep this worker POS-only.
    if (!isPosRuntimeAsset(url)) {
        return;
    }

    // 3. For the POS shell and inertia page navigations, use network-first.
    // If the backend is down, fall back to the latest cached shell.
    if (url.pathname === POS_SHELL_URL || event.request.headers.get('X-Inertia')) {
        event.respondWith(
            fetch(event.request).then((networkResponse) => {
                if (networkResponse && networkResponse.status === 200) {
                    const responseToCache = networkResponse.clone();
                    const shellResponseToCache = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        return Promise.all([
                            cache.put(event.request, responseToCache),
                            cache.put(POS_SHELL_CACHE_KEY, shellResponseToCache),
                        ]);
                    }).catch((err) => {
                        console.warn('Failed to cache POS shell response:', err);
                    });
                }

                return networkResponse;
            }).catch(() => {
                if (url.pathname === POS_SHELL_URL) {
                    return matchPosShell(event.request);
                }

                return matchCacheOrOffline(event.request);
            })
        );
        return;
    }

    // 4. For static assets (build/assets), cache-first.
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                if (cachedResponse) {
                    return cachedResponse;
                }
                return fetch(event.request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const responseToCache = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, responseToCache);
                        });
                    }
                    return networkResponse;
                }).catch(() => {
                    return matchCacheOrOffline(event.request);
                });
            })
        );
        return;
    }

    // 5. Default network-first for everything else.
    event.respondWith(
        fetch(event.request).catch(() => {
            return matchCacheOrOffline(event.request);
        })
    );
});

function isPosTerminalNavigation(request, url) {
    if (request.mode !== 'navigate') {
        return false;
    }

    return url.pathname === POS_SHELL_URL ||
        url.pathname === POS_LEGACY_SHELL_URL ||
        url.pathname.startsWith(POS_TERMINAL_PREFIX) ||
        url.pathname === '/checkout';
}

function isPosRuntimeAsset(url) {
    return url.pathname.startsWith('/build/assets/') ||
        url.pathname === VITE_MANIFEST_URL ||
        url.pathname === '/manifest.json' ||
        url.pathname === '/favicon.svg' ||
        url.pathname === SW_HEALTH_URL ||
        url.pathname === POS_SHELL_URL ||
        url.pathname === POS_LEGACY_SHELL_URL ||
        url.pathname.startsWith(POS_TERMINAL_PREFIX);
}

async function handlePosTerminalNavigation(request) {
    try {
        const networkResponse = await fetch(request);

        if (networkResponse && networkResponse.ok) {
            const cache = await caches.open(CACHE_NAME);
            const requestResponse = networkResponse.clone();
            const canonicalShellResponse = networkResponse.clone();
            const legacyShellResponse = new URL(request.url).pathname === POS_LEGACY_SHELL_URL
                ? networkResponse.clone()
                : null;
            const shellKeyResponse = networkResponse.clone();

            await cache.put(request, requestResponse);
            await cache.put(POS_SHELL_URL, canonicalShellResponse);
            if (new URL(request.url).pathname === POS_LEGACY_SHELL_URL) {
                await cache.put(POS_LEGACY_SHELL_URL, legacyShellResponse);
            }
            await cache.put(POS_SHELL_CACHE_KEY, shellKeyResponse);
        }

        return networkResponse;
    } catch (err) {
        return matchPosShell(request);
    }
}

async function matchPosShell(request) {
    const cachedResponse = await caches.match(request);

    if (cachedResponse) {
        return cachedResponse;
    }

    const cachedShell = await caches.match(POS_SHELL_URL) ||
        await caches.match(POS_LEGACY_SHELL_URL) ||
        await caches.match(POS_SHELL_CACHE_KEY);

    if (cachedShell) {
        return cachedShell;
    }

    return new Response(createOfflineShellUnavailableHtml(), {
        status: 503,
        statusText: 'Service Unavailable',
        headers: new Headers({ 'Content-Type': 'text/html; charset=utf-8' })
    });
}

function matchCacheOrOffline(request) {
    return caches.match(request).then((cachedResponse) => {
        if (cachedResponse) {
            return cachedResponse;
        }
        const isJson = request.headers.get('Accept') && request.headers.get('Accept').includes('application/json');
        if (isJson) {
            return new Response(JSON.stringify({ error: 'Network connection offline', offline: true }), {
                status: 503,
                statusText: 'Service Unavailable',
                headers: new Headers({ 'Content-Type': 'application/json' })
            });
        }
        return new Response('Network connection offline', {
            status: 503,
            statusText: 'Service Unavailable',
            headers: new Headers({ 'Content-Type': 'text/plain' })
        });
    });
}

function createOfflineShellUnavailableHtml() {
    return `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IPOS Terminal Offline</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #020617;
            color: #e5e7eb;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        main {
            max-width: 32rem;
            padding: 2rem;
            text-align: center;
        }
        h1 {
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
        }
        p {
            color: #94a3b8;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <main>
        <h1>POS terminal shell is not cached yet.</h1>
        <p>Reconnect once and open the POS terminal so offline refresh can use the cached app shell.</p>
    </main>
</body>
</html>`;
}

async function cacheVitePosAssets(cache) {
    const response = await fetch(VITE_MANIFEST_URL, { cache: 'no-store' });
    if (!response.ok) {
        return;
    }

    const manifest = await response.json();
    const assets = collectManifestAssets(manifest, [
        'resources/js/app.jsx',
        'resources/js/Pages/POS/Index.jsx',
        'resources/js/Pages/POS/Terminal/Checkout.jsx',
    ]);

    await cacheUrls(cache, [VITE_MANIFEST_URL, ...assets]);
}

function collectManifestAssets(manifest, entryKeys) {
    const visited = new Set();
    const assets = new Set();

    function shouldCacheDynamicImport(key) {
        return key === 'resources/js/Pages/POS/Index.jsx' ||
            key === 'resources/js/Pages/POS/Terminal/Checkout.jsx' ||
            key.startsWith('_Cart-') ||
            key.startsWith('_ConnectivityBanner-') ||
            key.startsWith('_FailureGuardianBanner-') ||
            key.startsWith('_ProductGrid-') ||
            key.startsWith('_Receipt-') ||
            key.startsWith('_SearchBar-') ||
            key.startsWith('_SplitPayWizard-') ||
            key.startsWith('_StatusUncertainPanel-') ||
            key.startsWith('_TerminalLockScreen-');
    }

    function visit(key, includeDynamicImports = true) {
        if (!key || visited.has(key)) {
            return;
        }

        visited.add(key);
        const entry = manifest[key];
        if (!entry) {
            return;
        }

        if (entry.file) {
            assets.add(`/build/${entry.file}`);
        }

        for (const cssFile of entry.css || []) {
            assets.add(`/build/${cssFile}`);
        }

        for (const importKey of entry.imports || []) {
            visit(importKey);
        }

        if (includeDynamicImports) {
            for (const importKey of entry.dynamicImports || []) {
                if (shouldCacheDynamicImport(importKey)) {
                    visit(importKey);
                }
            }
        }
    }

    for (const key of entryKeys) {
        visit(key);
    }

    return Array.from(assets);
}

async function cacheUrls(cache, urls) {
    await Promise.all(urls.map(async (url) => {
        try {
            const response = await fetch(url, { cache: 'reload' });
            if (response && response.ok) {
                await cache.put(url, response.clone());
            }
        } catch (err) {
            console.warn('Failed to cache asset:', url, err);
        }
    }));
}
