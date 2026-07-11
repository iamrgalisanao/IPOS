<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Favicon -->
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead

        <script>
            window.IPOS_CONTEXT = {
                tenantId: "{{ $page['props']['tenant_id'] ?? '' }}",
                branchId: "{{ $page['props']['branch_id'] ?? '' }}",
                terminalId: "{{ $page['props']['terminal_id'] ?? '' }}"
            };
        </script>
        
        @if(request()->is('pos') || request()->is('pos/*'))
            <!-- Tablet POS PWA Requirements -->
            <link rel="manifest" href="/manifest.json">
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
            <script>
                (function() {
                    if (!('serviceWorker' in navigator)) {
                        window.IPOS_SERVICE_WORKER_READY = false;
                        return;
                    }

                    var posServiceWorkerVersion = 'ipos-terminal-shell-v31-20260711';
                    var reloadSessionKey = 'ipos_sw_reloaded_' + posServiceWorkerVersion;
                    var registerPosServiceWorker = function() {
                        return navigator.serviceWorker.register('/sw.js', { scope: '/' });
                    };

                    window.IPOS_SERVICE_WORKER_READY = Boolean(navigator.serviceWorker.controller);

                    if (!navigator.onLine && navigator.serviceWorker.controller) {
                        return;
                    }

                    navigator.serviceWorker.getRegistrations().then(function(registrations) {
                            var unregisterScopedWorkers = registrations
                                .filter(function(registration) {
                                    return registration.scope !== window.location.origin + '/';
                                })
                                .map(function(registration) {
                                    return registration.unregister();
                                });

                            return Promise.all(unregisterScopedWorkers).then(registerPosServiceWorker);
                        })
                            .then(function(registration) {
                                console.log('POS Terminal ServiceWorker registration successful with scope: ', registration.scope);
                                if (navigator.onLine) {
                                    registration.update().catch(function(err) {
                                        console.info('POS Terminal ServiceWorker update skipped:', err);
                                    });
                                }

                                if (registration.waiting) {
                                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                                }

                                navigator.serviceWorker.ready.then(function() {
                                    window.IPOS_SERVICE_WORKER_READY = Boolean(navigator.serviceWorker.controller);

                                    if (!navigator.serviceWorker.controller && navigator.onLine && sessionStorage.getItem(reloadSessionKey) !== '1') {
                                        sessionStorage.setItem(reloadSessionKey, '1');
                                        window.location.reload();
                                    }
                                });

                                registration.addEventListener('updatefound', function() {
                                    var nextWorker = registration.installing;
                                    if (!nextWorker) return;

                                    nextWorker.addEventListener('statechange', function() {
                                        if (nextWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                            nextWorker.postMessage({ type: 'SKIP_WAITING' });
                                        }
                                    });
                                });
                            }, function(err) {
                                console.log('POS Terminal ServiceWorker registration failed: ', err);
                                window.IPOS_SERVICE_WORKER_READY = false;
                            });

                        navigator.serviceWorker.addEventListener('controllerchange', function() {
                            window.IPOS_SERVICE_WORKER_READY = true;

                            if (sessionStorage.getItem(reloadSessionKey) === '1') {
                                return;
                            }

                            sessionStorage.setItem(reloadSessionKey, '1');
                            window.location.reload();
                        });
                })();
            </script>
        @endif
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
