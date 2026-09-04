<?php

namespace App\Policies;

use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Student;
use App\Models\User;
use App\Models\Branch;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

class EnrollmentFeePolicy
{
    public function view(
        User $user,
        EnrollmentFee $fee
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewFinancialData
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $fee->center_id
        ) {
            return false;
        }

        $enrollment =
            $this->enrollmentForFee(
                $fee
            );

        if ($enrollment === null) {
            return false;
        }

        $branchId =
            $this->branchIdForEnrollment(
                $enrollment
            );

        if (
            $branchId === null
            || $branchId !== $fee->branch_id
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            true,

            SystemRole::BranchManager =>
            $this->branchManagerHasAccess(
                $user,
                $fee->center_id,
                $branchId
            ),

            SystemRole::FinanceEmployee =>
            $this->financeEmployeeHasAccess(
                $user,
                $fee->center_id,
                $branchId
            ),

            SystemRole::Student =>
            $this->isEnrollmentStudent(
                $user,
                $enrollment
            ),

            default =>
            false,
        };
    }

    public function viewStudentSummary(
        User $user,
        Student $student,
        ?Branch $branch = null
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ViewFinancialData
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $student->center_id
        ) {
            return false;
        }

        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            true,

            /*
         * Student financial history may belong to an
         * older Branch after the Student moves.
         *
         * Therefore access is based on the requested
         * financial Branch, not Student.branch_id.
         */
            SystemRole::BranchManager =>
            $branch !== null
                && $branch->center_id
                === $student->center_id
                && $this->branchManagerHasAccess(
                    $user,
                    $student->center_id,
                    $branch->id
                ),

            SystemRole::FinanceEmployee =>
            $branch !== null
                && $branch->center_id
                === $student->center_id
                && $this->financeEmployeeHasAccess(
                    $user,
                    $student->center_id,
                    $branch->id
                ),

            SystemRole::Student =>
            $student->user_id !== null
                && $student->user_id
                === $user->id,

            default =>
            false,
        };
    }

    public function create(
        User $user,
        Enrollment $enrollment
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageFinancialOperations
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

        $branchId =
            $this->branchIdForEnrollment(
                $enrollment
            );

        if ($branchId === null) {
            return false;
        }

        return $this->canManageBranch(
            $user,
            $enrollment->center_id,
            $branchId
        );
    }

    public function void(
        User $user,
        EnrollmentFee $fee
    ): bool {
        if (
            ! $user->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        ) {
            return false;
        }

        if (
            $user->center_id === null
            || $user->center_id
            !== $fee->center_id
        ) {
            return false;
        }

        $enrollment =
            $this->enrollmentForFee(
                $fee
            );

        if ($enrollment === null) {
            return false;
        }

        $branchId =
            $this->branchIdForEnrollment(
                $enrollment
            );

        if (
            $branchId === null
            || $branchId !== $fee->branch_id
        ) {
            return false;
        }

        return $this->canManageBranch(
            $user,
            $fee->center_id,
            $branchId
        );
    }

    private function canManageBranch(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            true,

            SystemRole::BranchManager =>
            $this->branchManagerHasAccess(
                $user,
                $centerId,
                $branchId
            ),

            SystemRole::FinanceEmployee =>
            $this->financeEmployeeHasAccess(
                $user,
                $centerId,
                $branchId
            ),

            default =>
            false,
        };
    }

    private function branchManagerHasAccess(
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

    private function financeEmployeeHasAccess(
        User $user,
        int $centerId,
        int $branchId
    ): bool {
        return FinanceEmployeeAssignment::query()
            ->withoutGlobalScopes()
            ->active()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'branch_id',
                $branchId
            )
            ->exists();
    }

    private function enrollmentForFee(
        EnrollmentFee $fee
    ): ?Enrollment {
        return Enrollment::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $fee->enrollment_id
            )
            ->where(
                'center_id',
                $fee->center_id
            )
            ->first();
    }

    private function branchIdForEnrollment(
        Enrollment $enrollment
    ): ?int {
        $courseClass =
            CourseClass::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $enrollment->class_id
            )
            ->where(
                'center_id',
                $enrollment->center_id
            )
            ->first();

        return $courseClass === null
            ? null
            : (int) $courseClass->branch_id;
    }

    private function isEnrollmentStudent(
        User $user,
        Enrollment $enrollment
    ): bool {
        return Student::query()
            ->withoutGlobalScopes()
            ->whereKey(
                $enrollment->student_id
            )
            ->where(
                'center_id',
                $enrollment->center_id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->exists();
    }
}