<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\Center;
use App\Models\User;
use App\Policies\BranchPolicy;
use App\Policies\CenterPolicy;
use App\Support\Enums\SystemPermission;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
        * Tenant and operational contexts belong to the
        * current request lifecycle.
        *
        * They must remain request-scoped so a long-lived
        * worker cannot leak tenant or operational state
        * between requests.
        */
        $this->app->scoped(
            TenantContext::class,
            fn(): TenantContext => new TenantContext()
        );

        $this->app->scoped(
            BranchContext::class,
            fn(): BranchContext => new BranchContext()
        );
    }

    public function boot(): void
    {
        Gate::policy(
            Center::class,
            CenterPolicy::class
        );
        Gate::policy(
            Branch::class,
            BranchPolicy::class
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
