const CACHE_NAME = 'ipos-terminal-shell-v1';

// We only cache the app shell and basic static assets.
// We explicitly DO NOT cache API endpoints or admin routes.
const ASSETS_TO_CACHE = [
    '/pos/terminal/checkout',
    '/manifest.json',
    // In a real build, we'd inject the Vite asset manifest here.
    // We rely on standard browser caching for Vite assets since they are hashed.
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE).catch(err => {
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

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // 1. Never intercept POST, PUT, DELETE, etc.
    if (event.request.method !== 'GET') {
        return;
    }

    // 2. Never cache or intercept API endpoints, admin routes, reports, etc.
    if (
        url.pathname.startsWith('/api') ||
        url.pathname.startsWith('/admin') ||
        url.pathname.startsWith('/reports') ||
        url.pathname.startsWith('/settings') ||
        url.pathname.includes('/checkout/create-sale') ||
        url.pathname.includes('/checkout/status') ||
        url.pathname.includes('/checkout/validate') ||
        url.pathname.includes('record-cash-event')
    ) {
        return;
    }

    // 3. For inertia page navigations (which use X-Inertia headers), 
    // we want network-first because they contain dynamic state.
    if (event.request.headers.get('X-Inertia')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                return caches.match(event.request);
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
                });
            })
        );
        return;
    }

    // 5. Default network-first for everything else.
    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request);
        })
    );
});
