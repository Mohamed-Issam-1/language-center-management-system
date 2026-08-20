<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

class StudentPolicy
{
    public function viewAny(
        User $user
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewStudentRecords
            )
        ) {
            return false;
        }

        if ($user->center_id === null) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner => true,

            /*
             * A Branch Manager may list Student records only
             * while holding an active Branch assignment.
             *
             * The actual query must additionally use the
             * branch-scoped Student query so records from other
             * Branches cannot appear.
             */
            SystemRole::BranchManager =>
                $user->activeBranchManagerAssignment()
                    ->where(
                        'center_id',
                        $user->center_id
                    )
                    ->exists(),

            /*
             * Teacher Student-record access is class-scoped.
             * That domain does not exist yet, so it remains
             * fail-closed here.
             *
             * Student accounts may view their own record through
             * view(), but may not list Student records.
             */
            default => false,
        };
    }

    public function view(
        User $user,
        Student $student
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewStudentRecords
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id !== $student->center_id
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner => true,

            SystemRole::BranchManager =>
                $this->hasActiveBranchAccess(
                    $user,
                    $student->center_id,
                    $student->branch_id
                ),

            /*
             * A Student account may view only the Student record
             * belonging to the same Person inside the same Center.
             *
             * This remains valid even before user_id linkage has
             * been completed because Person is the shared identity.
             */
            SystemRole::Student =>
                $user->person_id !== null
                && $user->person_id === $student->person_id,

            /*
             * Teacher visibility must eventually depend on an
             * assigned Class. Until that domain exists, granting
             * general Student-record access would be too broad.
             */
            SystemRole::Teacher => false,

            default => false,
        };
    }

    public function create(
        User $user,
        Branch $branch
    ): bool {
        return $this->canManageBranch(
            $user,
            $branch->center_id,
            $branch->id
        );
    }

    public function update(
        User $user,
        Student $student
    ): bool {
        return $this->canManageBranch(
            $user,
            $student->center_id,
            $student->branch_id
        );
    }

    public function archive(
        User $user,
        Student $student
    ): bool {
        return $this->canManageBranch(
            $user,
            $student->center_id,
            $student->branch_id
        );
    }

    public function restore(
        User $user,
        Student $student
    ): bool {
        return $this->canManageBranch(
            $user,
            $student->center_id,
            $student->branch_id
        );
    }

    private function canManageBranch(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageStudentRecords
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id !== $centerId
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner => true,

            SystemRole::BranchManager =>
                $this->hasActiveBranchAccess(
                    $user,
                    $centerId,
                    $branchId
                ),

            default => false,
        };
    }

    private function hasActiveBranchAccess(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        return $user->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'branch_id',
                $branchId
            )
            ->exists();
    }
}