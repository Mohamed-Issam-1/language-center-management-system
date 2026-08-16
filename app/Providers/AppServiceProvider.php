<?php

namespace App\Providers;

use App\Models\Center;
use App\Models\User;
use App\Policies\CenterPolicy;
use App\Support\Enums\SystemPermission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(
            Center::class,
            CenterPolicy::class
        );

        /*
         * Register every fixed system permission as a Laravel Gate.
         *
         * Gates validate only the role-level capability.
         * Tenant, branch, class, record ownership, and other
         * operational scopes remain independently enforceable by
         * policies, scoped queries, middleware, and services.
         */
        foreach (
            SystemPermission::cases() as $permission
        ) {
            Gate::define(
                $permission->value,
                fn(User $user): bool => $user
                    ->hasPermission($permission)
            );
        }

        Vite::prefetch(concurrency: 3);
    }
}
