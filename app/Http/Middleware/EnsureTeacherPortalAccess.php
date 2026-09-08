<?php

namespace App\Http\Middleware;

use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTeacherPortalAccess
{
    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $authenticatedUser =
            $request->user();

        abort_unless(
            $authenticatedUser instanceof User,
            401
        );

        /*
         * Never trust mutable or stale in-memory authentication
         * state for the Teacher Portal boundary.
         *
         * Re-read the authenticated account so account
         * deactivation, role changes, Person changes, or Center
         * changes take effect immediately.
         */
        abort_if(
            ! $authenticatedUser->exists
                || $authenticatedUser->getKey() === null,
            403,
            'The Teacher User Account is unavailable.'
        );

        $user =
            User::query()
            ->withoutGlobalScopes()
            ->with(
                'role'
            )
            ->whereKey(
                $authenticatedUser->getKey()
            )
            ->first();

        abort_if(
            $user === null,
            403,
            'The Teacher User Account could not be resolved.'
        );

        abort_unless(
            $user->status
                === AccountStatus::Active,
            403,
            'Teacher Portal access requires an Active User Account.'
        );

        abort_unless(
            $user->systemRole()
                === SystemRole::Teacher,
            403,
            'Teacher Portal access requires a Teacher account.'
        );

        /*
         * tenant.context must already have established an exact
         * Center scope for production Teacher Portal routes.
         */
        abort_unless(
            $this->tenant->isCenterScoped(),
            403,
            'Teacher Portal access requires a Center-scoped tenant context.'
        );

        $centerId =
            $this->tenant->centerId();

        abort_if(
            $centerId === null
                || $user->center_id !== $centerId
                || $user->person_id === null,
            403,
            'The Teacher account does not match the active Center identity.'
        );

        /*
         * Require the exact persisted operational linkage:
         *
         * User
         *   -> same Center
         *   -> same Person
         *   -> same Teacher.user_id
         *   -> Active Teacher record
         *
         * Person-only or role-only linkage is not sufficient.
         */
        $hasActiveTeacherRecord =
            Teacher::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'person_id',
                $user->person_id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'status',
                StaffStatus::Active->value
            )
            ->exists();

        abort_unless(
            $hasActiveTeacherRecord,
            403,
            'The authenticated account does not have an active linked Teacher record.'
        );

        return $next(
            $request
        );
    }
}
