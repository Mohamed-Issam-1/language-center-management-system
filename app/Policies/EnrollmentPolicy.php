<?php

namespace App\Policies;

use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

class EnrollmentPolicy
{
    public function create(
        User $user,
        Student $student,
        CourseClass $courseClass
    ): bool {
        if (
            $student->center_id
            !== $courseClass->center_id
        ) {
            return false;
        }

        return $this->canManageBranches(
            $user,
            $student->center_id,
            $student->branch_id,
            $courseClass->branch_id
        );
    }

    public function updateStatus(
        User $user,
        Enrollment $enrollment
    ): bool {
        return $this->canManageEnrollment(
            $user,
            $enrollment
        );
    }

    public function withdraw(
        User $user,
        Enrollment $enrollment
    ): bool {
        return $this->canManageEnrollment(
            $user,
            $enrollment
        );
    }

    public function transfer(
        User $user,
        Enrollment $enrollment,
        CourseClass $targetClass
    ): bool {
        if (
            $enrollment->center_id
            !== $targetClass->center_id
        ) {
            return false;
        }

        $student =
            $enrollment->student;

        $sourceClass =
            $enrollment->courseClass;

        if (
            $student === null
            || $sourceClass === null
        ) {
            return false;
        }

        if (
            $student->center_id
            !== $enrollment->center_id
            || $sourceClass->center_id
            !== $enrollment->center_id
        ) {
            return false;
        }

        if (
            ! $user->hasPermission(
                SystemPermission::ManageEnrollments
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $enrollment->center_id
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            true,

            /*
             * Branch Manager transfer operations cannot
             * cross the assigned Branch boundary.
             *
             * Student, source Class, and destination Class
             * must all remain inside the exact assigned Branch.
             */
            SystemRole::BranchManager =>
            $student->branch_id
                === $sourceClass->branch_id
                && $sourceClass->branch_id
                === $targetClass->branch_id
                && $this->hasActiveBranchAccess(
                    $user,
                    $enrollment->center_id,
                    $sourceClass->branch_id
                ),

            default => false,
        };
    }

    private function canManageEnrollment(
        User $user,
        Enrollment $enrollment
    ): bool {
        $student =
            $enrollment->student;

        $courseClass =
            $enrollment->courseClass;

        if (
            $student === null
            || $courseClass === null
        ) {
            return false;
        }

        if (
            $student->center_id
            !== $enrollment->center_id
            || $courseClass->center_id
            !== $enrollment->center_id
        ) {
            return false;
        }

        return $this->canManageBranches(
            $user,
            $enrollment->center_id,
            $student->branch_id,
            $courseClass->branch_id
        );
    }

    private function canManageBranches(
        User $user,
        int $centerId,
        int $studentBranchId,
        int $classBranchId
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageEnrollments
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $centerId
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            /*
             * Center Owner is Center-wide and may manage
             * Enrollment records anywhere inside the Center.
             */
            SystemRole::CenterOwner =>
            true,

            /*
             * Branch Manager may operate only when both the
             * Student and the Class are inside the exact Branch
             * currently assigned to that account.
             */
            SystemRole::BranchManager =>
            $studentBranchId
                === $classBranchId
                && $this->hasActiveBranchAccess(
                    $user,
                    $centerId,
                    $studentBranchId
                ),

            default => false,
        };
    }

    private function hasActiveBranchAccess(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        return $user
            ->activeBranchManagerAssignment()
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
