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
        
        @if(request()->is('pos/terminal/*'))
            <!-- Tablet POS PWA Requirements -->
            <link rel="manifest" href="/manifest.json">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
            <script>
                if ('serviceWorker' in navigator) {
                    window.addEventListener('load', function() {
                        navigator.serviceWorker.register('/pos/terminal/sw.js', { scope: '/pos/terminal/' })
                            .then(function(registration) {
                                console.log('POS Terminal ServiceWorker registration successful with scope: ', registration.scope);
                            }, function(err) {
                                console.log('POS Terminal ServiceWorker registration failed: ', err);
                            });
                    });
                }
            </script>
        @endif
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
