<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\User;
use App\Models\Student;
use App\Models\AcademicLevel;
use App\Models\Course;
use App\Models\Language;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Policies\BranchPolicy;
use App\Policies\CenterPolicy;
use App\Policies\ClassroomPolicy;
use App\Policies\StudentPolicy;
use App\Policies\AcademicLevelPolicy;
use App\Policies\CoursePolicy;
use App\Policies\LanguagePolicy;
use App\Policies\CourseClassPolicy;
use App\Policies\EnrollmentPolicy;
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

        Gate::policy(
            Classroom::class,
            ClassroomPolicy::class
        );

        Gate::policy(
            Language::class,
            LanguagePolicy::class
        );

        Gate::policy(
            AcademicLevel::class,
            AcademicLevelPolicy::class
        );

        Gate::policy(
            Course::class,
            CoursePolicy::class
        );

        Gate::policy(
            Student::class,
            StudentPolicy::class
        );

        Gate::policy(
            Course::class,
            CoursePolicy::class
        );

        Gate::policy(
            CourseClass::class,
            CourseClassPolicy::class
        );

        Gate::policy(
            Enrollment::class,
            EnrollmentPolicy::class
        );

        Gate::policy(
            Student::class,
            StudentPolicy::class
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
