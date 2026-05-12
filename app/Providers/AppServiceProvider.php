<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\TenantContext::class);
        $this->app->singleton(\App\Services\BranchContext::class);
        $this->app->singleton(\App\Services\SupportContext::class);
        $this->app->singleton(\App\Services\Observability\RequestCorrelation::class);
        $this->app->bind(
            \App\Services\Accounting\Contracts\AccountingMapperInterface::class,
            \App\Services\Accounting\AccountingMappingService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
