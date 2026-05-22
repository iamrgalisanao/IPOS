<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            \App\Http\Middleware\AttachRequestCorrelation::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\AddSecurityHeaders::class,
        ]);

        $middleware->api(prepend: [
            \App\Http\Middleware\AttachRequestCorrelation::class,
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\AddSecurityHeaders::class,
        ]);

        $middleware->alias([
            'tenant' => \App\Http\Middleware\IdentifyTenantContext::class,
            'branch' => \App\Http\Middleware\IdentifyBranchContext::class,
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'support.assisted' => \App\Http\Middleware\IdentifySupportAssistedContext::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'subscription.feature' => \App\Http\Middleware\EnforceSubscriptionGate::class,
        ]);

        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\IdentifyTenantContext::class
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
