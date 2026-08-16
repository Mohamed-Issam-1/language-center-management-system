<?php

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Tenant context belongs to the current request lifecycle.
         *
         * It must not be a global singleton because a long-lived
         * worker must never carry one center's context into another
         * request.
         */
        $this->app->scoped(
            TenantContext::class,
            fn(): TenantContext => new TenantContext()
        );
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
