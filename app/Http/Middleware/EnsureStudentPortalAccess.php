<?php

namespace App\Http\Middleware;

use App\Models\Student;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureStudentPortalAccess
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user =
            $request->user();

        abort_unless(
            $user instanceof User,
            401
        );

        abort_unless(
            $user->systemRole()
                === SystemRole::Student,
            403,
            'Student Portal access requires a Student account.'
        );

        abort_if(
            $user->center_id === null
                || $user->person_id === null,
            403,
            'The Student account does not have a valid Center identity.'
        );

        /*
         * Student Portal access requires an operational Student
         * record explicitly linked to the authenticated User.
         *
         * Matching Person and Center as well prevents stale or
         * inconsistent account linkage from silently expanding
         * the authenticated Student scope.
         */
        $student =
            Student::query()
            ->withoutGlobalScopes()
            ->where(
                'center_id',
                $user->center_id
            )
            ->where(
                'person_id',
                $user->person_id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->first();

        abort_if(
            $student === null
                || ! $student->isActive(),
            403,
            'The authenticated account does not have an active linked Student record.'
        );

        return $next(
            $request
        );
    }
}
